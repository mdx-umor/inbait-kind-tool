<?php
/**
 * Orders screen: courier column, customer record badge, WhatsApp confirm,
 * bulk booking and labels, and a side box on the order page.
 * Works with both HPOS and legacy order storage.
 *
 * @package Lavkhata
 */

namespace Lavkhata\Admin;

use Lavkhata\Cod_Guard;
use Lavkhata\Courier_Sync;
use Lavkhata\Couriers\Courier;
use Lavkhata\Helpers;
use Lavkhata\Labels;
use Lavkhata\Ledger;
use Lavkhata\Settings;
use Lavkhata\Whatsapp_Confirm;

defined( 'ABSPATH' ) || exit;

class Orders {

	public static function init() {
		// HPOS screen.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column_hpos' ), 10, 2 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		// Legacy screen.
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column_legacy' ), 10, 2 );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( __CLASS__, 'handle_bulk' ), 10, 3 );

		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'wp_ajax_lvk_check_record', array( __CLASS__, 'ajax_record' ) );
	}

	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'order_status' === $k ) {
				$out['lvk_record']  = __( 'Customer record', 'lavkhata' );
				$out['lvk_courier'] = __( 'Courier', 'lavkhata' );
				$out['lvk_confirm'] = __( 'Confirm', 'lavkhata' );
			}
		}
		if ( ! isset( $out['lvk_courier'] ) ) {
			$out['lvk_record']  = __( 'Customer record', 'lavkhata' );
			$out['lvk_courier'] = __( 'Courier', 'lavkhata' );
			$out['lvk_confirm'] = __( 'Confirm', 'lavkhata' );
		}
		return $out;
	}

	public static function column_hpos( $column, $order ) {
		self::column( $column, $order instanceof \WC_Order ? $order : wc_get_order( $order ) );
	}

	public static function column_legacy( $column, $post_id ) {
		if ( 0 === strpos( $column, 'lvk_' ) ) {
			self::column( $column, wc_get_order( $post_id ) );
		}
	}

	private static function column( $column, $order ) {
		if ( ! $order ) {
			return;
		}
		switch ( $column ) {
			case 'lvk_record':
				echo self::record_badge( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped inside.
				break;
			case 'lvk_courier':
				self::courier_cell( $order );
				break;
			case 'lvk_confirm':
				self::confirm_cell( $order );
				break;
		}
	}

	/** Red / amber / green badge with parcels and delivery rate. */
	public static function record_badge( \WC_Order $order ) {
		$r = $order->get_meta( '_lvk_record' );
		if ( ! is_array( $r ) ) {
			if ( '' === Helpers::bd_phone( $order->get_billing_phone() ) ) {
				return '<span class="lvk-badge none">' . esc_html__( 'No valid phone', 'lavkhata' ) . '</span>';
			}
			return '<span class="lvk-rec"><button type="button" class="button button-small lvk-check" data-order="' . esc_attr( $order->get_id() ) . '">' . esc_html__( 'Check', 'lavkhata' ) . '</button></span>';
		}
		return self::badge_html( $r, $order->get_id() );
	}

	public static function badge_html( array $r, $order_id ) {
		if ( empty( $r['total'] ) ) {
			$tone = 'new';
			$text = __( 'New customer', 'lavkhata' );
		} else {
			$tone = 'risky' === $r['level'] ? 'bad' : ( $r['ratio'] >= 80 ? 'good' : 'warn' );
			/* translators: 1: parcels, 2: delivery rate */
			$text = sprintf( _n( '%1$d parcel · %2$s delivered', '%1$d parcels · %2$s delivered', (int) $r['total'], 'lavkhata' ), (int) $r['total'], Helpers::pct( $r['ratio'] ) );
		}
		$title = array();
		if ( ! empty( $r['sources'] ) ) {
			foreach ( $r['sources'] as $name => $s ) {
				if ( ! empty( $s['ok'] ) ) {
					$label   = 'store' === $name ? __( 'Your shop', 'lavkhata' ) : ucfirst( $name );
					$title[] = sprintf( '%s: %d/%d', $label, (int) $s['delivered'], (int) $s['total'] );
				} else {
					/* translators: %s: courier */
					$title[] = sprintf( __( '%s: not available', 'lavkhata' ), ucfirst( $name ) );
				}
			}
		}
		return sprintf(
			'<span class="lvk-rec"><span class="lvk-badge %1$s" title="%2$s">%3$s</span> <button type="button" class="lvk-recheck" data-order="%4$d" aria-label="%5$s">↻</button></span>',
			esc_attr( $tone ),
			esc_attr( implode( "\n", $title ) ),
			esc_html( $text ),
			(int) $order_id,
			esc_attr__( 'Check again', 'lavkhata' )
		);
	}

	private static function action_url( $action, $order_id, $args = array() ) {
		return add_query_arg( array_merge( array( 'action' => $action, 'order' => $order_id, '_wpnonce' => wp_create_nonce( $action ) ), $args ), admin_url( 'admin-post.php' ) );
	}

	private static function courier_cell( \WC_Order $order ) {
		$cid = (string) $order->get_meta( '_lvk_cid' );
		if ( '' !== $cid ) {
			$status  = (string) $order->get_meta( '_lvk_courier_status' );
			$courier = Courier::get( $order->get_meta( '_lvk_courier' ) );
			$tone    = in_array( $status, array( 'delivered', 'partial' ), true ) ? 'good' : ( in_array( $status, array( 'returned', 'cancelled' ), true ) ? 'bad' : 'info' );
			$url     = $courier ? $courier->tracking_url( $cid, $order->get_meta( '_lvk_tracking' ) ) : '';
			echo '<span class="lvk-chip ' . esc_attr( $tone ) . '">' . esc_html( ( $courier ? $courier->name() . ': ' : '' ) . Courier::status_label( $status ) ) . '</span><br>';
			echo $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $cid ) . '</a>' : esc_html( $cid );
			if ( in_array( $status, array( 'returned', 'cancelled' ), true ) ) {
				self::book_buttons( $order );
			}
			return;
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded', 'completed', 'lvk-returned', 'trash' ), true ) ) {
			echo '<span class="lvk-muted">—</span>';
			return;
		}
		self::book_buttons( $order );
	}

	private static function book_buttons( \WC_Order $order ) {
		$out = array();
		foreach ( Courier::all() as $id => $c ) {
			if ( $c->is_ready() ) {
				/* translators: %s: courier */
				$out[] = '<a class="button button-small lvk-book" href="' . esc_url( self::action_url( 'lvk_book', $order->get_id(), array( 'courier' => $id ) ) ) . '">' . esc_html( sprintf( __( 'Send to %s', 'lavkhata' ), $c->name() ) ) . '</a>';
			}
		}
		if ( ! $out ) {
			$out[] = '<a href="' . esc_url( admin_url( 'admin.php?page=lavkhata-settings&tab=couriers' ) ) . '">' . esc_html__( 'Connect a courier', 'lavkhata' ) . '</a>';
		}
		echo '<div class="lvk-book-row">' . implode( ' ', $out ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function confirm_cell( \WC_Order $order ) {
		if ( $order->get_meta( '_lvk_confirmed' ) ) {
			echo '<span class="lvk-chip good">' . esc_html__( 'Confirmed', 'lavkhata' ) . '</span>';
			return;
		}
		if ( ! Settings::get( 'wa_enabled' ) || in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded', 'completed', 'lvk-returned', 'lvk-shipped' ), true ) ) {
			echo '<span class="lvk-muted">—</span>';
			return;
		}
		$link = Whatsapp_Confirm::wa_link( $order );
		if ( $link ) {
			echo '<a class="button button-small lvk-wa" target="_blank" rel="noopener" href="' . esc_url( $link ) . '">' . esc_html__( 'WhatsApp', 'lavkhata' ) . '</a>';
		}
	}

	public static function bulk_actions( $actions ) {
		foreach ( Courier::all() as $id => $c ) {
			if ( $c->is_ready() ) {
				/* translators: %s: courier */
				$actions[ 'lvk_book_' . $id ] = sprintf( __( 'Send to %s', 'lavkhata' ), $c->name() );
			}
		}
		$actions['lvk_labels']  = __( 'Print shipping labels', 'lavkhata' );
		$actions['lvk_refresh'] = __( 'Check courier status', 'lavkhata' );
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 0 !== strpos( (string) $action, 'lvk_' ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect;
		}
		$ids = array_map( 'absint', (array) $ids );
		if ( 'lvk_labels' === $action ) {
			wp_safe_redirect( Labels::url( $ids ) );
			exit;
		}
		$ok   = 0;
		$errs = array();
		foreach ( array_slice( $ids, 0, 100 ) as $id ) {
			if ( 'lvk_refresh' === $action ) {
				$res = Courier_Sync::refresh( $id );
			} else {
				$res = Courier_Sync::book( $id, substr( $action, strlen( 'lvk_book_' ) ) );
			}
			if ( $res['ok'] ) {
				++$ok;
			} else {
				$errs[] = $res['message'];
			}
		}
		/* translators: %d: count */
		Admin::notice( sprintf( _n( '%d order done.', '%d orders done.', $ok, 'lavkhata' ), $ok ) );
		foreach ( array_slice( $errs, 0, 10 ) as $e ) {
			Admin::notice( $e, 'error' );
		}
		return $redirect;
	}

	public static function ajax_record() {
		check_ajax_referer( 'lvk_ajax' );
		$order = isset( $_POST['order'] ) ? wc_get_order( absint( $_POST['order'] ) ) : null;
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error();
		}
		$a = Cod_Guard::assess( $order->get_billing_phone(), $order->get_id(), true );
		if ( ! $a ) {
			wp_send_json_error();
		}
		Cod_Guard::store_record( $order, $a );
		wp_send_json_success( array( 'html' => self::badge_html( $a, $order->get_id() ) ) );
	}

	/* ---------- Order page side box ---------- */

	public static function meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'lvk-order', __( 'Lavkhata', 'lavkhata' ), array( __CLASS__, 'render_box' ), $screen, 'side', 'high' );
	}

	public static function render_box( $post_or_order ) {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		echo '<div class="lvk-box">';

		echo '<h4>' . esc_html__( 'Customer record', 'lavkhata' ) . '</h4>';
		echo self::record_badge( $order ); // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<h4>' . esc_html__( 'Courier', 'lavkhata' ) . '</h4>';
		$cid = (string) $order->get_meta( '_lvk_cid' );
		if ( '' !== $cid ) {
			self::courier_cell( $order );
			$msg = (string) $order->get_meta( '_lvk_tracking_msg' );
			if ( $msg ) {
				echo '<p class="lvk-muted">' . esc_html( $msg ) . '</p>';
			}
			echo '<p><a class="button button-small" href="' . esc_url( self::action_url( 'lvk_refresh', $order->get_id() ) ) . '">' . esc_html__( 'Check status now', 'lavkhata' ) . '</a></p>';
		} else {
			self::courier_cell( $order );
		}
		/* translators: %s: amount */
		echo '<p>' . esc_html( sprintf( __( 'COD to collect: %s', 'lavkhata' ), Helpers::taka( Helpers::cod_amount( $order ) ) ) ) . '</p>';
		echo '<p><a class="button button-small" target="_blank" href="' . esc_url( Labels::url( array( $order->get_id() ) ) ) . '">' . esc_html__( 'Print label', 'lavkhata' ) . '</a></p>';

		if ( 'lvk_advance' === $order->get_payment_method() ) {
			echo '<h4>' . esc_html__( 'Advance payment', 'lavkhata' ) . '</h4>';
			/* translators: 1: amount, 2: sender, 3: trx */
			echo '<p>' . esc_html( sprintf( __( '%1$s from %2$s, TrxID %3$s', 'lavkhata' ), Helpers::taka( $order->get_meta( '_lvk_advance_amount' ) ), $order->get_meta( '_lvk_advance_sender' ), $order->get_meta( '_lvk_advance_trx' ) ) ) . '</p>';
			if ( $order->get_meta( '_lvk_advance_verified' ) ) {
				echo '<span class="lvk-chip good">' . esc_html__( 'Verified', 'lavkhata' ) . '</span>';
			} else {
				echo '<a class="button button-small button-primary" href="' . esc_url( self::action_url( 'lvk_verify_advance', $order->get_id() ) ) . '">' . esc_html__( 'I received it', 'lavkhata' ) . '</a>';
			}
		}

		if ( Settings::get( 'wa_enabled' ) ) {
			echo '<h4>' . esc_html__( 'Confirmation', 'lavkhata' ) . '</h4>';
			self::confirm_cell( $order );
		}

		$entries = Ledger::order_entries( $order->get_id() );
		if ( $entries ) {
			$kinds = Ledger::kinds();
			echo '<h4>' . esc_html__( 'Real profit', 'lavkhata' ) . '</h4><table class="lvk-kv">';
			foreach ( $entries as $e ) {
				$v = in_array( $e['kind'], Ledger::INCOME, true ) ? (float) $e['amount'] : -(float) $e['amount'];
				printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html( isset( $kinds[ $e['kind'] ] ) ? $kinds[ $e['kind'] ] : $e['kind'] ), esc_html( Helpers::taka( $v, 2 ) ) );
			}
			$profit = (float) $order->get_meta( '_lvk_profit' );
			printf( '<tr class="lvk-pnl-total %3$s"><th>%1$s</th><td>%2$s</td></tr></table>', esc_html__( 'Profit', 'lavkhata' ), esc_html( Helpers::taka( $profit, 2 ) ), $profit >= 0 ? 'good' : 'bad' );
			if ( (int) $order->get_meta( '_lvk_cost_missing' ) > 0 ) {
				echo '<p class="lvk-warn-text">' . esc_html__( 'Some products have no cost price, so profit looks higher than it is.', 'lavkhata' ) . '</p>';
			}
		}
		echo '</div>';
	}
}
