<?php
/**
 * How an agent learns that this site sells access, and for how much.
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'ABSPATH' ) || exit;

/**
 * `/.well-known/x402`: what is paid, the price, and the x402 requirement. Nothing personal.
 */
class P2Flux_AP_Discovery {

	const REFRESH_HOOK = 'p2flux_ap_refresh_listing';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve' ), 0 );
		add_action( self::REFRESH_HOOK, array( __CLASS__, 'announce' ) );
		if ( ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::REFRESH_HOOK );
		}
	}

	/**
	 * Tell P2Flux to read this site's document again - after the settings change, and weekly.
	 * P2Flux decides from the document alone: listed when it says so, removed when it says not.
	 *
	 * @return void
	 */
	public static function announce() {
		$s = P2Flux_AP_Settings::get();
		if ( '' === $s['wallet'] ) {
			return;
		}
		P2Flux_AP_Client::refresh_listing( home_url(), $s['environment'] );
	}

	/**
	 * Up to ten latest paid posts: title, address, price. Nothing an agent does not already get in a list.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	private static function samples( array $s ) {
		$out   = array();
		$types = $s['paid_post_types'] ? $s['paid_post_types'] : array( 'post' );
		$posts = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'numberposts'      => 30,
				'has_password'     => false,
				'suppress_filters' => false,
			)
		);
		foreach ( $posts as $post ) {
			$price = P2Flux_AP_Gate::price_of( $post );
			if ( null === $price ) {
				continue;
			}
			$out[] = array(
				'title' => wp_strip_all_tags( get_the_title( $post ) ),
				'url'   => get_permalink( $post ),
				'price' => $price,
			);
			if ( count( $out ) >= 10 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Serve the document when asked for.
	 *
	 * @return void
	 */
	public static function maybe_serve() {
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path   = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home   = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		$wanted = $home . '/.well-known/x402';
		if ( untrailingslashit( $path ) !== $wanted ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( self::document(), JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * The document.
	 *
	 * @return array
	 */
	public static function document() {
		$s       = P2Flux_AP_Settings::get();
		$accepts = P2Flux_AP_Client::challenge( $s['wallet'], $s['default_price'], $s['environment'] );
		$slugs   = array();
		foreach ( $s['paid_categories'] as $id ) {
			$term = get_term( (int) $id, 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				$slugs[] = $term->slug;
			}
		}
		return array(
			'x402Version' => 2,
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url( '/' ),
			'directory'   => 'yes' === $s['directory'],
			'samples'     => 'yes' === $s['directory'] ? self::samples( $s ) : array(),
			'price'       => $s['default_price'],
			'currency'    => 'USDC',
			'paid'        => array(
				'post_types' => $s['paid_post_types'],
				'categories' => $slugs,
				'routes'     => array_map( static fn( $r ) => rest_url( ltrim( $r, '/' ) ), $s['paid_routes'] ),
			),
			'note'        => 'Single posts in these types and categories answer AI agents with HTTP 402 and an x402 requirement. A post may carry its own price.',
			'accepts'     => is_wp_error( $accepts ) ? array() : $accepts,
		);
	}
}
