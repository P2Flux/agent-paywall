<?php
/**
 * The two calls to P2Flux.
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'ABSPATH' ) || exit;

/**
 * P2Flux API client. `wp_remote_post` only - WordPress.org rejects plugins that call curl directly.
 */
class P2Flux_AP_Client {

	/**
	 * How long settling one payment may take: a settlement is mined in ~2 seconds, and the API waits
	 * once more for a slow one. Under the 30 seconds shared hosts still allow a request.
	 */
	const REDEEM_TIMEOUT = 25;
	const TIMEOUT        = 10;

	/**
	 * API base for an environment.
	 *
	 * @param string $environment test|live.
	 * @return string
	 */
	public static function api_url( $environment ) {
		$url = 'live' === $environment ? 'https://api.p2flux.com' : 'https://api-test.p2flux.com';
		/**
		 * Filter the API base. For development against an API on localhost.
		 *
		 * @param string $url         Default.
		 * @param string $environment test|live.
		 */
		return untrailingslashit( apply_filters( 'p2flux_ap_api_url', $url, $environment ) );
	}

	/**
	 * What an agent must pay: the x402 `accepts` list. Cached for the time the API allows.
	 *
	 * @param string $wallet      Seller wallet.
	 * @param string $price       Price, e.g. "0.05".
	 * @param string $environment test|live.
	 * @param bool   $fresh       Skip the cache.
	 * @return array|WP_Error `accepts`, or p2flux_ap_rejected (400: wallet or price refused) / p2flux_ap_unavailable.
	 */
	public static function challenge( $wallet, $price, $environment, $fresh = false ) {
		$key = 'p2flux_ap_ch_' . md5( $environment . '|' . strtolower( $wallet ) . '|' . $price );
		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && $cached ) {
				return $cached;
			}
		}
		$answer = self::post(
			$environment,
			'/x402/paywall/challenge',
			array(
				'recipient' => $wallet,
				'price'     => $price,
			),
			self::TIMEOUT
		);
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		if ( empty( $answer['accepts'] ) || ! is_array( $answer['accepts'] ) ) {
			return new WP_Error( 'p2flux_ap_unavailable', 'unexpected answer' );
		}
		$ttl = isset( $answer['ttl'] ) ? min( 3600, max( 60, (int) $answer['ttl'] ) ) : 600;
		set_transient( $key, $answer['accepts'], $ttl );
		return $answer['accepts'];
	}

	/**
	 * Settle an agent's payment for the seller's own wallet and price.
	 *
	 * @param string $wallet      Seller wallet.
	 * @param string $price       Price.
	 * @param string $header      The agent's PAYMENT-SIGNATURE header, unread.
	 * @param string $url         URL the agent asked for.
	 * @param string $environment test|live.
	 * @return array|WP_Error `{ paid, reason?, message?, transaction?, payer?, amount?, network, payment_response? }`.
	 */
	public static function redeem( $wallet, $price, $header, $url, $environment ) {
		return self::post(
			$environment,
			'/x402/paywall/redeem',
			array(
				'recipient' => $wallet,
				'price'     => $price,
				'payment'   => $header,
				'resource'  => substr( $url, 0, 2048 ),
			),
			self::REDEEM_TIMEOUT
		);
	}

	/**
	 * Ask P2Flux to read this site's /.well-known/x402 again: it lists, updates or unlists the site
	 * by what that document says. Best effort - the listing is also re-read daily by P2Flux.
	 *
	 * @param string $site        This site's address.
	 * @param string $environment test|live.
	 * @return array|WP_Error
	 */
	public static function refresh_listing( $site, $environment ) {
		return self::post( $environment, '/x402/directory/refresh', array( 'site' => $site ), self::TIMEOUT );
	}

	/**
	 * One JSON POST.
	 *
	 * @param string $environment test|live.
	 * @param string $path        Path.
	 * @param array  $body        Body.
	 * @param int    $timeout     Seconds.
	 * @return array|WP_Error
	 */
	private static function post( $environment, $path, array $body, $timeout ) {
		$response = wp_remote_post(
			self::api_url( $environment ) . $path,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'P2Flux-Agent-Paywall/' . P2FLUX_AP_VERSION . '; ' . home_url( '/' ),
				),
				'body'        => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'p2flux_ap_unavailable', $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 400 === $status ) {
			return new WP_Error( 'p2flux_ap_rejected', is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : 'rejected' );
		}
		if ( 200 !== $status || ! is_array( $data ) ) {
			return new WP_Error( 'p2flux_ap_unavailable', 'HTTP ' . $status );
		}
		return $data;
	}
}
