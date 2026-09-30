<?php
/**
 * Remove everything the plugin stored. Payments already made stay on the chain.
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'p2flux_ap_settings' );
delete_option( 'p2flux_ap_db_version' );
delete_post_meta_by_key( '_p2flux_ap_price' );
wp_clear_scheduled_hook( 'p2flux_ap_prune' );
wp_clear_scheduled_hook( 'p2flux_ap_refresh_listing' );
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'p2flux_ap_payments' ) );
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_p2flux_ap_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_p2flux_ap_' ) . '%'
	)
);
// phpcs:enable
