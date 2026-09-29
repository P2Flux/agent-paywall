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

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve' ), 0 );
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
			'url'         => home_url( '/' ),
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
