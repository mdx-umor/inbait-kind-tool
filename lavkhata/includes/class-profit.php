<?php
/**
 * Real profit per order: counted only when the courier delivers or returns it.
 *
 * @package Lavkhata
 */

namespace Lavkhata;

defined( 'ABSPATH' ) || exit;

class Profit {

	public static function init() {
		// Save each item's cost at the moment of sale, so later price changes don't rewrite history.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'snapshot_cost' ), 10, 3 );
		// Manual status changes also settle the books.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refund' ), 20, 1 );
	}

	public static function snapshot_cost( $item, $cart_item_key, $values ) {
		$product = $item->get_product();
		if ( $product ) {
			$cost = self::product_cost( $product );
			if ( null !== $cost ) {
				$item->add_meta_data( '_lvk_unit_cost', $cost, true );
			}
		}
	}

	/** Cost price of a product (variation falls back to parent). */
	public static function product_cost( $product ) {
		$cost = $product->get_meta( '_lvk_cost' );
		if ( '' === $cost && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$cost   = $parent ? $parent->get_meta( '_lvk_cost' ) : '';
		}
		return '' === $cost || null === $cost ? null : (float) $cost;
	}

	/** Product cost of an order, and how many lines have no cost set. */
	public static function order_cogs( \WC_Order $order ) {
		$cogs    = 0.0;
		$missing = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			$qty  = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item_id );
			$unit = $item->get_meta( '_lvk_unit_cost' );
			if ( '' === $unit ) {
				$product = $item->get_product();
				$unit    = $product ? self::product_cost( $product ) : null;
			}
			if ( null === $unit || '' === $unit ) {
				++$missing;
				continue;
			}
			$cogs += (float) $unit * max( 0, $qty );
		}
		return array( 'cogs' => $cogs, 'missing' => $missing );
	}

	public static function delivery_charge( \WC_Order $order ) {
		$charge = $order->get_meta( '_lvk_delivery_charge' );
		if ( '' !== $charge && null !== $charge ) {
			return (float) $charge;
		}
		return (float) ( Helpers::is_inside_dhaka( $order ) ? Settings::get( 'cost_delivery_inside' ) : Settings::get( 'cost_delivery_outside' ) );
	}

	/**
	 * Pure profit calculation. Every amount is positive; the kind says whether
	 * it is income or a cost (see Ledger::INCOME).
	 *
	 * @param array $in outcome (delivered|partial|returned), total, cogs, prepaid,
	 *                  cod (amount the courier should collect), collected (partial only),
	 *                  delivery_charge, is_advance (bool).
	 * @param array $s  Cost settings.
	 * @return array kind => amount
	 */
	public static function breakdown( array $in, array $s ) {
		$in = array_merge(
			array( 'outcome' => 'delivered', 'total' => 0, 'cogs' => 0, 'prepaid' => 0, 'cod' => 0, 'collected' => null, 'delivery_charge' => 0, 'is_advance' => false ),
			$in
		);
		$cod_pct  = (float) $s['cost_cod_fee_pct'] / 100;
		$gw_pct   = (float) $s['cost_gateway_fee_pct'] / 100;
		$prepaid  = max( 0.0, (float) $in['prepaid'] );
		$lines    = array();

		if ( 'returned' === $in['outcome'] ) {
			$lines['delivery']   = (float) $in['delivery_charge'];
			$lines['return_fee'] = (float) $in['delivery_charge'] * (float) $s['cost_return_pct'] / 100;
			$lines['packaging']  = (float) $s['cost_packaging'];
			if ( $in['is_advance'] && $prepaid > 0 && ! empty( $s['advance_kept_on_return'] ) ) {
				$lines['advance_kept'] = $prepaid;
				$lines['gateway_fee']  = $prepaid * $gw_pct;
			}
			return self::round_lines( $lines );
		}

		$total = max( 0.0, (float) $in['total'] );
		if ( 'partial' === $in['outcome'] && null !== $in['collected'] ) {
			$collected = max( 0.0, (float) $in['collected'] );
			$revenue   = min( $total, $prepaid + $collected );
			$share     = $total > 0 ? $revenue / $total : 1;
			$cogs      = (float) $in['cogs'] * $share;
		} else {
			$collected = max( 0.0, (float) $in['cod'] );
			$revenue   = $total;
			$cogs      = (float) $in['cogs'];
		}

		$lines['revenue']     = $revenue;
		$lines['cogs']        = $cogs;
		$lines['delivery']    = (float) $in['delivery_charge'];
		$lines['cod_fee']     = $collected * $cod_pct;
		$lines['packaging']   = (float) $s['cost_packaging'];
		$lines['gateway_fee'] = $prepaid * $gw_pct;
		return self::round_lines( $lines );
	}

	private static function round_lines( array $lines ) {
		$out = array();
		foreach ( $lines as $k => $v ) {
			$v = round( (float) $v, 2 );
			if ( $v > 0 ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/** Profit of a breakdown (income minus costs). */
	public static function net_of( array $lines ) {
		$net = 0.0;
		foreach ( $lines as $k => $v ) {
			$net += in_array( $k, Ledger::INCOME, true ) ? $v : -$v;
		}
		return round( $net, 2 );
	}

	/** Write the order's real result to the ledger (idempotent). */
	public static function finalize( \WC_Order $order, $outcome ) {
		$cogs    = self::order_cogs( $order );
		$total   = (float) $order->get_total() - (float) $order->get_total_refunded();
		$collected = $order->get_meta( '_lvk_collected' );
		$in = array(
			'outcome'         => $outcome,
			'total'           => $total,
			'cogs'            => $cogs['cogs'],
			'prepaid'         => Helpers::prepaid_amount( $order ),
			'cod'             => Helpers::cod_amount( $order ),
			'collected'       => '' === $collected ? null : (float) $collected,
			'delivery_charge' => self::delivery_charge( $order ),
			'is_advance'      => 'lvk_advance' === $order->get_payment_method(),
		);
		$lines = self::breakdown( $in, Settings::all() );

		Ledger::clear_order( $order->get_id() );
		$date = current_time( 'Y-m-d' );
		/* translators: %s: order number */
		$note = sprintf( __( 'Order #%s', 'lavkhata' ), $order->get_order_number() );
		foreach ( $lines as $kind => $amount ) {
			Ledger::add( $order->get_id(), $date, $kind, $amount, $note );
		}
		$order->update_meta_data( '_lvk_profit', self::net_of( $lines ) );
		$order->update_meta_data( '_lvk_cost_missing', $cogs['missing'] );
		$order->update_meta_data( '_lvk_settled', $outcome . '|' . $date );
		$order->save();
	}

	/** Manual status changes (no courier, or courier booked outside the plugin). */
	public static function on_status_changed( $order_id, $from, $to, $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$row     = Index::get( $order_id );
		$outcome = $row ? (string) $row['outcome'] : '';
		if ( 'completed' === $to && ! in_array( $outcome, array( 'delivered', 'partial' ), true ) ) {
			Index::set( $order_id, array( 'outcome' => 'delivered' ) );
			self::finalize( $order, 'delivered' );
			Meta_Capi::on_delivered( $order );
		} elseif ( 'lvk-returned' === $to && 'returned' !== $outcome ) {
			Index::set( $order_id, array( 'outcome' => 'returned' ) );
			self::finalize( $order, 'returned' );
		} elseif ( in_array( $to, array( 'cancelled', 'failed' ), true ) && in_array( $from, array( 'completed', 'lvk-returned' ), true ) ) {
			// Undo a settled order that was cancelled by hand.
			Index::set( $order_id, array( 'outcome' => 'cancelled' ) );
			Ledger::clear_order( $order_id );
			$order->delete_meta_data( '_lvk_profit' );
			$order->delete_meta_data( '_lvk_settled' );
			$order->save();
		}
	}

	public static function on_refund( $order_id ) {
		$order = wc_get_order( $order_id );
		$row   = Index::get( $order_id );
		if ( $order && $row && in_array( $row['outcome'], array( 'delivered', 'partial' ), true ) ) {
			self::finalize( $order, $row['outcome'] );
		}
	}
}
