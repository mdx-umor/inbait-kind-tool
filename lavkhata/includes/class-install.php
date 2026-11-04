<?php
/**
 * Activation, database tables and scheduled jobs.
 *
 * @package Lavkhata
 */

namespace Lavkhata;

defined( 'ABSPATH' ) || exit;

class Install {

	const DB_VERSION = '1';

	public static function activate() {
		self::create_tables();
		self::ensure_secrets();
		if ( ! wp_next_scheduled( 'lvk_nightly_sync' ) ) {
			// 2 AM site time.
			$next = strtotime( 'tomorrow 02:00', current_time( 'timestamp' ) ) - ( (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
			wp_schedule_event( $next, 'daily', 'lvk_nightly_sync' );
		}
		// Fill the customer-history index from existing orders, in the background.
		update_option( 'lvk_backfill_cursor', 0, false );
		if ( ! wp_next_scheduled( 'lvk_backfill' ) ) {
			wp_schedule_single_event( time() + 30, 'lvk_backfill' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'lvk_nightly_sync' );
		wp_clear_scheduled_hook( 'lvk_backfill' );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'lvk_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		// One row per order: our own fast index for customer history and courier sync.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}lvk_orders (
				order_id BIGINT UNSIGNED NOT NULL,
				phone VARCHAR(20) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				courier VARCHAR(20) NOT NULL DEFAULT '',
				consignment VARCHAR(64) NOT NULL DEFAULT '',
				tracking VARCHAR(64) NOT NULL DEFAULT '',
				courier_status VARCHAR(60) NOT NULL DEFAULT '',
				outcome VARCHAR(20) NOT NULL DEFAULT '',
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (order_id),
				KEY phone (phone),
				KEY consignment (consignment),
				KEY outcome (outcome)
			) $charset;"
		);

		// Profit ledger: revenue and every cost, dated when it really happened.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}lvk_ledger (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				entry_date DATE NOT NULL,
				kind VARCHAR(30) NOT NULL,
				amount DECIMAL(14,2) NOT NULL DEFAULT 0,
				note VARCHAR(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY entry_date (entry_date)
			) $charset;"
		);

		update_option( 'lvk_db_version', self::DB_VERSION, false );
	}

	/** Generate webhook secrets once so the settings page can show them. */
	public static function ensure_secrets() {
		$updates = array();
		if ( '' === Settings::get( 'steadfast_webhook_token' ) ) {
			$updates['steadfast_webhook_token'] = wp_generate_password( 32, false );
		}
		if ( '' === Settings::get( 'pathao_webhook_secret' ) ) {
			$updates['pathao_webhook_secret'] = wp_generate_password( 32, false );
		}
		if ( $updates ) {
			Settings::update( $updates );
		}
	}

	/** Custom order statuses. */
	public static function register_statuses() {
		register_post_status(
			'wc-lvk-shipped',
			array(
				'label'                     => _x( 'With courier', 'Order status', 'lavkhata' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'With courier <span class="count">(%s)</span>', 'With courier <span class="count">(%s)</span>', 'lavkhata' ),
			)
		);
		register_post_status(
			'wc-lvk-returned',
			array(
				'label'                     => _x( 'Returned', 'Order status', 'lavkhata' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Returned <span class="count">(%s)</span>', 'Returned <span class="count">(%s)</span>', 'lavkhata' ),
			)
		);
	}

	public static function add_statuses( $statuses ) {
		$out = array();
		foreach ( $statuses as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$out['wc-lvk-shipped'] = _x( 'With courier', 'Order status', 'lavkhata' );
			}
			if ( 'wc-completed' === $key ) {
				$out['wc-lvk-returned'] = _x( 'Returned', 'Order status', 'lavkhata' );
			}
		}
		return $out;
	}
}
