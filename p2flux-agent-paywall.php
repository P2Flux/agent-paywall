<?php
/**
 * Plugin Name:       P2Flux Agent Paywall
 * Plugin URI:        https://p2flux.com
 * Description:       Get paid in USDC when AI agents read your site. Humans see your site as always; AI agents pay per page with x402, straight to your wallet.
 * Version:           0.4.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            P2Flux
 * Author URI:        https://p2flux.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       p2flux-agent-paywall
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'ABSPATH' ) || exit;

define( 'P2FLUX_AP_VERSION', '0.4.0' );
define( 'P2FLUX_AP_FILE', __FILE__ );
define( 'P2FLUX_AP_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-p2flux-ap-rules.php';
require_once __DIR__ . '/includes/class-p2flux-ap-detector.php';
require_once __DIR__ . '/includes/class-p2flux-ap-botauth.php';
require_once __DIR__ . '/includes/class-p2flux-ap-settings.php';
require_once __DIR__ . '/includes/class-p2flux-ap-client.php';
require_once __DIR__ . '/includes/class-p2flux-ap-log.php';
require_once __DIR__ . '/includes/class-p2flux-ap-gate.php';
require_once __DIR__ . '/includes/class-p2flux-ap-metabox.php';
require_once __DIR__ . '/includes/class-p2flux-ap-discovery.php';
require_once __DIR__ . '/includes/class-p2flux-ap-files.php';
require_once __DIR__ . '/includes/class-p2flux-ap-abilities.php';

register_activation_hook( __FILE__, array( 'P2Flux_AP_Log', 'install' ) );
register_deactivation_hook( __FILE__, static fn() => P2Flux_AP_Files::sync( true ) );

add_action( 'plugins_loaded', 'p2flux_ap_boot' );

/**
 * Wire the hooks. Nothing runs before a wallet is saved: an unconfigured plugin changes nothing.
 *
 * @return void
 */
function p2flux_ap_boot() {
	P2Flux_AP_Settings::register();
	P2Flux_AP_Metabox::register();
	P2Flux_AP_Log::maybe_upgrade();
	if ( '' === P2Flux_AP_Settings::get()['wallet'] ) {
		return;
	}
	P2Flux_AP_Gate::register();
	P2Flux_AP_Discovery::register();
	P2Flux_AP_Files::register();
	P2Flux_AP_Abilities::register();
}
