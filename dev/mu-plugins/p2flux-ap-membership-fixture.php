<?php
/**
 * Dev only - never shipped (dev/ is not in the release ZIP).
 *
 * The smallest membership plugin built on the Agent Paywall hooks, so the integration test proves
 * what a real one (Tipster Script) relies on: a post with the meta `_ap_fixture_member` costs 0.30
 * and the payment buys an hour of access, proven later by the P2Flux-Access-Token header. Active only
 * while the option `p2flux_ap_fake` exists, like the fake API.
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_array( get_option( 'p2flux_ap_fake' ) ) ) {
	return;
}

/**
 * Whether the request carries a token this fixture issued and that has not expired.
 *
 * @return bool
 */
function p2flux_ap_fixture_entitled() {
	if ( ! class_exists( 'P2Flux_AP_Gate' ) ) {
		return false;
	}
	$issued = (array) get_option( 'p2flux_ap_fixture_tokens', array() );
	foreach ( P2Flux_AP_Gate::access_tokens() as $token ) {
		$hash = hash( 'sha256', $token );
		if ( isset( $issued[ $hash ] ) && (int) $issued[ $hash ] > time() ) {
			return true;
		}
	}
	return false;
}

add_filter(
	'p2flux_ap_price',
	static function ( $price, $post ) {
		if ( ! $post instanceof WP_Post || '1' !== get_post_meta( $post->ID, '_ap_fixture_member', true ) ) {
			return $price;
		}
		return p2flux_ap_fixture_entitled() ? null : '0.30';
	},
	10,
	2
);

add_filter(
	'p2flux_ap_requirement',
	static function ( $required ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post && '1' === get_post_meta( $post->ID, '_ap_fixture_member', true ) ) {
			$required['resource']['description'] = 'MEMBERSHIP-OFFER: one hour of every member page';
			$required['accepts']                 = array(); // A plugin may not change what is settled.
		}
		return $required;
	}
);

add_action(
	'p2flux_ap_paid',
	static function ( $payment ) {
		if ( ! $payment['post_id'] || '1' !== get_post_meta( $payment['post_id'], '_ap_fixture_member', true ) ) {
			return;
		}
		$token          = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		$issued         = (array) get_option( 'p2flux_ap_fixture_tokens', array() );
		$issued[ hash( 'sha256', $token ) ] = time() + 3600;
		update_option( 'p2flux_ap_fixture_tokens', $issued, false );
		update_option( 'p2flux_ap_fixture_last_payment', $payment, false );
		header( 'P2Flux-Access-Token: ' . $token );
	}
);
