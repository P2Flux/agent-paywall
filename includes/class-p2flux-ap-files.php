<?php
/**
 * Paid files: an image, a PDF or a dataset in the Media Library with a price for AI agents.
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'ABSPATH' ) || exit;

/**
 * WordPress does not see requests for uploaded files: the web server answers them itself. So a rule in
 * the uploads folder sends the requests of AGENTS for the PRICED files - those files and no others -
 * through WordPress, where they are paid for like a post. People's requests never take that path, and
 * no other file in the folder is touched.
 *
 * Apache and LiteSpeed read the rule from .htaccess, written here. nginx needs it in its own
 * configuration (the guide has it); without it the paid address `/?p2flux_ap_file=<id>` still works.
 *
 * What comes through WordPress is sent ONLY if it is a Media Library file with a price. Anything else
 * is a 404 here: this must never become a way around another plugin's protection of its own files.
 */
class P2Flux_AP_Files {

	const QUERY  = 'p2flux_ap_file';
	const MARKER = 'P2Flux Agent Paywall';
	/** Paths per rule: an Apache line holds 8190 characters. */
	const PER_RULE = 25;
	// ponytail: every priced file is a path in .htaccess; past MAX_FILES the newest are left to the paid address only. A rewrite map when someone sells that many files.
	const MAX_FILES = 2000;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 99 );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * The web server rule. Pure.
	 *
	 * @param string   $index   Path of WordPress' index.php, e.g. "/index.php".
	 * @param string   $pattern Agent user agents, from P2Flux_AP_Detector::pattern().
	 * @param string[] $paths   Priced files, relative to the uploads folder, e.g. "2026/09/prices.csv".
	 * @return string[] Lines for .htaccess.
	 */
	public static function rules( $index, $pattern, array $paths ) {
		$files = array();
		foreach ( $paths as $path ) {
			// A path that could break out of the expression is left out, not escaped around.
			if ( is_string( $path ) && preg_match( '#^[A-Za-z0-9._/-]+\.([A-Za-z0-9]+)$#', $path, $m ) && false === strpos( $path, '..' ) ) {
				$stem = substr( $path, 0, -strlen( $m[1] ) - 1 );
				// The file, and the smaller sizes WordPress makes of an image.
				$files[] = str_replace( array( '.', '-' ), array( '\\.', '\\-' ), $stem ) . '(?:\\-[0-9]+x[0-9]+|\\-scaled)?\\.' . $m[1];
			}
		}
		if ( ! $files ) {
			return array();
		}
		$lines = array( '<IfModule mod_rewrite.c>', 'RewriteEngine On' );
		foreach ( array_chunk( $files, self::PER_RULE ) as $chunk ) {
			$lines[] = 'RewriteCond %{HTTP_USER_AGENT} (' . $pattern . ') [NC,OR]';
			$lines[] = 'RewriteCond %{HTTP_USER_AGENT} ^$ [OR]';
			$lines[] = 'RewriteCond %{HTTP:Signature-Agent} !^$ [OR]';
			$lines[] = 'RewriteCond %{HTTP:Payment-Signature} !^$';
			$lines[] = 'RewriteRule ^((?:' . implode( '|', $chunk ) . '))$ ' . $index . '?' . self::QUERY . '=$1 [L,QSA]';
		}
		$lines[] = '</IfModule>';
		return $lines;
	}

	/**
	 * Write the rule while any file has a price, remove it when none has.
	 *
	 * @param bool $remove Remove it whatever is priced (deactivation).
	 * @return void
	 */
	public static function sync( $remove = false ) {
		$dir = wp_upload_dir()['basedir'];
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return;
		}
		$priced = $remove ? array() : get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'meta_key'    => P2Flux_AP_Metabox::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- on save only.
				'fields'      => 'ids',
				'numberposts' => self::MAX_FILES,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
		if ( ! $priced && ! is_file( $dir . '/.htaccess' ) ) {
			return;
		}
		$paths = array();
		foreach ( $priced as $id ) {
			if ( null !== self::price_of( (int) $id ) ) {
				$paths[] = (string) get_post_meta( (int) $id, '_wp_attached_file', true );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$signatures = (array) apply_filters( 'p2flux_ap_agent_signatures', P2Flux_AP_Detector::SIGNATURES );
		$index      = trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) . 'index.php';
		insert_with_markers( $dir . '/.htaccess', self::MARKER, self::rules( $index, P2Flux_AP_Detector::pattern( $signatures ), $paths ) );
	}

	/**
	 * The price of a file for agents, or null.
	 *
	 * @param int $id Attachment.
	 * @return string|null
	 */
	public static function price_of( $id ) {
		return $id ? P2Flux_AP_Rules::normalise_price( (string) get_post_meta( $id, P2Flux_AP_Metabox::META, true ) ) : null;
	}

	/**
	 * The attachment a file in uploads belongs to: the file itself, or one of its image sizes.
	 *
	 * @param string $relative Path below the uploads folder.
	 * @return int 0 when it is nobody's.
	 */
	private static function attachment_of( $relative ) {
		foreach ( array_unique( array( $relative, (string) preg_replace( '/-(\d+x\d+|scaled)(\.[A-Za-z0-9]+)$/', '$2', $relative ) ) ) as $candidate ) {
			$ids = get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'meta_key'    => '_wp_attached_file', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- how core finds it too.
					'meta_value'  => $candidate, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'      => 'ids',
					'numberposts' => 1,
				)
			);
			if ( $ids ) {
				return (int) $ids[0];
			}
		}
		return 0;
	}

	/**
	 * A request for a file that came through WordPress: charge an agent when the file has a price, then send it.
	 *
	 * @return void
	 */
	public static function maybe_serve() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public download address, no state changes.
		$wanted = isset( $_GET[ self::QUERY ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ) : '';
		if ( '' === $wanted ) {
			return;
		}
		$base = realpath( wp_upload_dir()['basedir'] );
		if ( ctype_digit( $wanted ) ) {
			$id   = (int) $wanted;
			$file = 'attachment' === get_post_type( $id ) ? realpath( (string) get_attached_file( $id ) ) : false;
		} else {
			$file = realpath( $base . '/' . $wanted );
			$id   = $file ? self::attachment_of( ltrim( str_replace( '\\', '/', substr( $file, strlen( (string) $base ) ) ), '/' ) ) : 0;
		}
		// Sent only: a Media Library file WITH A PRICE, inside the uploads folder, of a type WordPress
		// allows to be uploaded. Everything else - files of other plugins, files without a price - is not
		// this plugin's to hand out: the web server decides about those, as it did before.
		$type  = $file ? wp_check_filetype( $file )['type'] : false;
		$price = self::price_of( $id );
		if ( null === $price || ! $base || ! $file || ! $type || 0 !== strpos( $file, $base . DIRECTORY_SEPARATOR ) || ! is_file( $file ) || '.' === basename( $file )[0] ) {
			status_header( 404 );
			exit;
		}
		if ( P2Flux_AP_Gate::is_agent() ) {
			$decision = P2Flux_AP_Gate::charge( $price, (string) wp_get_attachment_url( $id ), $id, $type );
			if ( true !== $decision['ok'] ) {
				P2Flux_AP_Gate::send( $decision );
			}
			header( 'Cache-Control: no-store, private' );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: ' . $type );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Vary: User-Agent, PAYMENT-SIGNATURE', false );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a download; WP_Filesystem would load it into memory.
		exit;
	}

	/**
	 * "Price for AI agents" on a file in the Media Library.
	 *
	 * @param array   $fields Fields.
	 * @param WP_Post $post   Attachment.
	 * @return array
	 */
	public static function field( $fields, $post ) {
		$help = __( 'Empty: free. An amount (at least 0.001; under 0.01 only from a prepaid balance, else 0.01): AI agents pay that to download this file. People see it as always.', 'p2flux-agent-paywall' );
		if ( ! empty( $GLOBALS['is_nginx'] ) ) {
			$help .= ' ' . __( 'Your server is nginx: it needs one rule from the P2Flux guide to protect the direct address of files.', 'p2flux-agent-paywall' );
		}
		$fields['p2flux_ap_price'] = array(
			'label' => __( 'Price for AI agents (USDC)', 'p2flux-agent-paywall' ),
			'input' => 'text',
			'value' => (string) get_post_meta( $post->ID, P2Flux_AP_Metabox::META, true ),
			'helps' => $help,
		);
		return $fields;
	}

	/**
	 * Save the price of a file. Invalid input leaves the old value.
	 *
	 * @param array $post       Attachment data.
	 * @param array $attachment Submitted fields.
	 * @return array
	 */
	public static function save( $post, $attachment ) {
		if ( isset( $attachment['p2flux_ap_price'] ) && current_user_can( 'edit_post', (int) $post['ID'] ) ) {
			$raw = trim( sanitize_text_field( (string) $attachment['p2flux_ap_price'] ) );
			if ( '' === $raw || '0' === $raw ) {
				delete_post_meta( (int) $post['ID'], P2Flux_AP_Metabox::META );
			} elseif ( null !== P2Flux_AP_Rules::normalise_price( $raw ) ) {
				update_post_meta( (int) $post['ID'], P2Flux_AP_Metabox::META, P2Flux_AP_Rules::normalise_price( $raw ) );
			}
			self::sync();
		}
		return $post;
	}
}
