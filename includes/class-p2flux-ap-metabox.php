<?php
/**
 * A post's own price.
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Price for AI agents" in the editor.
 */
class P2Flux_AP_Metabox {

	const META  = '_p2flux_ap_price';
	const NONCE = 'p2flux_ap_price_nonce';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ) );
	}

	/**
	 * Add the box to every public post type.
	 *
	 * @return void
	 */
	public static function add() {
		foreach ( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) as $type ) {
			add_meta_box( 'p2flux-ap-price', __( 'Price for AI agents', 'p2flux-agent-paywall' ), array( __CLASS__, 'render' ), $type, 'side' );
		}
	}

	/**
	 * The box.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render( $post ) {
		wp_nonce_field( 'p2flux_ap_save_price', self::NONCE );
		$value = (string) get_post_meta( $post->ID, self::META, true );
		?>
		<p>
			<input type="text" inputmode="decimal" class="small-text" id="p2flux-ap-post-price" name="p2flux_ap_price" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( P2Flux_AP_Settings::get()['default_price'] ); ?>"> USDC
		</p>
		<p class="description"><?php esc_html_e( 'Empty: the site settings decide. 0: free for agents. Any other amount (at least 0.001): agents pay that to read this. Under 0.01 it is that price from a prepaid balance, 0.01 paid one by one.', 'p2flux-agent-paywall' ); ?></p>
		<?php
	}

	/**
	 * Save. Invalid input leaves the old value.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public static function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), 'p2flux_ap_save_price' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$raw = isset( $_POST['p2flux_ap_price'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['p2flux_ap_price'] ) ) ) : '';
		if ( '' === $raw ) {
			delete_post_meta( $post_id, self::META );
			return;
		}
		$value = '0' === $raw ? '0' : P2Flux_AP_Rules::normalise_price( $raw );
		if ( null !== $value ) {
			update_post_meta( $post_id, self::META, $value );
		}
	}
}
