<?php
/**
 * Where a request is asked to pay.
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'ABSPATH' ) || exit;

/**
 * The gate.
 *
 * Humans and logged-in users pass untouched. An agent that opens a paid post or calls a paid route
 * gets HTTP 402 with the x402 requirement; one that sends a payment has it settled by P2Flux BEFORE
 * the content is built. One payment serves one response, once: a payment presented again - by the
 * same agent or by anyone it was shared with - is refused, as x402 requires. In lists, feeds and REST
 * collections an agent sees the price instead of the text of paid posts.
 */
class P2Flux_AP_Gate {

	/** A real x402 payment header is ~1.1 KB. */
	const MAX_HEADER = 8192;
	/** How long this site remembers a used payment, to refuse it without asking P2Flux. */
	const USED_TTL = 600;

	/**
	 * Post paid for in this request: its content is served in full.
	 *
	 * @var int
	 */
	private static $paid_post = 0;

	/**
	 * Cached answer of is_agent() for this request.
	 *
	 * @var bool|null
	 */
	private static $agent = null;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'on_page' ), 0 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'on_rest' ), 10, 3 );
		foreach ( array( 'the_content', 'the_content_feed', 'the_excerpt', 'the_excerpt_rss', 'the_excerpt_embed', 'get_the_excerpt' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'hide_paid' ), 999 );
		}
		// WP Rocket: never serve its cached pages to agents. Other caches: Settings → "Check my setup".
		add_filter( 'rocket_cache_reject_ua', static fn( $agents ) => array_merge( (array) $agents, explode( '|', P2Flux_AP_Detector::pattern() ) ) );
	}

	/**
	 * Is this request from an AI agent (and not a logged-in user)?
	 *
	 * @return bool
	 */
	public static function is_agent() {
		if ( null === self::$agent ) {
			/**
			 * Filter the user-agent signatures treated as AI agents.
			 *
			 * @param string[] $signatures Substrings, matched case-insensitively.
			 */
			$signatures  = (array) apply_filters( 'p2flux_ap_agent_signatures', P2Flux_AP_Detector::SIGNATURES );
			$user_agent  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			$has_payment = null !== self::payment_header() || array() !== self::access_tokens();
			self::$agent = ! is_user_logged_in() && P2Flux_AP_Detector::is_agent( $user_agent, $has_payment, $signatures, P2Flux_AP_Botauth::claimed() );
		}
		return self::$agent;
	}

	/**
	 * The agent's payment header: null when it sent none, false when what it sent is not one.
	 *
	 * @return string|false|null
	 */
	private static function payment_header() {
		foreach ( array( 'HTTP_PAYMENT_SIGNATURE', 'HTTP_X_PAYMENT' ) as $key ) {
			if ( ! isset( $_SERVER[ $key ] ) ) {
				continue;
			}
			// Validated, not sanitised: a base64 payment passed on byte for byte, or refused.
			$raw = wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw = is_string( $raw ) ? trim( $raw ) : '';
			return ( strlen( $raw ) <= self::MAX_HEADER && 1 === preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/', $raw ) ) ? $raw : false;
		}
		return null;
	}

	/**
	 * Access tokens the agent sent (P2Flux-Access-Token), for a membership plugin to check.
	 *
	 * @return string[]
	 */
	public static function access_tokens() {
		$header = isset( $_SERVER['HTTP_P2FLUX_ACCESS_TOKEN'] ) ? wp_unslash( $_SERVER['HTTP_P2FLUX_ACCESS_TOKEN'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated character by character in access_tokens().
		return P2Flux_AP_Rules::access_tokens( $header );
	}

	/**
	 * The price of a post for agents, or null.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $url  The URL requested; the post's permalink when empty.
	 * @return string|null
	 */
	public static function price_of( $post, $url = '' ) {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return null;
		}
		$categories = 'post' === $post->post_type ? wp_get_post_categories( $post->ID ) : array();
		$price      = P2Flux_AP_Rules::price_for_post(
			P2Flux_AP_Settings::get(),
			(string) get_post_meta( $post->ID, P2Flux_AP_Metabox::META, true ),
			$post->post_type,
			is_array( $categories ) ? $categories : array()
		);
		/**
		 * Filter what a request costs an agent.
		 *
		 * For membership plugins: price content WordPress does not know as a post (a page that shows
		 * a record selected by a query variable), or return null for content the agent is already
		 * entitled to (see P2Flux_AP_Gate::access_tokens()). Return a price string such as "0.05".
		 *
		 * @param string|null $price Price in USDC, or null for free.
		 * @param WP_Post     $post  The post.
		 * @param string      $url   The URL requested.
		 */
		$price = apply_filters( 'p2flux_ap_price', $price, $post, '' !== $url ? $url : (string) get_permalink( $post ) );
		return null === $price ? null : P2Flux_AP_Rules::normalise_price( $price );
	}

	/**
	 * A single post or page.
	 *
	 * @return void
	 */
	public static function on_page() {
		if ( ! is_singular() || ! self::is_agent() ) {
			return;
		}
		// Whatever an agent is answered on a single page may depend on what it paid or holds: never cached.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// The constant page-cache plugins (WP Super Cache, W3 Total Cache, LiteSpeed…) read: not ours to prefix.
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}
		nocache_headers();
		header( 'Vary: User-Agent, PAYMENT-SIGNATURE, P2Flux-Access-Token', false );

		$post  = get_queried_object();
		$url   = self::requested_url( $post );
		$price = self::price_of( $post, $url );
		if ( null === $price ) {
			return;
		}

		$decision = self::charge( $price, $url, $post->ID, 'text/html' );
		if ( true === $decision['ok'] ) {
			self::$paid_post = $post->ID;
			return;
		}
		self::send( $decision );
	}

	/**
	 * The URL of the requested single page: its permalink, or the address actually asked for when a
	 * rewrite rule shows something else at it (a record selected by a query variable).
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function requested_url( $post ) {
		$permalink = (string) get_permalink( $post );
		$path      = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		if ( '' === $path || untrailingslashit( (string) wp_parse_url( $permalink, PHP_URL_PATH ) ) === untrailingslashit( $path ) ) {
			return $permalink;
		}
		return home_url( $path );
	}

	/**
	 * Answer a request that was not paid: the 402 (or 503, or a refund's receipt), and nothing else.
	 *
	 * @param array $decision From charge().
	 * @return never
	 */
	public static function send( array $decision ) {
		status_header( $decision['status'] );
		foreach ( $decision['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store, private' );
		echo wp_json_encode( $decision['body'] );
		exit;
	}

	/**
	 * A REST request: a route the owner made paid, or one paid post by id.
	 *
	 * @param mixed           $result  Earlier result.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function on_rest( $result, $server, $request ) {
		if ( null !== $result || is_user_logged_in() ) {
			return $result;
		}
		$route = $request->get_route();
		$price = P2Flux_AP_Rules::price_for_route( P2Flux_AP_Settings::get(), $route );
		$post  = null;
		if ( null === $price && 'GET' === $request->get_method() && self::is_agent() && preg_match( '#^/wp/v2/([a-z0-9_-]+)/(\d+)$#', $route, $m ) ) {
			$post = get_post( (int) $m[2] );
			$type = $post ? get_post_type_object( $post->post_type ) : null;
			if ( $type && ( $type->rest_base ? $type->rest_base : $post->post_type ) === $m[1] ) {
				$price = self::price_of( $post );
			}
		}
		if ( null === $price ) {
			return $result;
		}
		$decision = self::charge( $price, rest_url( ltrim( $route, '/' ) ), $post ? $post->ID : 0, 'application/json' );
		if ( true === $decision['ok'] ) {
			if ( $post ) {
				self::$paid_post = $post->ID;
			}
			return $result;
		}
		$response = new WP_REST_Response( $decision['body'], $decision['status'] );
		foreach ( $decision['headers'] as $name => $value ) {
			$response->header( $name, $value );
		}
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * Content of paid posts, for agents that did not pay for them: the price, not the text.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function hide_paid( $content ) {
		if ( ! self::is_agent() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || $post->ID === self::$paid_post ) {
			return $content;
		}
		$price = self::price_of( $post );
		if ( null === $price ) {
			return $content;
		}
		return sprintf(
			/* translators: 1: price, 2: URL */
			esc_html__( 'Paid content: %1$s USDC per request for AI agents. Request %2$s with an x402 client to read it.', 'p2flux-agent-paywall' ),
			esc_html( $price ),
			esc_url( get_permalink( $post ) )
		);
	}

	/**
	 * Take payment for one resource.
	 *
	 * @param string $price     Price.
	 * @param string $url       Resource URL.
	 * @param int    $post_id   Post, 0 for a route.
	 * @param string $mime_type What the resource is.
	 * @return array{ok: bool, status?: int, headers?: array, body?: array}
	 */
	public static function charge( $price, $url, $post_id, $mime_type ) {
		$settings = P2Flux_AP_Settings::get();
		$header   = self::payment_header();
		if ( null === $header ) {
			return self::required( $settings, $price, $url, $mime_type, null );
		}
		if ( false === $header ) {
			return self::required( $settings, $price, $url, $mime_type, 'invalid_payload' );
		}

		// Used here before: refused without asking P2Flux. P2Flux would refuse it too - a payment settles once.
		$key = 'p2flux_ap_used_' . hash( 'sha256', $header );
		if ( false !== get_transient( $key ) ) {
			return self::required( $settings, $price, $url, $mime_type, 'invalid_transaction_state' );
		}

		$answer = P2Flux_AP_Client::redeem( $settings['wallet'], $price, $header, $url, $settings['environment'] );
		if ( is_wp_error( $answer ) ) {
			return self::unavailable( $settings );
		}
		// Headers from P2Flux go to the agent as they are: only if they are what they must be, base64.
		foreach ( array( 'payment_response', 'payment_required' ) as $field ) {
			if ( isset( $answer[ $field ] ) && ! self::is_header_value( $answer[ $field ] ) ) {
				unset( $answer[ $field ] );
			}
		}

		// Paid on the network of this site's mode, or not paid for here. A live site whose API address
		// was pointed elsewhere must never hand real content out for test money.
		if ( ! empty( $answer['paid'] ) && P2Flux_AP_Client::network( $settings['environment'] ) !== ( $answer['network'] ?? '' ) ) {
			return self::unavailable( $settings );
		}
		if ( ! empty( $answer['paid'] ) ) {
			set_transient( $key, 1, self::USED_TTL );
			$scheme = isset( $answer['scheme'] ) ? (string) $answer['scheme'] : 'exact';
			// A prepaid request has no transaction of its own: its receipt identifies it.
			$id    = 'batch-settlement' === $scheme ? (string) ( $answer['receipt'] ?? '' ) : (string) ( $answer['transaction'] ?? '' );
			$agent = P2Flux_AP_Botauth::verified_agent();
			P2Flux_AP_Log::insert( $post_id, $url, P2Flux_AP_Rules::units( $price ), (string) ( $answer['payer'] ?? '' ), $id, (string) ( $answer['network'] ?? '' ), $scheme, $agent );
			if ( ! empty( $answer['payment_response'] ) ) {
				header( 'PAYMENT-RESPONSE: ' . $answer['payment_response'] );
			}
			/**
			 * A payment settled and the response is about to be served.
			 *
			 * For membership plugins: grant what this payment bought (and send the agent an access
			 * token in a response header) before the content is rendered.
			 *
			 * @param array $payment {
			 *     @type string $price   Price paid, e.g. "0.05".
			 *     @type int    $units   The same in USDC base units.
			 *     @type string $url     The resource.
			 *     @type int    $post_id The post, 0 for a route.
			 *     @type string $payer   The paying wallet address.
			 *     @type string $tx      Transaction hash, or the prepaid receipt.
			 *     @type string $network CAIP-2 network, e.g. "eip155:8453".
			 *     @type string $scheme  "exact" or "batch-settlement".
			 *     @type string $agent   The agent's verified Web Bot Auth host, or "".
			 * }
			 */
			do_action(
				'p2flux_ap_paid',
				array(
					'price'   => $price,
					'units'   => P2Flux_AP_Rules::units( $price ),
					'url'     => $url,
					'post_id' => (int) $post_id,
					'payer'   => (string) ( $answer['payer'] ?? '' ),
					'tx'      => $id,
					'network' => (string) ( $answer['network'] ?? '' ),
					'scheme'  => $scheme,
					'agent'   => (string) $agent,
				)
			);
			return array( 'ok' => true );
		}
		// The agent took its unused prepaid balance back: the receipt, no content.
		if ( ! empty( $answer['refunded'] ) && ! empty( $answer['payment_response'] ) && is_string( $answer['payment_response'] ) ) {
			return array(
				'ok'      => false,
				'status'  => 200,
				'headers' => array( 'PAYMENT-RESPONSE' => $answer['payment_response'] ),
				'body'    => array( 'refunded' => true ),
			);
		}
		$reason = isset( $answer['reason'] ) ? (string) $answer['reason'] : 'payment_refused';
		if ( 'invalid_transaction_state' === $reason ) {
			set_transient( $key, 1, self::USED_TTL );
		}
		// A prepaid refusal carries its own 402: the channel state the agent resynchronises to.
		if ( ! empty( $answer['payment_required'] ) && is_string( $answer['payment_required'] ) ) {
			return self::forwarded( $answer['payment_required'], $reason );
		}
		return self::required( $settings, $price, $url, $mime_type, $reason );
	}

	/**
	 * A value that may be sent as an x402 header: base64, of a sane size.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_header_value( $value ) {
		return is_string( $value ) && strlen( $value ) <= 16384 && 1 === preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/', $value );
	}

	/**
	 * A 402 exactly as P2Flux wrote it.
	 *
	 * @param string $header PAYMENT-REQUIRED header value.
	 * @param string $reason Why the payment was refused.
	 * @return array
	 */
	private static function forwarded( $header, $reason ) {
		$body = json_decode( (string) base64_decode( $header, true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the x402 header format.
		return array(
			'ok'      => false,
			'status'  => 402,
			'headers' => array( 'PAYMENT-REQUIRED' => $header ),
			'body'    => is_array( $body ) ? $body : array( 'error' => $reason ),
		);
	}

	/**
	 * HTTP 402 with what to pay.
	 *
	 * @param array       $settings  Settings.
	 * @param string      $price     Price.
	 * @param string      $url       Resource.
	 * @param string      $mime_type Mime type.
	 * @param string|null $error     Why an earlier payment was not accepted.
	 * @return array
	 */
	private static function required( array $settings, $price, $url, $mime_type, $error ) {
		$accepts = P2Flux_AP_Client::challenge( $settings['wallet'], $price, $settings['environment'] );
		if ( is_wp_error( $accepts ) ) {
			return self::unavailable( $settings );
		}
		if ( 'yes' !== $settings['prepaid'] ) {
			$accepts = array_values( array_filter( $accepts, static fn( $a ) => is_array( $a ) && 'batch-settlement' !== ( $a['scheme'] ?? '' ) ) );
		}
		$required = array(
			'x402Version' => 2,
			'resource'    => array(
				'url'         => $url,
				'description' => get_bloginfo( 'name' ),
				'mimeType'    => $mime_type,
			),
			'accepts'     => $accepts,
		);
		/**
		 * Filter the payment requirement (HTTP 402) before it is sent.
		 *
		 * For membership plugins: say in resource.description what the payment buys. Do not change
		 * "accepts": it is what P2Flux settles.
		 *
		 * @param array  $required The x402 PaymentRequired object.
		 * @param string $price    Price.
		 * @param string $url      Resource.
		 */
		$filtered = apply_filters( 'p2flux_ap_requirement', $required, $price, $url );
		if ( is_array( $filtered ) && isset( $filtered['resource'] ) && is_array( $filtered['resource'] ) ) {
			$required['resource'] = array_map( 'strval', array_intersect_key( $filtered['resource'], $required['resource'] ) ) + $required['resource'];
		}
		if ( null !== $error ) {
			$required = array(
				'x402Version' => 2,
				'error'       => $error,
			) + $required;
		}
		return array(
			'ok'      => false,
			'status'  => 402,
			'headers' => array( 'PAYMENT-REQUIRED' => base64_encode( wp_json_encode( $required ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the x402 header format.
			'body'    => $required,
		);
	}

	/**
	 * P2Flux cannot be reached: the owner's choice.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	private static function unavailable( array $settings ) {
		if ( 'free' === $settings['api_down'] ) {
			return array( 'ok' => true );
		}
		return array(
			'ok'      => false,
			'status'  => 503,
			'headers' => array( 'Retry-After' => '60' ),
			'body'    => array( 'error' => 'payment_service_unavailable' ),
		);
	}

	/**
	 * Tests only: forget what this request decided.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$agent     = null;
		self::$paid_post = 0;
	}
}
