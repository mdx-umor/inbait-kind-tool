<?php
/**
 * Restock planner: days of stock left, when to reorder and how many.
 *
 * @package Lavkhata
 */

namespace Lavkhata;

defined( 'ABSPATH' ) || exit;

class Restock {

	/**
	 * Pure calculation for one product.
	 *
	 * @param int $stock    Units in stock.
	 * @param int $sold     Units sold in the lookback window.
	 * @param int $lookback Days in the window.
	 * @param int $lead     Supplier lead time in days.
	 * @param int $cover    Days of stock to buy for.
	 */
	public static function plan( $stock, $sold, $lookback, $lead, $cover ) {
		$stock    = (int) $stock;
		$lookback = max( 1, (int) $lookback );
		$per_day  = max( 0, (float) $sold ) / $lookback;
		if ( $per_day <= 0 ) {
			return array( 'per_day' => 0.0, 'days_left' => null, 'reorder_in' => null, 'qty' => 0, 'status' => $stock <= 0 ? 'out' : 'no_sales' );
		}
		$days_left  = $stock > 0 ? $stock / $per_day : 0.0;
		$reorder_in = $days_left - (int) $lead;
		$qty        = (int) max( 0, ceil( $per_day * ( (int) $lead + (int) $cover ) - max( 0, $stock ) ) );
		if ( $stock <= 0 ) {
			$status = 'out';
		} elseif ( $reorder_in <= 0 ) {
			$status = 'now';
		} elseif ( $reorder_in <= 7 ) {
			$status = 'soon';
		} else {
			$status = 'ok';
		}
		return array(
			'per_day'    => round( $per_day, 2 ),
			'days_left'  => round( $days_left, 1 ),
			'reorder_in' => round( $reorder_in, 1 ),
			'qty'        => $qty,
			'status'     => $status,
		);
	}

	/** Units sold per product/variation id in the last N days (cached 1 hour). */
	public static function sales( $days ) {
		$key    = 'lvk_restock_sales_' . (int) $days;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$sold = array();
		$page = 1;
		do {
			$orders = wc_get_orders(
				array(
					'limit'        => 200,
					'paged'        => $page,
					'type'         => 'shop_order',
					'status'       => array( 'processing', 'completed', 'on-hold', 'lvk-shipped' ),
					'date_created' => '>' . ( time() - (int) $days * DAY_IN_SECONDS ),
				)
			);
			foreach ( $orders as $order ) {
				foreach ( $order->get_items() as $item ) {
					$id          = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
					$sold[ $id ] = ( isset( $sold[ $id ] ) ? $sold[ $id ] : 0 ) + (int) $item->get_quantity();
				}
			}
			++$page;
		} while ( count( $orders ) === 200 && $page < 50 );
		set_transient( $key, $sold, HOUR_IN_SECONDS );
		return $sold;
	}

	/** Rows for every product that manages stock, most urgent first. */
	public static function rows() {
		$lookback = (int) Settings::get( 'restock_lookback' );
		$lead     = (int) Settings::get( 'restock_lead' );
		$cover    = (int) Settings::get( 'restock_cover' );
		$sold     = self::sales( $lookback );
		$rows     = array();
		$ids      = wc_get_products(
			array(
				'limit'        => 2000,
				'status'       => 'publish',
				'type'         => array( 'simple', 'variable' ),
				'return'       => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$items = $p->is_type( 'variable' ) ? $p->get_children() : array( $id );
			foreach ( $items as $child_id ) {
				$c = $child_id === $id ? $p : wc_get_product( $child_id );
				if ( ! $c || ! $c->managing_stock() ) {
					continue;
				}
				$n    = isset( $sold[ $child_id ] ) ? $sold[ $child_id ] : 0;
				$plan = self::plan( (int) $c->get_stock_quantity(), $n, $lookback, $lead, $cover );
				$rows[ $child_id ] = array_merge(
					$plan,
					array(
						'id'    => $child_id,
						'name'  => $c->get_formatted_name(),
						'sku'   => $c->get_sku(),
						'stock' => (int) $c->get_stock_quantity(),
						'sold'  => $n,
						'edit'  => get_edit_post_link( $c->get_parent_id() ? $c->get_parent_id() : $c->get_id(), 'raw' ),
					)
				);
			}
		}
		$order = array( 'out' => 0, 'now' => 1, 'soon' => 2, 'ok' => 3, 'no_sales' => 4 );
		uasort(
			$rows,
			static function ( $a, $b ) use ( $order ) {
				$s = $order[ $a['status'] ] <=> $order[ $b['status'] ];
				if ( 0 !== $s ) {
					return $s;
				}
				return ( null === $a['days_left'] ? PHP_INT_MAX : $a['days_left'] ) <=> ( null === $b['days_left'] ? PHP_INT_MAX : $b['days_left'] );
			}
		);
		return array_values( $rows );
	}

	public static function status_label( $s ) {
		$labels = array(
			'out'      => __( 'Out of stock', 'lavkhata' ),
			'now'      => __( 'Reorder now', 'lavkhata' ),
			'soon'     => __( 'Reorder this week', 'lavkhata' ),
			'ok'       => __( 'OK', 'lavkhata' ),
			'no_sales' => __( 'No recent sales', 'lavkhata' ),
		);
		return isset( $labels[ $s ] ) ? $labels[ $s ] : $s;
	}
}
