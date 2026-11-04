<?php
/**
 * Ledger table: every taka in and out, dated when it really happened.
 *
 * @package Lavkhata
 */

namespace Lavkhata;

defined( 'ABSPATH' ) || exit;

class Ledger {

	/** Kinds that add money. Everything else is a cost. */
	const INCOME = array( 'revenue', 'advance_kept', 'other_income' );

	public static function kinds() {
		return array(
			'revenue'      => __( 'Sales (delivered orders)', 'lavkhata' ),
			'advance_kept' => __( 'Advance kept from returned orders', 'lavkhata' ),
			'other_income' => __( 'Other income', 'lavkhata' ),
			'cogs'         => __( 'Product cost', 'lavkhata' ),
			'delivery'     => __( 'Delivery charges', 'lavkhata' ),
			'cod_fee'      => __( 'COD collection fee', 'lavkhata' ),
			'return_fee'   => __( 'Return charges', 'lavkhata' ),
			'packaging'    => __( 'Packaging', 'lavkhata' ),
			'gateway_fee'  => __( 'Payment gateway fees', 'lavkhata' ),
			'ad_spend'     => __( 'Ad spend (with VAT)', 'lavkhata' ),
			'expense'      => __( 'Other expenses', 'lavkhata' ),
		);
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lvk_ledger';
	}

	public static function add( $order_id, $date, $kind, $amount, $note = '' ) {
		global $wpdb;
		$amount = round( (float) $amount, 2 );
		if ( 0.0 === $amount ) {
			return 0;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'order_id'   => (int) $order_id,
				'entry_date' => $date,
				'kind'       => sanitize_key( $kind ),
				'amount'     => $amount,
				'note'       => mb_substr( (string) $note, 0, 255 ),
			),
			array( '%d', '%s', '%s', '%f', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function clear_order( $order_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'order_id' => (int) $order_id ), array( '%d' ) ); // phpcs:ignore
	}

	public static function delete_entry( $id ) {
		global $wpdb;
		// Only manual entries (no order) can be deleted by hand.
		$wpdb->delete( self::table(), array( 'id' => (int) $id, 'order_id' => 0 ), array( '%d', '%d' ) ); // phpcs:ignore
	}

	public static function order_entries( $order_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", $order_id ), ARRAY_A );
	}

	/** Sum per kind between two dates (inclusive from, exclusive to). */
	public static function totals( $from, $to ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT kind, SUM(amount) AS total, COUNT(DISTINCT order_id) AS orders FROM {$table} WHERE entry_date >= %s AND entry_date < %s GROUP BY kind", $from, $to ), ARRAY_A );
		$out  = array_fill_keys( array_keys( self::kinds() ), 0.0 );
		foreach ( (array) $rows as $r ) {
			$out[ $r['kind'] ] = (float) $r['total'];
		}
		return $out;
	}

	public static function entries( $from, $to, $manual_only = false ) {
		global $wpdb;
		$table = self::table();
		$extra = $manual_only ? ' AND order_id = 0' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE entry_date >= %s AND entry_date < %s{$extra} ORDER BY entry_date ASC, id ASC", $from, $to ), ARRAY_A );
	}

	/** Orders that were delivered / returned in the period (from the ledger). */
	public static function settled_counts( $from, $to ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$delivered = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT order_id) FROM {$table} WHERE kind = 'revenue' AND order_id > 0 AND entry_date >= %s AND entry_date < %s", $from, $to ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$returned = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT order_id) FROM {$table} WHERE kind = 'return_fee' AND order_id > 0 AND entry_date >= %s AND entry_date < %s", $from, $to ) );
		return array( 'delivered' => $delivered, 'returned' => $returned );
	}

	/**
	 * Turn kind totals into a P&L summary.
	 *
	 * @param array $t Kind => total.
	 * @param int   $orders_placed Orders placed in the period (for cost per purchase).
	 */
	public static function summarize( array $t, $orders_placed = 0 ) {
		$g = static function ( $k ) use ( $t ) {
			return isset( $t[ $k ] ) ? (float) $t[ $k ] : 0.0;
		};
		$income      = $g( 'revenue' ) + $g( 'advance_kept' ) + $g( 'other_income' );
		$order_costs = $g( 'cogs' ) + $g( 'delivery' ) + $g( 'cod_fee' ) + $g( 'return_fee' ) + $g( 'packaging' ) + $g( 'gateway_fee' );
		$before_ads  = $income - $order_costs;
		$ads         = $g( 'ad_spend' );
		$net         = $before_ads - $ads - $g( 'expense' );

		$break_even = $orders_placed > 0 ? $before_ads / $orders_placed : null;
		$cpp        = $orders_placed > 0 && $ads > 0 ? $ads / $orders_placed : null;

		return array(
			'income'          => $income,
			'gross_profit'    => $income - $g( 'cogs' ),
			'order_costs'     => $order_costs,
			'profit_before_ads' => $before_ads,
			'ads'             => $ads,
			'expenses'        => $g( 'expense' ),
			'net'             => $net,
			'margin'          => $income > 0 ? $net / $income * 100 : 0,
			'break_even_cpp'  => $break_even,
			'actual_cpp'      => $cpp,
			'ads_profitable'  => null === $cpp || null === $break_even ? null : $cpp <= $break_even,
		);
	}

	/** Ad spend in taka including VAT. */
	public static function ad_amount_bdt( $amount, $currency, $vat_pct, $usd_rate ) {
		$amount = (float) $amount;
		if ( 'USD' === $currency ) {
			$amount *= (float) $usd_rate;
		}
		return round( $amount * ( 1 + (float) $vat_pct / 100 ), 2 );
	}
}
