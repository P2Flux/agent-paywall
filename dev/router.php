<?php
/**
 * `php -S` router: real files as files, everything else through WordPress (pretty permalinks).
 *
 * @package P2Flux_Agent_Paywall
 */

$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ); // phpcs:ignore
// What the plugin's .htaccess rule does on Apache: the rule the plugin WROTE is read and applied here.
$p2flux_ua    = $_SERVER['HTTP_USER_AGENT'] ?? ''; // phpcs:ignore
$p2flux_rules = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/uploads/.htaccess';
if ( 0 === strpos( $path, '/wp-content/uploads/' ) && is_file( $p2flux_rules ) && preg_match_all( '#^RewriteRule \^(.+)\$ \S+\?p2flux_ap_file=\$1#m', (string) file_get_contents( $p2flux_rules ), $p2flux_m ) ) { // phpcs:ignore
	preg_match( '#^RewriteCond %\{HTTP_USER_AGENT\} \((.+)\) \[NC,OR\]$#m', (string) file_get_contents( $p2flux_rules ), $p2flux_agents ); // phpcs:ignore
	$p2flux_agent = '' === $p2flux_ua || preg_match( '#' . str_replace( '#', '\\#', $p2flux_agents[1] ?? 'x^' ) . '#i', $p2flux_ua ) || isset( $_SERVER['HTTP_PAYMENT_SIGNATURE'] ) || isset( $_SERVER['HTTP_SIGNATURE_AGENT'] );
	foreach ( $p2flux_m[1] as $p2flux_rule ) {
		if ( $p2flux_agent && preg_match( '#^' . $p2flux_rule . '$#', substr( $path, strlen( '/wp-content/uploads/' ) ), $p2flux_hit ) ) {
			$_GET['p2flux_ap_file'] = $p2flux_hit[1];
			$path                   = '/';
			break;
		}
	}
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
