<?php
/**
 * `php -S` router: real files as files, everything else through WordPress (pretty permalinks).
 *
 * @package P2Flux_Agent_Paywall
 */

$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ); // phpcs:ignore
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
