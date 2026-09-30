<?php
/**
 * DEVELOPMENT ONLY - never shipped (dev/ is excluded from the package).
 *
 * A fake P2Flux API inside WordPress, so the gate can be tested end to end over HTTP with no network
 * and no chain. Active only while the option `p2flux_ap_fake` exists. It keeps the one property of the
 * real API the gate depends on: a payment is paid once.
 *
 * A fake payment header is base64 of {"id": "..."}; "bad-" ids are refused, "slow-" ids stall.
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$fake = get_option( 'p2flux_ap_fake' );
		if ( is_array( $fake ) && ! empty( $fake['cached'] ) && 0 === strpos( $url, home_url() ) ) {
			return array( 'headers' => array(), 'body' => '<html>the full page, from a cache</html>', 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array() );
		}
		// The keys of a signing agent (Web Bot Auth), as https://agent.test would publish them.
		if ( is_array( $fake ) && 'https://agent.test/.well-known/http-message-signatures-directory' === $url ) {
			return array( 'headers' => array(), 'body' => (string) get_option( 'p2flux_ap_fake_jwks', '{}' ), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array() );
		}
		// The live API too: a test must never reach it. The fake answers as the TEST network either way.
		if ( ! is_array( $fake ) || ( 0 !== strpos( $url, 'https://api-test.p2flux.com/x402/' ) && 0 !== strpos( $url, 'https://api.p2flux.com/x402/' ) ) ) {
			return $pre;
		}
		if ( str_ends_with( $url, '/directory/refresh' ) ) {
			$sent   = json_decode( $args['body'], true );
			$seen   = (array) get_option( 'p2flux_ap_fake_refreshes', array() );
			$seen[] = (string) ( $sent['site'] ?? '' );
			update_option( 'p2flux_ap_fake_refreshes', $seen, false );
			return array( 'headers' => array(), 'body' => '{"listed":true}', 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array() );
		}
		$calls   = (array) get_option( 'p2flux_ap_fake_calls', array() );
		$calls[] = basename( $url );
		update_option( 'p2flux_ap_fake_calls', $calls, false );
		if ( ! empty( $fake['down'] ) ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		}
		$body  = json_decode( $args['body'], true );
		$reply = static fn( $status, $data ) => array(
			'headers'  => array(),
			'body'     => wp_json_encode( $data ),
			'response' => array( 'code' => $status, 'message' => '' ),
			'cookies'  => array(),
		);
		if ( ! preg_match( '/^0x[0-9a-f]{40}$/i', $body['recipient'] ?? '' ) || '0x000000000000000000000000000000000000dead' === strtolower( $body['recipient'] ) ) {
			return $reply( 400, array( 'error' => 'INVALID_REQUEST' ) );
		}
		$units = (int) round( (float) $body['price'] * 1000000 );
		if ( str_ends_with( $url, '/challenge' ) ) {
			return $reply(
				200,
				array(
					'x402Version' => 2,
					'ttl'         => 3600,
					'accepts'     => array(
						array(
							'scheme'            => 'exact',
							'network'           => 'eip155:84532',
							'asset'             => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
							'amount'            => (string) $units,
							'payTo'             => '0x' . substr( md5( $body['recipient'] ), 0, 40 ),
							'maxTimeoutSeconds' => 300,
							'extra'             => array( 'name' => 'USDC', 'version' => '2', 'p2flux' => array( 'recipient' => $body['recipient'] ) ),
						),
						array(
							'scheme'  => 'batch-settlement',
							'network' => 'eip155:84532',
							'amount'  => (string) $units,
							'payTo'   => '0x' . substr( md5( 'batch' . $body['recipient'] ), 0, 40 ),
							'extra'   => array( 'minDeposit' => '1000000', 'withdrawDelay' => 86400 ),
						),
					),
				)
			);
		}
		$payment = json_decode( base64_decode( $body['payment'] ), true ); // phpcs:ignore
		$id      = is_array( $payment ) ? (string) ( $payment['id'] ?? '' ) : '';
		$refuse  = static fn( $reason ) => $reply( 200, array( 'paid' => false, 'reason' => $reason, 'network' => 'eip155:84532' ) );
		if ( '' === $id ) {
			return $refuse( 'invalid_payload' );
		}
		if ( 0 === strpos( $id, 'batchbad-' ) ) {
			$state = base64_encode( wp_json_encode( array( 'x402Version' => 2, 'error' => 'batch_settlement_stale_cumulative_amount', 'accepts' => array( array( 'scheme' => 'batch-settlement', 'extra' => array( 'channelState' => array( 'chargedCumulativeAmount' => '150000' ) ) ) ) ) ) ); // phpcs:ignore
			return $reply( 200, array( 'paid' => false, 'reason' => 'batch_settlement_stale_cumulative_amount', 'network' => 'eip155:84532', 'scheme' => 'batch-settlement', 'payment_required' => $state ) );
		}
		if ( 0 === strpos( $id, 'junkheader-' ) ) {
			return $reply( 200, array( 'paid' => true, 'transaction' => '0x' . hash( 'sha256', $id ), 'payer' => '0x9B710c4Cc6A63Fc0728748Af852e2183fb936262', 'amount' => (string) $units, 'network' => 'eip155:84532', 'payment_response' => "x\r\nSet-Cookie: pwned=1" ) );
		}
		if ( 0 === strpos( $id, 'refund-' ) ) {
			return $reply( 200, array( 'paid' => false, 'refunded' => true, 'reason' => 'refunded', 'network' => 'eip155:84532', 'scheme' => 'batch-settlement', 'payment_response' => base64_encode( '{"success":true,"amount":"900000"}' ) ) ); // phpcs:ignore
		}
		if ( 0 === strpos( $id, 'bad-' ) ) {
			return $refuse( 'invalid_exact_evm_insufficient_balance' );
		}
		if ( isset( $payment['price'] ) && (string) $payment['price'] !== (string) $body['price'] ) {
			return $refuse( 'invalid_payment_requirements' );
		}
		// Paid once, atomically - the property of the real API the gate relies on.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off')", 'p2flux_ap_fake_used_' . md5( $id ) ) ); // phpcs:ignore
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return $refuse( 'invalid_transaction_state' );
		}
		if ( 0 === strpos( $id, 'slow-' ) ) {
			usleep( 800000 );
		}
		$tx = '0x' . hash( 'sha256', $id );
		if ( 0 === strpos( $id, 'batch-' ) ) {
			return $reply( 200, array( 'paid' => true, 'scheme' => 'batch-settlement', 'receipt' => $tx, 'payer' => '0x9B710c4Cc6A63Fc0728748Af852e2183fb936262', 'amount' => (string) $units, 'network' => 'eip155:84532', 'payment_response' => base64_encode( '{"success":true}' ) ) ); // phpcs:ignore
		}
		return $reply(
			200,
			array(
				'paid'             => true,
				'transaction'      => $tx,
				'payer'            => '0x9B710c4Cc6A63Fc0728748Af852e2183fb936262',
				'amount'           => (string) $units,
				'network'          => 'eip155:84532',
				'payment_response' => base64_encode( wp_json_encode( array( 'success' => true, 'transaction' => $tx ) ) ), // phpcs:ignore
			)
		);
	},
	10,
	3
);

// A route the owner can make paid: stands in for a site's own data API.
add_action(
	'rest_api_init',
	static function () {
		if ( ! is_array( get_option( 'p2flux_ap_fake' ) ) ) {
			return;
		}
		register_rest_route(
			'paid/v1',
			'/data',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static fn() => array( 'data' => 'ROUTE-SECRET-42' ),
			)
		);
	}
);
