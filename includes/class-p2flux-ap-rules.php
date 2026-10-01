<?php
/**
 * What a request costs. Pure: no WordPress calls, so it is tested without WordPress.
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'ABSPATH' ) || exit;

/**
 * Price rules.
 *
 * Order, first match wins: the post's own price (0 = free) → a paid category → a paid post type.
 * Nothing matches: free. Prices are decimal strings in USDC ("0.05"), never floats.
 */
class P2Flux_AP_Rules {

	/** Smallest price the P2Flux API settles. */
	const MIN_PRICE = '0.01';
	/** Largest price this plugin accepts for one request. */
	const MAX_PRICE = '1000';

	/**
	 * A price as the API wants it, or null.
	 *
	 * Accepts "0.05", "1", " 2.5 ", "0,05" (comma from a European keyboard). Up to six decimals, the
	 * precision of USDC. Refuses anything below MIN_PRICE or above MAX_PRICE.
	 *
	 * @param mixed $value Input.
	 * @return string|null Normalised price, e.g. "0.05".
	 */
	public static function normalise_price( $value ) {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}
		$value = str_replace( ',', '.', trim( (string) $value ) );
		if ( ! preg_match( '/^(\d{1,4})(?:\.(\d{1,6}))?$/', $value, $m ) ) {
			return null;
		}
		$units = self::units( $value );
		if ( $units < self::units( self::MIN_PRICE ) || $units > self::units( self::MAX_PRICE ) ) {
			return null;
		}
		$whole    = ltrim( $m[1], '0' );
		$fraction = rtrim( $m[2] ?? '', '0' );
		return ( '' === $whole ? '0' : $whole ) . ( '' === $fraction ? '' : '.' . $fraction );
	}

	/**
	 * A decimal price in USDC base units (6 decimals). Integer arithmetic only.
	 *
	 * @param string $price Validated price.
	 * @return int
	 */
	public static function units( $price ) {
		$parts    = explode( '.', $price, 2 );
		$fraction = str_pad( substr( $parts[1] ?? '', 0, 6 ), 6, '0' );
		return (int) $parts[0] * 1000000 + (int) $fraction;
	}

	/**
	 * Base units back to a price for display: 50000 → "0.05".
	 *
	 * @param int $units Amount.
	 * @return string
	 */
	public static function format_units( $units ) {
		$units    = max( 0, (int) $units );
		$fraction = rtrim( str_pad( (string) ( $units % 1000000 ), 6, '0', STR_PAD_LEFT ), '0' );
		$whole    = (string) intdiv( $units, 1000000 );
		return '' === $fraction ? $whole . '.00' : $whole . '.' . str_pad( $fraction, 2, '0' );
	}

	/**
	 * The price of one post, or null when it is free.
	 *
	 * @param array  $settings   Plugin settings (P2Flux_AP_Settings::get()).
	 * @param string $own_price  The post's meta value: '' = follow the rules, '0' = free, else a price.
	 * @param string $post_type  The post's type.
	 * @param int[]  $categories The post's category ids.
	 * @return string|null
	 */
	public static function price_for_post( array $settings, $own_price, $post_type, array $categories ) {
		$own_price = is_string( $own_price ) ? trim( $own_price ) : '';
		if ( '0' === $own_price ) {
			return null;
		}
		if ( '' !== $own_price ) {
			$price = self::normalise_price( $own_price );
			if ( null !== $price ) {
				return $price;
			}
		}
		$default = self::normalise_price( $settings['default_price'] ?? '' );
		if ( null === $default ) {
			return null;
		}
		$paid_categories = array_map( 'intval', (array) ( $settings['paid_categories'] ?? array() ) );
		if ( array_intersect( array_map( 'intval', $categories ), $paid_categories ) ) {
			return $default;
		}
		if ( in_array( $post_type, (array) ( $settings['paid_post_types'] ?? array() ), true ) ) {
			return $default;
		}
		return null;
	}

	/**
	 * The price of a REST route the owner listed as paid, or null.
	 *
	 * A listed prefix is paid for every caller that is not logged in - it is an API, callers are
	 * programs. `/wp/v2/` can never be listed: the editor and themes live on it.
	 *
	 * @param array  $settings Plugin settings.
	 * @param string $route    REST route, e.g. "/myapi/v1/data".
	 * @return string|null
	 */
	public static function price_for_route( array $settings, $route ) {
		$default = self::normalise_price( $settings['default_price'] ?? '' );
		if ( null === $default || ! is_string( $route ) ) {
			return null;
		}
		foreach ( (array) ( $settings['paid_routes'] ?? array() ) as $prefix ) {
			if ( self::route_allowed( $prefix ) && 0 === strpos( $route, $prefix ) ) {
				return $default;
			}
		}
		return null;
	}

	/**
	 * Access tokens an agent sent in the P2Flux-Access-Token header, for a membership plugin to check.
	 *
	 * A token is 32 random bytes in base64url (43 characters). The header may carry several, comma
	 * separated: an agent does not know before asking which membership a page belongs to. Anything
	 * else is dropped; at most 10 are kept.
	 *
	 * @param mixed $header Raw header value.
	 * @return string[]
	 */
	public static function access_tokens( $header ) {
		if ( ! is_string( $header ) || strlen( $header ) > 1024 ) {
			return array();
		}
		$tokens = array();
		foreach ( explode( ',', $header ) as $token ) {
			$token = trim( $token );
			if ( 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) && ! in_array( $token, $tokens, true ) ) {
				$tokens[] = $token;
			}
		}
		return array_slice( $tokens, 0, 10 );
	}

	/**
	 * Whether a REST prefix may be made paid.
	 *
	 * @param string $prefix e.g. "/myapi/v1/".
	 * @return bool
	 */
	public static function route_allowed( $prefix ) {
		return is_string( $prefix )
			&& 1 === preg_match( '#^/[a-z0-9._-]+(/[a-z0-9._-]+)*/?$#i', $prefix )
			&& 0 === preg_match( '#/\.\.?(/|$)#', $prefix )
			&& 0 !== strpos( $prefix, '/wp/' )
			&& '/wp' !== rtrim( $prefix, '/' )
			&& 0 !== strpos( $prefix, '/oembed/' )
			&& 0 !== strpos( $prefix, '/wp-site-health/' );
	}
}
