<?php
/**
 * Our own order index (lvk_orders table): phone, courier, consignment, outcome.
 *
 * @package Lavkhata
 */

namespace Lavkhata;

defined( 'ABSPATH' ) || exit;

class Index {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lvk_orders';
	}

	/** Make sure an order has a row, and keep its phone fresh. */
	public static function touch( $order ) {
		global $wpdb;
		if ( ! $order instanceof \WC_Order || 'shop_order_refund' === $order->get_type() ) {
			return;
		}
		$phone   = Helpers::bd_phone( $order->get_billing_phone() );
		$created = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' );
		$now     = current_time( 'mysql' );
		$table   = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$table} WHERE order_id = %d", $order->get_id() ) );
		if ( $exists ) {
			$wpdb->update( $table, array( 'phone' => $phone, 'updated_at' => $now ), array( 'order_id' => $order->get_id() ) ); // phpcs:ignore
		} else {
			$wpdb->insert( // phpcs:ignore
				$table,
				array(
					'order_id'   => $order->get_id(),
					'phone'      => $phone,
					'created_at' => $created,
					'updated_at' => $now,
				)
			);
		}
	}

	public static function get( $order_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", $order_id ), ARRAY_A );
	}

	public static function set( $order_id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql' );
		if ( ! self::get( $order_id ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				self::touch( $order );
			}
		}
		$wpdb->update( self::table(), $fields, array( 'order_id' => (int) $order_id ) ); // phpcs:ignore
	}

	public static function find_by_consignment( $consignment ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$table} WHERE consignment = %s LIMIT 1", (string) $consignment ) );
	}

	/**
	 * This shop's own delivery history for a phone number.
	 *
	 * @return array{delivered:int,returned:int,total:int,open:int}
	 */
	public static function history( $phone, $exclude_order = 0 ) {
		global $wpdb;
		$phone = Helpers::bd_phone( $phone );
		$out   = array( 'delivered' => 0, 'returned' => 0, 'total' => 0, 'open' => 0 );
		if ( '' === $phone ) {
			return $out;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT outcome, COUNT(*) AS n FROM {$table} WHERE phone = %s AND order_id <> %d GROUP BY outcome", $phone, (int) $exclude_order ), ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$n = (int) $r['n'];
			if ( in_array( $r['outcome'], array( 'delivered', 'partial' ), true ) ) {
				$out['delivered'] += $n;
			} elseif ( 'returned' === $r['outcome'] ) {
				$out['returned'] += $n;
			} elseif ( '' === $r['outcome'] ) {
				$out['open'] += $n;
			}
		}
		$out['total'] = $out['delivered'] + $out['returned'];
		return $out;
	}

	/** Consignments that are not finished yet (for the nightly check). */
	public static function open_consignments( $limit = 200, $offset = 0 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results( $wpdb->prepare( "SELECT order_id, courier, consignment FROM {$table} WHERE consignment <> '' AND outcome = '' ORDER BY order_id ASC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
	}

	/** Counts for the overview page. */
	public static function counts_between( $from, $to ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT outcome, consignment <> '' AS booked, COUNT(*) AS n FROM {$table} WHERE created_at >= %s AND created_at < %s GROUP BY outcome, booked", $from, $to ), ARRAY_A );
		$out  = array( 'orders' => 0, 'delivered' => 0, 'returned' => 0, 'in_courier' => 0 );
		foreach ( (array) $rows as $r ) {
			$n              = (int) $r['n'];
			$out['orders'] += $n;
			if ( in_array( $r['outcome'], array( 'delivered', 'partial' ), true ) ) {
				$out['delivered'] += $n;
			} elseif ( 'returned' === $r['outcome'] ) {
				$out['returned'] += $n;
			} elseif ( (int) $r['booked'] ) {
				$out['in_courier'] += $n;
			}
		}
		return $out;
	}

	/** Background backfill of existing orders, 200 at a time. */
	public static function backfill() {
		$cursor = (int) get_option( 'lvk_backfill_cursor', 0 );
		$ids    = wc_get_orders(
			array(
				'limit'   => 200,
				'offset'  => $cursor,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
				'type'    => 'shop_order',
				'status'  => array_keys( wc_get_order_statuses() ),
			)
		);
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			self::touch( $order );
			$status = $order->get_status();
			if ( 'completed' === $status ) {
				self::set( $id, array( 'outcome' => 'delivered' ) );
			} elseif ( 'lvk-returned' === $status ) {
				self::set( $id, array( 'outcome' => 'returned' ) );
			}
		}
		if ( count( $ids ) === 200 ) {
			update_option( 'lvk_backfill_cursor', $cursor + 200, false );
			wp_schedule_single_event( time() + 10, 'lvk_backfill' );
		} else {
			update_option( 'lvk_backfill_done', time(), false );
		}
	}
}
