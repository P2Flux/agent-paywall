<?php
/**
 * Payments received, for the earnings box. The chain is the record; this is the site's own copy.
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payment log.
 */
class P2Flux_AP_Log {

	const DB_VERSION = '3';
	const PRUNE_HOOK = 'p2flux_ap_prune';
	/** Rows older than this are deleted daily. */
	const KEEP_DAYS = 400;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'p2flux_ap_payments';
	}

	/**
	 * Create or update the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				url varchar(2048) NOT NULL DEFAULT '',
				amount bigint(20) unsigned NOT NULL,
				payer char(42) NOT NULL DEFAULT '',
				tx char(66) NOT NULL,
				network varchar(32) NOT NULL DEFAULT '',
				scheme varchar(32) NOT NULL DEFAULT 'exact',
				agent varchar(100) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY tx (tx),
				KEY created_at (created_at)
			) {$charset};"
		);
		update_option( 'p2flux_ap_db_version', self::DB_VERSION, false );
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Install on first use after an update, when activation did not run.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( 'p2flux_ap_db_version' ) ) {
			self::install();
		}
		add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune' ) );
	}

	/**
	 * Record one payment. A transaction is recorded once.
	 *
	 * @param int    $post_id Post, 0 for a route.
	 * @param string $url     URL paid for.
	 * @param int    $amount  USDC base units.
	 * @param string $payer   Agent wallet.
	 * @param string $tx      Transaction hash, or the receipt of a prepaid request.
	 * @param string $network CAIP-2 network.
	 * @param string $scheme  exact | batch-settlement.
	 * @param string $agent   Who paid, when it proved it (Web Bot Auth), e.g. "chatgpt.com".
	 * @return void
	 */
	public static function insert( $post_id, $url, $amount, $payer, $tx, $network, $scheme = 'exact', $agent = '' ) {
		global $wpdb;
		if ( ! preg_match( '/^0x[0-9a-fA-F]{64}$/', (string) $tx ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, one insert.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (created_at, post_id, url, amount, payer, tx, network, scheme, agent) VALUES (%s, %d, %s, %d, %s, %s, %s, %s, %s)',
				self::table(),
				gmdate( 'Y-m-d H:i:s' ),
				(int) $post_id,
				substr( (string) $url, 0, 2048 ),
				(int) $amount,
				preg_match( '/^0x[0-9a-fA-F]{40}$/', (string) $payer ) ? $payer : '',
				$tx,
				substr( (string) $network, 0, 32 ),
				'batch-settlement' === $scheme ? 'batch-settlement' : 'exact',
				substr( (string) $agent, 0, 100 )
			)
		);
	}

	/**
	 * Count and sum for today, this month and all time (UTC).
	 *
	 * @return array{today: array{count:int,units:int}, month: array{count:int,units:int}, all: array{count:int,units:int}}
	 */
	public static function totals() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, admin screen only.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT
					COUNT(*) AS all_count, COALESCE(SUM(amount), 0) AS all_units,
					COALESCE(SUM(created_at >= %s), 0) AS month_count, COALESCE(SUM(IF(created_at >= %s, amount, 0)), 0) AS month_units,
					COALESCE(SUM(created_at >= %s), 0) AS today_count, COALESCE(SUM(IF(created_at >= %s, amount, 0)), 0) AS today_units
				FROM %i',
				gmdate( 'Y-m-01 00:00:00' ),
				gmdate( 'Y-m-01 00:00:00' ),
				gmdate( 'Y-m-d 00:00:00' ),
				gmdate( 'Y-m-d 00:00:00' ),
				self::table()
			)
		);
		$get = static fn( $k ) => $row ? (int) $row->$k : 0;
		return array(
			'today' => array(
				'count' => $get( 'today_count' ),
				'units' => $get( 'today_units' ),
			),
			'month' => array(
				'count' => $get( 'month_count' ),
				'units' => $get( 'month_units' ),
			),
			'all'   => array(
				'count' => $get( 'all_count' ),
				'units' => $get( 'all_units' ),
			),
		);
	}

	/**
	 * Latest payments.
	 *
	 * @param int $limit How many.
	 * @return array
	 */
	public static function recent( $limit ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, admin screen only.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table(), (int) $limit ) );
	}

	/**
	 * Delete old rows.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, daily.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table(), gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
	}
}
