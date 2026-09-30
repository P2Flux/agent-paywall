<?php
/**
 * `php -S` router: real files as files, everything else through WordPress (pretty permalinks).
 *
 * @package P2Flux_Agent_Paywall
 */

$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ); // phpcs:ignore
// What the plugin's .htaccess rule does on Apache: agents' requests for uploads go through WordPress.
$p2flux_ua = $_SERVER['HTTP_USER_AGENT'] ?? ''; // phpcs:ignore
if ( 0 === strpos( $path, '/wp-content/uploads/' ) && ( '' === $p2flux_ua || preg_match( '#GPTBot|ClaudeBot|curl/|node|P2Flux-MCP|x402#i', $p2flux_ua ) || isset( $_SERVER['HTTP_PAYMENT_SIGNATURE'] ) || isset( $_SERVER['HTTP_SIGNATURE_AGENT'] ) ) ) {
	$_GET['p2flux_ap_file'] = substr( $path, strlen( '/wp-content/uploads/' ) );
	$path                   = '/';
}
if ( '/' !== $path && is_file( $_SERVER['DOCUMENT_ROOT'] . $path ) && ! str_ends_with( $path, '.php' ) ) {
	return false;
}
if ( str_ends_with( $path, '.php' ) && is_file( $_SERVER['DOCUMENT_ROOT'] . $path ) ) {
	chdir( dirname( $_SERVER['DOCUMENT_ROOT'] . $path ) );
	require $_SERVER['DOCUMENT_ROOT'] . $path;
	return true;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
