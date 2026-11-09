<?php
/**
 * Page caches: they must never hand a paid page to an AI agent.
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'ABSPATH' ) || exit;

/**
 * A full-page cache answers from a stored copy BEFORE WordPress runs. If a person's visit stored a paid
 * page, an agent asking for it later gets that copy, free, and the gate never sees it. Headers on the
 * answers to agents cannot help there: the cache must be told up front which requests skip it.
 *
 * - LiteSpeed (server and plugin): a rule at the top of the site's .htaccess makes requests from agents,
 *   and any request with a payment header, skip the cache (E=Cache-Control:no-cache). Written here when
 *   the file is writable, else shown on the settings page.
 * - WP Rocket: its "rejected user agents" filter.
 * - Every answer to an agent is marked not cacheable (DONOTCACHEPAGE, Cache-Control, the LiteSpeed header
 *   and hook), so it is never stored and served to someone else.
 * - Other caches (W3 Total Cache, WP Super Cache, Cloudflare, host caches) have no hook for this: the
 *   setup test names the cache and says what to change.
 */
class P2Flux_AP_Cache {

	const MARKER = 'P2Flux Agent Paywall';
	/** What was last given to the caches; a change means they must be purged. */
	const OPTION = 'p2flux_ap_cache_rules';

	/**
	 * Set while the rules are being removed: WP Rocket, rebuilding its rules now, must not get ours.
	 *
	 * @var bool
	 */
	private static $off = false;

	/**
	 * Hooks. Runs when the plugin file loads - also on activation, when WP Rocket rebuilds its rules.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'rocket_cache_reject_ua', array( __CLASS__, 'rocket_reject_ua' ) );
	}

	/**
	 * WP Rocket: never serve its cached pages to agents. Its entries are regular expressions.
	 *
	 * @param mixed $agents Rejected user agents.
	 * @return array
	 */
	public static function rocket_reject_ua( $agents ) {
		return self::$off ? (array) $agents : array_merge( (array) $agents, explode( '|', self::pattern() ) );
	}

	/**
	 * The agent user agents, as the gate sees them.
	 *
	 * @return string
	 */
	public static function pattern() {
		return P2Flux_AP_Detector::pattern( (array) apply_filters( 'p2flux_ap_agent_signatures', P2Flux_AP_Detector::SIGNATURES ) );
	}

	/**
	 * The .htaccess rule: requests the gate may charge skip the LiteSpeed cache. Pure.
	 *
	 * @param string $pattern Agent user agents, from P2Flux_AP_Detector::pattern().
	 * @return string[]
	 */
	public static function rules( $pattern ) {
		$lines = array( '<IfModule LiteSpeed>', 'RewriteEngine On' );
		if ( '' !== $pattern ) {
			$lines[] = 'RewriteCond %{HTTP_USER_AGENT} (' . $pattern . ') [NC,OR]';
		}
		// No user agent, or Node's own "node": a program (as P2Flux_AP_Detector::is_agent()).
		$lines[] = 'RewriteCond %{HTTP_USER_AGENT} ^(node)?$ [NC,OR]';
		$lines[] = 'RewriteCond %{HTTP:Payment-Signature} !^$ [OR]';
		$lines[] = 'RewriteCond %{HTTP:X-Payment} !^$ [OR]';
		$lines[] = 'RewriteCond %{HTTP:Signature-Agent} !^$ [OR]';
		$lines[] = 'RewriteCond %{HTTP:P2Flux-Access-Token} !^$';
		$lines[] = 'RewriteRule .* - [E=Cache-Control:no-cache]';
		$lines[] = '</IfModule>';
		return $lines;
	}

	/**
	 * The .htaccess content with our block put at the top (rules below WordPress' own never run) or
	 * taken out when $lines is empty. Pure; the same input gives the same output however often.
	 *
	 * @param string   $content The file now.
	 * @param string[] $lines   Our rules; empty removes the block.
	 * @return string
	 */
	public static function with_block( $content, array $lines ) {
		$begin   = '# BEGIN ' . self::MARKER;
		$end     = '# END ' . self::MARKER;
		$content = (string) preg_replace( '/^' . preg_quote( $begin, '/' ) . '\r?$.*?^' . preg_quote( $end, '/' ) . '\r?$\R?/ms', '', (string) $content );
		if ( ! $lines ) {
			return $content;
		}
		return implode( "\n", array_merge( array( $begin ), $lines, array( $end ) ) ) . "\n" . $content;
	}

