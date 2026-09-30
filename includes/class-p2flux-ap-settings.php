<?php
/**
 * The one settings page.
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings: wallet, network, price, what is paid, what happens when P2Flux cannot be reached.
 */
class P2Flux_AP_Settings {

	const OPTION = 'p2flux_ap_settings';
	const PAGE   = 'p2flux-agent-paywall';

	/**
	 * Defaults. Nothing is paid until the owner says so.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'wallet'          => '',
			'environment'     => 'test',
			'default_price'   => '0.05',
			'paid_post_types' => array(),
			'paid_categories' => array(),
			'paid_routes'     => array(),
			'api_down'        => 'refuse',
			'prepaid'         => 'yes',
		);
	}

	/**
	 * Current settings, always complete.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( P2FLUX_AP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Settings → Agent Paywall.
	 *
	 * @return void
	 */
	public static function menu() {
		add_options_page(
			__( 'Agent Paywall', 'p2flux-agent-paywall' ),
			__( 'Agent Paywall', 'p2flux-agent-paywall' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'p2flux-agent-paywall' ) . '</a>' );
		return $links;
	}

	/**
	 * Settings API registration.
	 *
	 * @return void
	 */
	public static function register_setting() {
		register_setting(
			'p2flux_ap',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Clean what the form sent. Anything invalid keeps its previous value and says why.
	 *
	 * @param mixed $input Raw form values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$old   = self::get();
		$input = is_array( $input ) ? $input : array();
		$clean = $old;

		$clean['environment'] = ( isset( $input['environment'] ) && 'live' === $input['environment'] ) ? 'live' : 'test';
		$clean['api_down']    = ( isset( $input['api_down'] ) && 'free' === $input['api_down'] ) ? 'free' : 'refuse';
		$clean['prepaid']     = ( isset( $input['prepaid'] ) && 'yes' === $input['prepaid'] ) ? 'yes' : 'no';

		$price = P2Flux_AP_Rules::normalise_price( isset( $input['default_price'] ) ? sanitize_text_field( (string) $input['default_price'] ) : '' );
		if ( null === $price ) {
			/* translators: 1: smallest price, 2: largest price */
			self::error( 'price', sprintf( __( 'The price must be between %1$s and %2$s USDC. The previous price is kept.', 'p2flux-agent-paywall' ), P2Flux_AP_Rules::MIN_PRICE, P2Flux_AP_Rules::MAX_PRICE ) );
		} else {
			$clean['default_price'] = $price;
		}

		$types                    = get_post_types( array( 'public' => true ) );
		$clean['paid_post_types'] = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['paid_post_types'] ?? array() ) ), array_diff( $types, array( 'attachment' ) ) ) );
		$clean['paid_categories'] = array_values( array_filter( array_map( 'absint', (array) ( $input['paid_categories'] ?? array() ) ) ) );

		$routes = array();
		foreach ( preg_split( '/\R/', (string) ( $input['paid_routes'] ?? '' ) ) as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( P2Flux_AP_Rules::route_allowed( $line ) ) {
				$routes[] = $line;
			} else {
				/* translators: %s: a REST route prefix */
				self::error( 'route', sprintf( __( '"%s" cannot be paid. Routes of WordPress itself (/wp/…) stay free; the editor needs them.', 'p2flux-agent-paywall' ), $line ) );
			}
		}
		$clean['paid_routes'] = array_values( array_unique( $routes ) );

		$wallet = trim( sanitize_text_field( (string) ( $input['wallet'] ?? '' ) ) );
		if ( '' === $wallet ) {
			$clean['wallet'] = '';
		} elseif ( ! preg_match( '/^0x[0-9a-fA-F]{40}$/', $wallet ) ) {
			self::error( 'wallet', __( 'That is not a wallet address. It starts with 0x and has 40 more characters.', 'p2flux-agent-paywall' ) );
		} else {
			// P2Flux checks the address (including its checksum) and answers what an agent will be asked.
			$check = P2Flux_AP_Client::challenge( $wallet, $clean['default_price'] ?? '0.05', $clean['environment'], true );
			if ( is_wp_error( $check ) && 'p2flux_ap_rejected' === $check->get_error_code() ) {
				self::error( 'wallet', __( 'P2Flux does not accept this wallet address. Copy it again from your wallet: one wrong character sends money nowhere.', 'p2flux-agent-paywall' ) );
			} else {
				if ( is_wp_error( $check ) ) {
					self::error( 'wallet', __( 'Saved, but P2Flux could not be reached to check the address. Agents will be asked to pay once it can.', 'p2flux-agent-paywall' ), 'warning' );
				}
				$clean['wallet'] = $wallet;
			}
		}
		return $clean;
	}

	/**
	 * A message on the settings screen.
	 *
	 * @param string $code    Code.
	 * @param string $message Text.
	 * @param string $type    error|warning|success.
	 * @return void
	 */
	private static function error( $code, $message, $type = 'error' ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, 'p2flux_ap_' . $code, $message, $type );
		}
	}

	/**
	 * The page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s          = self::get();
		$name       = self::OPTION;
		$categories = get_categories( array( 'hide_empty' => false ) );
		$types      = array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) );
		$totals     = P2Flux_AP_Log::totals();
		$recent     = P2Flux_AP_Log::recent( 20 );
		wp_enqueue_style( 'p2flux-ap-admin', plugins_url( 'assets/admin.css', P2FLUX_AP_FILE ), array(), P2FLUX_AP_VERSION );
		?>
		<div class="wrap p2flux-ap">
			<h1><?php esc_html_e( 'Agent Paywall', 'p2flux-agent-paywall' ); ?></h1>
			<p class="p2flux-ap-lead"><?php esc_html_e( 'AI agents pay in USDC to read what you choose below. People visiting your site see it as always. Money goes straight to your wallet; P2Flux keeps 1% (at least 0.003 USDC) of each payment.', 'p2flux-agent-paywall' ); ?></p>

			<?php if ( '' !== $s['wallet'] ) : ?>
			<div class="p2flux-ap-earnings">
				<div><span><?php esc_html_e( 'Today', 'p2flux-agent-paywall' ); ?></span><strong><?php echo esc_html( P2Flux_AP_Rules::format_units( $totals['today']['units'] ) ); ?> USDC</strong><em><?php echo esc_html( sprintf( /* translators: %d: number of payments */ _n( '%d payment', '%d payments', $totals['today']['count'], 'p2flux-agent-paywall' ), $totals['today']['count'] ) ); ?></em></div>
				<div><span><?php esc_html_e( 'This month', 'p2flux-agent-paywall' ); ?></span><strong><?php echo esc_html( P2Flux_AP_Rules::format_units( $totals['month']['units'] ) ); ?> USDC</strong><em><?php echo esc_html( sprintf( /* translators: %d: number of payments */ _n( '%d payment', '%d payments', $totals['month']['count'], 'p2flux-agent-paywall' ), $totals['month']['count'] ) ); ?></em></div>
				<div><span><?php esc_html_e( 'All time', 'p2flux-agent-paywall' ); ?></span><strong><?php echo esc_html( P2Flux_AP_Rules::format_units( $totals['all']['units'] ) ); ?> USDC</strong><em><?php echo esc_html( sprintf( /* translators: %d: number of payments */ _n( '%d payment', '%d payments', $totals['all']['count'], 'p2flux-agent-paywall' ), $totals['all']['count'] ) ); ?></em></div>
			</div>
			<p class="description"><?php esc_html_e( 'Amounts are what agents paid. Pay-per-page payments reach your wallet at once, less 1% (at least 0.003 USDC); prepaid ones in a payout at 2 USDC or weekly, less 3%.', 'p2flux-agent-paywall' ); ?></p>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'p2flux_ap' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="p2flux-ap-wallet"><?php esc_html_e( 'Your wallet', 'p2flux-agent-paywall' ); ?></label></th>
						<td>
							<input id="p2flux-ap-wallet" class="regular-text code" type="text" name="<?php echo esc_attr( $name ); ?>[wallet]" value="<?php echo esc_attr( $s['wallet'] ); ?>" placeholder="0x…" autocomplete="off" spellcheck="false">
							<p class="description"><?php esc_html_e( 'A wallet address on Base that receives USDC (for example from Coinbase Wallet, MetaMask or an exchange that supports Base). Nothing happens until it is set.', 'p2flux-agent-paywall' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'p2flux-agent-paywall' ); ?></th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[environment]" value="test" <?php checked( $s['environment'], 'test' ); ?>> <?php esc_html_e( 'Test — agents pay with test USDC on Base Sepolia. No real money.', 'p2flux-agent-paywall' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[environment]" value="live" <?php checked( $s['environment'], 'live' ); ?>> <?php esc_html_e( 'Live — agents pay real USDC on Base.', 'p2flux-agent-paywall' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="p2flux-ap-price"><?php esc_html_e( 'Price per request', 'p2flux-agent-paywall' ); ?></label></th>
						<td>
							<input id="p2flux-ap-price" class="small-text" type="text" inputmode="decimal" name="<?php echo esc_attr( $name ); ?>[default_price]" value="<?php echo esc_attr( $s['default_price'] ); ?>"> USDC
							<p class="description"><?php esc_html_e( 'What an agent pays to read one page or call one route. At least 0.01. A single post can have its own price, set in the editor.', 'p2flux-agent-paywall' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Paid for agents', 'p2flux-agent-paywall' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $types as $type ) : ?>
									<?php $object = get_post_type_object( $type ); ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[paid_post_types][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $s['paid_post_types'], true ) ); ?>> <?php echo esc_html( $object ? $object->labels->name : $type ); ?></label><br>
								<?php endforeach; ?>
							</fieldset>
							<?php if ( $categories ) : ?>
							<p><strong><?php esc_html_e( 'Or only these categories', 'p2flux-agent-paywall' ); ?></strong></p>
							<fieldset class="p2flux-ap-columns">
								<?php foreach ( $categories as $category ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[paid_categories][]" value="<?php echo esc_attr( (string) $category->term_id ); ?>" <?php checked( in_array( (int) $category->term_id, $s['paid_categories'], true ) ); ?>> <?php echo esc_html( $category->name ); ?></label>
								<?php endforeach; ?>
							</fieldset>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Agents that open a paid post get "402 Payment Required" and pay. In lists, feeds and search they see the title and the price, not the text. Logged-in users always read for free.', 'p2flux-agent-paywall' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="p2flux-ap-routes"><?php esc_html_e( 'Paid API routes', 'p2flux-agent-paywall' ); ?></label></th>
						<td>
							<textarea id="p2flux-ap-routes" class="large-text code" rows="3" name="<?php echo esc_attr( $name ); ?>[paid_routes]" placeholder="/myplugin/v1/"><?php echo esc_textarea( implode( "\n", $s['paid_routes'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional. REST API routes that every caller who is not logged in pays for, one prefix per line. For sites that sell data through their own API.', 'p2flux-agent-paywall' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Prepaid balance', 'p2flux-agent-paywall' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( $name ); ?>[prepaid]" value="no">
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[prepaid]" value="yes" <?php checked( $s['prepaid'], 'yes' ); ?>> <?php esc_html_e( 'Let agents pay from a prepaid balance (recommended)', 'p2flux-agent-paywall' ); ?></label>
							<p class="description"><?php esc_html_e( 'An agent puts at least 1 USDC aside once, then pays each page without a transaction - cheaper and faster for small prices. You receive the money in one payout when it reaches 2 USDC, or weekly, less 3%. Pay-per-page stays available for every agent.', 'p2flux-agent-paywall' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'If P2Flux cannot be reached', 'p2flux-agent-paywall' ); ?></th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[api_down]" value="refuse" <?php checked( $s['api_down'], 'refuse' ); ?>> <?php esc_html_e( 'Agents are asked to come back later (recommended)', 'p2flux-agent-paywall' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[api_down]" value="free" <?php checked( $s['api_down'], 'free' ); ?>> <?php esc_html_e( 'Agents read for free until it is back', 'p2flux-agent-paywall' ); ?></label>
							<p class="description"><?php esc_html_e( 'People visiting your site are never affected.', 'p2flux-agent-paywall' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php if ( $recent ) : ?>
			<h2><?php esc_html_e( 'Last payments', 'p2flux-agent-paywall' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Time (UTC)', 'p2flux-agent-paywall' ); ?></th><th><?php esc_html_e( 'Page', 'p2flux-agent-paywall' ); ?></th><th><?php esc_html_e( 'Amount', 'p2flux-agent-paywall' ); ?></th><th><?php esc_html_e( 'Agent wallet', 'p2flux-agent-paywall' ); ?></th><th><?php esc_html_e( 'Transaction', 'p2flux-agent-paywall' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $recent as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td><a href="<?php echo esc_url( $row->url ); ?>"><?php echo esc_html( wp_parse_url( $row->url, PHP_URL_PATH ) ?? $row->url ); ?></a></td>
						<td><?php echo esc_html( P2Flux_AP_Rules::format_units( (int) $row->amount ) ); ?> USDC</td>
						<td class="code"><?php echo esc_html( substr( $row->payer, 0, 6 ) . '…' . substr( $row->payer, -4 ) ); ?></td>
						<?php if ( 'batch-settlement' === ( $row->scheme ?? 'exact' ) ) : ?>
						<td><?php esc_html_e( 'prepaid', 'p2flux-agent-paywall' ); ?></td>
						<?php else : ?>
						<td><a href="<?php echo esc_url( ( 'eip155:8453' === $row->network ? 'https://basescan.org/tx/' : 'https://sepolia.basescan.org/tx/' ) . $row->tx ); ?>" target="_blank" rel="noopener"><?php echo esc_html( substr( $row->tx, 0, 10 ) . '…' ); ?></a></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
