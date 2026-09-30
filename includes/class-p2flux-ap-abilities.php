<?php
/**
 * Abilities: what the site owner's own AI assistant can do with this plugin (WordPress 6.9+).
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registered with the WordPress Abilities API, so an assistant connected to the site (for example
 * through the MCP Adapter plugin) can answer "what did AI agents pay me this month?" and "make this
 * post cost 0.10". Each ability asks for the same capability the matching screen does.
 */
class P2Flux_AP_Abilities {

	const CATEGORY = 'p2flux-agent-paywall';

	/**
	 * Hooks. Nothing on WordPress versions without the API.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'abilities' ) );
	}

	/**
	 * The category.
	 *
	 * @return void
	 */
	public static function category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Agent Paywall', 'p2flux-agent-paywall' ),
				'description' => __( 'What AI agents pay to read this site, and what it costs them.', 'p2flux-agent-paywall' ),
			)
		);
	}

	/**
	 * The abilities.
	 *
	 * @return void
	 */
	public static function abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$admin = static fn() => current_user_can( 'manage_options' );
		$meta  = static fn( $read_only ) => array(
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
			'annotations'  => array(
				'readonly'    => $read_only,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
		wp_register_ability(
			'p2flux-agent-paywall/get-earnings',
			array(
				'label'               => __( 'Get Agent Paywall earnings', 'p2flux-agent-paywall' ),
				'description'         => __( 'What AI agents paid to read this site: totals for today, this month and all time in USDC, and the latest payments.', 'p2flux-agent-paywall' ),
				'category'            => self::CATEGORY,
				'permission_callback' => $admin,
				'execute_callback'    => array( __CLASS__, 'earnings' ),
				'meta'                => $meta( true ),
			)
		);
		wp_register_ability(
			'p2flux-agent-paywall/get-settings',
			array(
				'label'               => __( 'Get Agent Paywall settings', 'p2flux-agent-paywall' ),
				'description'         => __( 'What is paid for AI agents on this site, the price per request, the wallet that receives the money and the mode (test or live).', 'p2flux-agent-paywall' ),
				'category'            => self::CATEGORY,
				'permission_callback' => $admin,
				'execute_callback'    => array( 'P2Flux_AP_Settings', 'get' ),
				'meta'                => $meta( true ),
			)
		);
		wp_register_ability(
			'p2flux-agent-paywall/set-post-price',
			array(
				'label'               => __( 'Set the price of a post for AI agents', 'p2flux-agent-paywall' ),
				'description'         => __( 'Set what AI agents pay to read one post, page or file. price: an amount in USDC such as "0.10" (at least 0.01), "0" to make it free for agents, or "" to follow the site settings.', 'p2flux-agent-paywall' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'post_id', 'price' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'price'   => array(
							'type'      => 'string',
							'maxLength' => 12,
						),
					),
				),
				'permission_callback' => static fn( $input ) => is_array( $input ) && current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) ),
				'execute_callback'    => array( __CLASS__, 'set_price' ),
				'meta'                => $meta( false ),
			)
		);
	}

	/**
	 * Earnings.
	 *
	 * @return array
	 */
	public static function earnings() {
		$out = array( 'currency' => 'USDC' );
		foreach ( P2Flux_AP_Log::totals() as $period => $total ) {
			$out[ $period ] = array(
				'payments' => $total['count'],
				'amount'   => P2Flux_AP_Rules::format_units( $total['units'] ),
			);
		}
		$out['latest'] = array_map(
			static fn( $row ) => array(
				'time'   => $row->created_at . ' UTC',
				'url'    => $row->url,
				'amount' => P2Flux_AP_Rules::format_units( (int) $row->amount ),
				'agent'  => (string) ( $row->agent ?? '' ),
				'wallet' => $row->payer,
			),
			P2Flux_AP_Log::recent( 20 )
		);
		return $out;
	}

	/**
	 * Set one post's price.
	 *
	 * @param array $input post_id, price.
	 * @return array|WP_Error
	 */
	public static function set_price( $input ) {
		$id  = (int) $input['post_id'];
		$raw = trim( (string) $input['price'] );
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'p2flux_ap_no_post', __( 'There is no such post.', 'p2flux-agent-paywall' ) );
		}
		if ( '' === $raw ) {
			delete_post_meta( $id, P2Flux_AP_Metabox::META );
		} else {
			$price = '0' === $raw ? '0' : P2Flux_AP_Rules::normalise_price( $raw );
			if ( null === $price ) {
				/* translators: 1: smallest price, 2: largest price */
				return new WP_Error( 'p2flux_ap_price', sprintf( __( 'The price must be between %1$s and %2$s USDC, 0 or empty.', 'p2flux-agent-paywall' ), P2Flux_AP_Rules::MIN_PRICE, P2Flux_AP_Rules::MAX_PRICE ) );
			}
			update_post_meta( $id, P2Flux_AP_Metabox::META, $price );
		}
		if ( 'attachment' === get_post_type( $id ) ) {
			P2Flux_AP_Files::sync();
		}
		$now = 'attachment' === get_post_type( $id ) ? P2Flux_AP_Files::price_of( $id ) : P2Flux_AP_Gate::price_of( get_post( $id ) );
		return array(
			'post_id' => $id,
			'url'     => (string) get_permalink( $id ),
			'price'   => $now ?? 'free',
		);
	}
}
