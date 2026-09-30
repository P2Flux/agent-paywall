<?php
/**
 * Web Bot Auth: an agent that signs its requests proves who it is.
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTTP Message Signatures (RFC 9421) with tag "web-bot-auth", Ed25519 only.
 *
 * A signed request is an agent whatever its user agent says, and a VERIFIED signature names it in the
 * payment log. Nothing here lets anyone read for free: the signature only adds knowledge.
 *
 * parse(), base() and check() are pure: tested without WordPress.
 */
class P2Flux_AP_Botauth {

	const TAG = 'web-bot-auth';
	/** A signature may live this long at most. */
	const MAX_LIFE = 86400;
	/** Clock difference tolerated. */
	const SKEW = 60;

	/**
	 * The web-bot-auth signature of a request, or null.
	 *
	 * @param string $input     Signature-Input header.
	 * @param string $signature Signature header.
	 * @return array{label: string, components: string[], params: string, keyid: string, created: int, expires: int, signature: string}|null
	 */
	public static function parse( $input, $signature ) {
		if ( ! is_string( $input ) || ! is_string( $signature ) || strlen( $input ) > 2048 || strlen( $signature ) > 2048 ) {
			return null;
		}
		if ( ! preg_match_all( '/(?:^|,)\s*([a-z][a-z0-9_.*-]*)=(\(([^()]*)\)((?:;[a-z]+=(?:"[^"]*"|[0-9]+))*))/', $input, $found, PREG_SET_ORDER ) ) {
			return null;
		}
		foreach ( $found as $m ) {
			$params = array();
			preg_match_all( '/;([a-z]+)=(?:"([^"]*)"|([0-9]+))/', $m[4], $pairs, PREG_SET_ORDER );
			foreach ( $pairs as $p ) {
				$params[ $p[1] ] = '' !== ( $p[3] ?? '' ) ? $p[3] : $p[2];
			}
			if ( self::TAG !== ( $params['tag'] ?? '' ) || ! isset( $params['keyid'], $params['created'], $params['expires'] ) ) {
				continue;
			}
			if ( isset( $params['alg'] ) && 'ed25519' !== $params['alg'] ) {
				continue;
			}
			if ( ! preg_match( '/(?:^|,)\s*' . preg_quote( $m[1], '/' ) . '=:([A-Za-z0-9+\/]+={0,2}):/', $signature, $s ) ) {
				continue;
			}
			$raw = base64_decode( $s[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the RFC 9421 signature format.
			if ( false === $raw || 64 !== strlen( $raw ) ) {
				continue;
			}
			preg_match_all( '/"[^"]+"(?:;[a-z]+="[^"]*")*/', $m[3], $components );
			return array(
				'label'      => $m[1],
				'components' => $components[0],
				'params'     => $m[2],
				'keyid'      => $params['keyid'],
				'created'    => (int) $params['created'],
				'expires'    => (int) $params['expires'],
				'signature'  => $raw,
			);
		}
		return null;
	}

	/**
	 * The text the agent signed (RFC 9421 signature base), or null when it covers something unknown.
	 *
	 * @param array $sig     From parse().
	 * @param array $request authority, target_uri, method, path, signature_agent (the raw header).
	 * @return string|null
	 */
	public static function base( array $sig, array $request ) {
		$lines     = array();
		$authority = false;
		foreach ( $sig['components'] as $component ) {
			switch ( true ) {
				case '"@authority"' === $component:
					$value     = strtolower( (string) $request['authority'] );
					$authority = true;
					break;
				case '"@target-uri"' === $component:
					$value     = (string) $request['target_uri'];
					$authority = true;
					break;
				case '"@method"' === $component:
					$value = strtoupper( (string) $request['method'] );
					break;
				case '"@path"' === $component:
					$value = (string) $request['path'];
					break;
				case '"signature-agent"' === $component:
					$value = trim( (string) $request['signature_agent'] );
					break;
				case 1 === preg_match( '/^"signature-agent";key="([a-z][a-z0-9_.*-]*)"$/', $component, $k ):
					if ( ! preg_match( '/(?:^|,)\s*' . preg_quote( $k[1], '/' ) . '=("[^"]*"(?:;[^,]*)?)/', (string) $request['signature_agent'], $member ) ) {
						return null;
					}
					$value = trim( $member[1] );
					break;
				default:
					return null;
			}
			$lines[] = $component . ': ' . $value;
		}
		// A signature that does not name this site could be replayed from another one.
		if ( ! $authority ) {
			return null;
		}
		$lines[] = '"@signature-params": ' . $sig['params'];
		return implode( "\n", $lines );
	}

	/**
	 * The name of an Ed25519 key: its JWK thumbprint (RFC 7638).
	 *
	 * @param string $x The JWK's "x".
	 * @return string
	 */
	public static function thumbprint( $x ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', '{"crv":"Ed25519","kty":"OKP","x":"' . $x . '"}', true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7638.
	}

	/**
	 * Is the signature valid now, by one of these keys?
	 *
	 * @param array  $sig  From parse().
	 * @param string $base From base().
	 * @param array  $keys The agent's published keys (JWKs).
	 * @param int    $now  Unix time.
	 * @return bool
	 */
	public static function check( array $sig, $base, array $keys, $now ) {
		if ( $sig['created'] > $now + self::SKEW || $sig['expires'] < $now - self::SKEW || $sig['expires'] - $sig['created'] > self::MAX_LIFE ) {
			return false;
		}
		foreach ( $keys as $key ) {
			if ( ! is_array( $key ) || 'OKP' !== ( $key['kty'] ?? '' ) || 'Ed25519' !== ( $key['crv'] ?? '' ) || ! is_string( $key['x'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $key['x'] ) ) {
				continue;
			}
			if ( ! hash_equals( self::thumbprint( $key['x'] ), $sig['keyid'] ) ) {
				continue;
			}
			$public = base64_decode( strtr( $key['x'], '-_', '+/' ) . '=', true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a JWK.
			return false !== $public && 32 === strlen( $public ) && sodium_crypto_sign_verify_detached( $sig['signature'], $base, $public );
		}
		return false;
	}

	/**
	 * The host an agent says it is, from Signature-Agent: `"https://chatgpt.com"` or `sig1="https://…"`.
	 *
	 * @param string $header Signature-Agent.
	 * @return string Host, '' when there is none.
	 */
	public static function agent_host( $header ) {
		return is_string( $header ) && preg_match( '#"https://([a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?)/?"#i', $header, $m ) ? strtolower( $m[1] ) : '';
	}

	/**
	 * One request header.
	 *
	 * @param string $name As in $_SERVER, e.g. HTTP_SIGNATURE.
	 * @return string
	 */
	private static function header( $name ) {
		// Validated by parse(): a signature is compared byte for byte, never sanitised into something else.
		return isset( $_SERVER[ $name ] ) && is_string( $_SERVER[ $name ] ) ? trim( wp_unslash( $_SERVER[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Does this request carry a web-bot-auth signature? Not yet verified: enough to know it is a program.
	 *
	 * @return bool
	 */
	public static function claimed() {
		return '' !== self::header( 'HTTP_SIGNATURE_AGENT' ) && null !== self::parse( self::header( 'HTTP_SIGNATURE_INPUT' ), self::header( 'HTTP_SIGNATURE' ) );
	}

	/**
	 * The agent that signed this request, verified against the keys it publishes. '' when unsigned or not proven.
	 *
	 * @return string Host, e.g. "chatgpt.com".
	 */
	public static function verified_agent() {
		$agent = self::header( 'HTTP_SIGNATURE_AGENT' );
		$host  = self::agent_host( $agent );
		$sig   = self::parse( self::header( 'HTTP_SIGNATURE_INPUT' ), self::header( 'HTTP_SIGNATURE' ) );
		if ( '' === $host || null === $sig ) {
			return '';
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$base = self::base(
			$sig,
			array(
				'authority'       => (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' ),
				'target_uri'      => home_url( $uri ),
				'method'          => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET',
				'path'            => (string) wp_parse_url( $uri, PHP_URL_PATH ),
				'signature_agent' => $agent,
			)
		);
		if ( null === $base ) {
			return '';
		}
		return self::check( $sig, $base, self::keys( $host ), time() ) ? $host : '';
	}

	/**
	 * The keys an agent publishes, remembered for an hour (a failure for five minutes).
	 *
	 * @param string $host Agent host.
	 * @return array
	 */
	private static function keys( $host ) {
		$cache = 'p2flux_ap_keys_' . md5( $host );
		$keys  = get_transient( $cache );
		if ( is_array( $keys ) ) {
			return $keys;
		}
		// wp_safe_remote_get refuses private and loopback addresses.
		$response = wp_safe_remote_get(
			'https://' . $host . '/.well-known/http-message-signatures-directory',
			array(
				'timeout'             => 3,
				'redirection'         => 0,
				'limit_response_size' => 65536,
			)
		);
		$document = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		$keys     = is_array( $document ) && is_array( $document['keys'] ?? null ) ? array_slice( $document['keys'], 0, 20 ) : array();
		set_transient( $cache, $keys, $keys ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		return $keys;
	}
}