	/**
	 * Path of the site's .htaccess.
	 *
	 * @return string
	 */
	public static function htaccess() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return get_home_path() . '.htaccess';
	}

	/**
	 * Is our rule in the site's .htaccess as it should be?
	 *
	 * @return bool
	 */
	public static function in_place() {
		$file = self::htaccess();
		$now  = is_file( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		return $now === self::with_block( $now, self::rules( self::pattern() ) );
	}

	/**
	 * Write the rule (activation, settings saved) or remove it (deactivation, uninstall, no wallet).
	 * Caches are purged when what they were given changed.
	 *
	 * @param bool $remove Remove it.
	 * @return void
	 */
	public static function sync( $remove = false ) {
		self::$off = $remove;
		$lines     = $remove ? array() : self::rules( self::pattern() );
		$file      = self::htaccess();
		$exists    = is_file( $file );
		// A new file only on Apache or LiteSpeed (WordPress' $is_apache is true for both); the block is inert elsewhere anyway.
		if ( $exists ? wp_is_writable( $file ) : ( $lines && ! empty( $GLOBALS['is_apache'] ) && wp_is_writable( dirname( $file ) ) ) ) {
			$now  = $exists ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
			$next = self::with_block( $now, $lines );
			if ( $next !== $now ) {
				file_put_contents( $file, $next, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- like core's insert_with_markers(), which cannot put a block first.
			}
		}
		$given = $remove ? '' : md5( implode( "\n", $lines ) );
		if ( (string) get_option( self::OPTION, '' ) !== $given ) {
			if ( $remove ) {
				delete_option( self::OPTION );
			} else {
				update_option( self::OPTION, $given, false );
			}
			self::purge();
		}
	}

	/**
	 * Empty the page cache of whichever cache plugin is active, so a stored paid page is gone.
	 *
	 * @return void
	 */
	public static function purge() {
		do_action( 'litespeed_purge_all', 'P2Flux Agent Paywall: AI agents skip the cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own action.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			// Rebuild WP Rocket's rules with the rejected user agents, then empty it.
			if ( function_exists( 'flush_rocket_htaccess' ) ) {
				flush_rocket_htaccess();
			}
			if ( function_exists( 'rocket_generate_config_file' ) ) {
				rocket_generate_config_file();
			}
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
	}

	/**
	 * Mark this answer to an agent as never to be stored by any cache.
	 *
	 * @return void
	 */
	public static function no_store() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// The constant page-cache plugins (WP Super Cache, W3 Total Cache, LiteSpeed…) read: not ours to prefix.
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}
		do_action( 'litespeed_control_set_nocache', 'P2Flux Agent Paywall: answer to an AI agent' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own action.
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			header( 'Vary: User-Agent, PAYMENT-SIGNATURE, P2Flux-Access-Token', false );
		}
	}

	/**
	 * What one setup-test request shows. Pure.
	 *
	 * @param int|string $code    HTTP status, '' when there was no answer.
	 * @param array      $headers Response headers, names in lower case.
	 * @param string     $body    Response body (only searched for a cache plugin's signature).
	 * @return array{state: string, cache: string} state: ok (402 with a valid PAYMENT-REQUIRED), free, blocked, unknown.
	 */
	public static function classify( $code, array $headers, $body = '' ) {
		$code  = (int) $code;
		$state = 'unknown';
		if ( 402 === $code ) {
			$required = json_decode( (string) base64_decode( (string) ( $headers['payment-required'] ?? '' ), true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the x402 header format.
			$state    = ( is_array( $required ) && isset( $required['x402Version'] ) && ! empty( $required['accepts'] ) && is_array( $required['accepts'] ) ) ? 'ok' : 'unknown';
		} elseif ( $code >= 200 && $code < 300 ) {
			$state = 'free';
		} elseif ( 403 === $code || 429 === $code ) {
			$state = 'blocked';
		}
		return array(
			'state' => $state,
			'cache' => self::detect( $headers, (string) $body ),
		);
	}

	/**
	 * Which cache answered, from the response. Pure.
	 *
	 * @param array  $headers Response headers, names in lower case.
	 * @param string $body    Response body.
	 * @return string litespeed, cloudflare, hostinger, siteground, varnish, wp-rocket, w3tc, wp-super-cache, proxy, or ''.
	 */
	public static function detect( array $headers, $body = '' ) {
		$header = static fn( $name ) => strtolower( is_array( $headers[ $name ] ?? '' ) ? implode( ',', $headers[ $name ] ) : (string) ( $headers[ $name ] ?? '' ) );
		foreach ( array(
			'litespeed'  => 'x-litespeed-cache',
			'cloudflare' => 'cf-cache-status',
			'hostinger'  => 'x-hcdn-cache-status',
			'siteground' => 'x-proxy-cache',
			'varnish'    => 'x-varnish',
		) as $cache => $name ) {
			if ( '' !== $header( $name ) && ( 'cloudflare' !== $cache || in_array( $header( $name ), array( 'hit', 'stale', 'revalidated', 'updating' ), true ) ) ) {
				return $cache;
			}
		}
		foreach ( array(
			'wp-rocket'      => 'This website is like a Rocket',
			'w3tc'           => 'W3 Total Cache',
			'wp-super-cache' => 'WP-Super-Cache',
		) as $cache => $mark ) {
			if ( false !== strpos( (string) $body, $mark ) ) {
				return $cache;
			}
		}
		return false !== strpos( $header( 'x-cache' ), 'hit' ) ? 'proxy' : '';
	}
}
