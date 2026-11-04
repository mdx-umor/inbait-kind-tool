<?php
/**
 * Booking parcels and keeping WooCommerce orders in sync with the courier.
 *
 * @package Lavkhata
 */

namespace Lavkhata;

use Lavkhata\Couriers\Courier;
use Lavkhata\Couriers\Pathao;
use Lavkhata\Couriers\Steadfast;

defined( 'ABSPATH' ) || exit;

class Courier_Sync {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'lvk_nightly_sync', array( __CLASS__, 'nightly' ) );
		add_action( 'lvk_sync_batch', array( __CLASS__, 'sync_batch' ), 10, 1 );
	}

	/**
	 * Book an order with a courier.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function book( $order, $courier_id ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return array( 'ok' => false, 'message' => __( 'Order not found.', 'lavkhata' ) );
		}
		$courier = Courier::get( $courier_id );
		if ( ! $courier ) {
			return array( 'ok' => false, 'message' => __( 'Unknown courier.', 'lavkhata' ) );
		}
		$current = (string) $order->get_meta( '_lvk_cid' );
		$cstatus = (string) $order->get_meta( '_lvk_courier_status' );
		if ( '' !== $current && ! in_array( $cstatus, array( 'cancelled', 'returned' ), true ) ) {
			/* translators: 1: order number, 2: consignment id */
			return array( 'ok' => false, 'message' => sprintf( __( 'Order #%1$s is already booked (consignment %2$s).', 'lavkhata' ), $order->get_order_number(), $current ) );
		}

		$res = $courier->book( $order );
		if ( empty( $res['ok'] ) ) {
			$order->save();
			/* translators: 1: courier, 2: error */
			$order->add_order_note( sprintf( __( '%1$s booking failed: %2$s', 'lavkhata' ), $courier->name(), $res['error'] ) );
			/* translators: 1: order number, 2: error */
			return array( 'ok' => false, 'message' => sprintf( __( 'Order #%1$s: %2$s', 'lavkhata' ), $order->get_order_number(), $res['error'] ) );
		}

		$order->update_meta_data( '_lvk_courier', $courier->id() );
		$order->update_meta_data( '_lvk_cid', $res['consignment'] );
		$order->update_meta_data( '_lvk_tracking', isset( $res['tracking'] ) ? $res['tracking'] : '' );
		$order->update_meta_data( '_lvk_courier_status', 'booked' );
		$order->update_meta_data( '_lvk_courier_raw', 'booked' );
		$order->update_meta_data( '_lvk_cod_booked', Helpers::cod_amount( $order ) );
		if ( isset( $res['charge'] ) && null !== $res['charge'] ) {
			$order->update_meta_data( '_lvk_delivery_charge', (float) $res['charge'] );
		}
		$order->save();

		Index::set(
			$order->get_id(),
			array(
				'courier'        => $courier->id(),
				'consignment'    => $res['consignment'],
				'tracking'       => isset( $res['tracking'] ) ? $res['tracking'] : '',
				'courier_status' => 'booked',
				'outcome'        => '',
			)
		);

		/* translators: 1: courier, 2: consignment id, 3: COD amount */
		$order->add_order_note( sprintf( __( 'Booked with %1$s. Consignment %2$s. COD to collect: %3$s.', 'lavkhata' ), $courier->name(), $res['consignment'], Helpers::taka( Helpers::cod_amount( $order ) ) ) );

		if ( Settings::is_auto() && in_array( $order->get_status(), array( 'pending', 'processing', 'on-hold' ), true ) ) {
			$order->update_status( 'lvk-shipped', __( 'Handed to courier.', 'lavkhata' ) );
		}
		/* translators: 1: order number, 2: courier, 3: consignment */
		return array( 'ok' => true, 'message' => sprintf( __( 'Order #%1$s booked with %2$s (%3$s).', 'lavkhata' ), $order->get_order_number(), $courier->name(), $res['consignment'] ) );
	}

	/** Ask the courier for the latest status of one order. */
	public static function refresh( $order ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order || '' === (string) $order->get_meta( '_lvk_cid' ) ) {
			return array( 'ok' => false, 'message' => __( 'This order is not booked with a courier.', 'lavkhata' ) );
		}
		$courier = Courier::get( $order->get_meta( '_lvk_courier' ) );
		if ( ! $courier ) {
			return array( 'ok' => false, 'message' => __( 'Unknown courier.', 'lavkhata' ) );
		}
		$res = $courier->status( $order->get_meta( '_lvk_cid' ) );
		if ( empty( $res['ok'] ) ) {
			return array( 'ok' => false, 'message' => $res['error'] );
		}
		self::apply( $order, $res['status'], $res['raw'] );
		/* translators: %s: status */
		return array( 'ok' => true, 'message' => sprintf( __( 'Courier status: %s', 'lavkhata' ), Courier::status_label( $res['status'] ) ) );
	}

	/**
	 * Apply a courier status to an order.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $status Normalised status.
	 * @param string    $raw    The courier's own status text.
	 * @param array     $extra  collected (float), charge (float).
	 */
	public static function apply( \WC_Order $order, $status, $raw = '', array $extra = array() ) {
		$previous = (string) $order->get_meta( '_lvk_courier_status' );
		if ( isset( $extra['charge'] ) && $extra['charge'] > 0 ) {
			$order->update_meta_data( '_lvk_delivery_charge', (float) $extra['charge'] );
		}
		if ( isset( $extra['collected'] ) && null !== $extra['collected'] ) {
			$order->update_meta_data( '_lvk_collected', (float) $extra['collected'] );
		}
		$order->update_meta_data( '_lvk_courier_status', $status );
		$order->update_meta_data( '_lvk_courier_raw', (string) $raw );
		$order->update_meta_data( '_lvk_courier_checked', time() );
		$order->save();

		$fields = array( 'courier_status' => substr( (string) ( $raw ? $raw : $status ), 0, 60 ) );
		$outcome = self::outcome_for( $status );
		if ( null !== $outcome ) {
			$fields['outcome'] = $outcome;
		}
		Index::set( $order->get_id(), $fields );

		if ( $previous === $status ) {
			return;
		}

		// Money and Meta follow the courier's truth in both modes.
		if ( in_array( $status, array( 'delivered', 'partial' ), true ) ) {
			Profit::finalize( $order, $status );
			Meta_Capi::on_delivered( $order );
		} elseif ( 'returned' === $status ) {
			Profit::finalize( $order, 'returned' );
		} elseif ( 'cancelled' === $status ) {
			Ledger::clear_order( $order->get_id() );
		}

		$target = self::wc_status_for( $status, $order->get_status() );
		$label  = Courier::status_label( $status );
		if ( ! $target ) {
			/* translators: %s: courier status */
			$order->add_order_note( sprintf( __( 'Courier update: %s', 'lavkhata' ), $label . ( $raw ? " ({$raw})" : '' ) ) );
			return;
		}
		if ( Settings::is_auto() ) {
			/* translators: %s: courier status */
			$order->update_status( $target, sprintf( __( 'Courier update: %s.', 'lavkhata' ), $label ) );
		} else {
			$statuses = wc_get_order_statuses();
			$name     = isset( $statuses[ 'wc-' . $target ] ) ? $statuses[ 'wc-' . $target ] : $target;
			/* translators: 1: courier status, 2: order status */
			$order->add_order_note( sprintf( __( 'Courier update: %1$s. Report-only mode is on, so the order was not changed. With Auto-update it would be marked "%2$s".', 'lavkhata' ), $label, $name ) );
		}
	}

	/** Courier status → outcome stored in the index (null = keep). */
	public static function outcome_for( $status ) {
		switch ( $status ) {
			case 'delivered':
				return 'delivered';
			case 'partial':
				return 'partial';
			case 'returned':
				return 'returned';
			case 'cancelled':
				return 'cancelled';
			default:
				return '';
		}
	}

	/** Courier status → WooCommerce status, or '' when nothing should change. */
	public static function wc_status_for( $status, $current ) {
		switch ( $status ) {
			case 'delivered':
			case 'partial':
				return 'completed' === $current ? '' : 'completed';
			case 'returned':
				return 'lvk-returned' === $current ? '' : 'lvk-returned';
			case 'cancelled':
				return in_array( $current, array( 'cancelled', 'refunded' ), true ) ? '' : 'cancelled';
			default:
				return in_array( $current, array( 'pending', 'processing', 'on-hold' ), true ) ? 'lvk-shipped' : '';
		}
	}

	public static function routes() {
		register_rest_route(
			'lavkhata/v1',
			'/steadfast',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'steadfast_webhook' ),
				'permission_callback' => '__return_true', // Checked with the token inside.
			)
		);
		register_rest_route(
			'lavkhata/v1',
			'/pathao',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'pathao_webhook' ),
				'permission_callback' => '__return_true', // Checked with the secret inside.
			)
		);
	}

	public static function steadfast_webhook( \WP_REST_Request $request ) {
		if ( ! Steadfast::verify_webhook( $request->get_header( 'authorization' ), $request->get_header( 'x_signature' ), $request->get_body() ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Unauthorized' ), 401 );
		}
		$p    = $request->get_json_params();
		$p    = is_array( $p ) ? $p : $request->get_body_params();
		$type = isset( $p['notification_type'] ) ? (string) $p['notification_type'] : '';
		$order = self::find_order( isset( $p['consignment_id'] ) ? $p['consignment_id'] : '', isset( $p['invoice'] ) ? $p['invoice'] : '' );
		if ( ! $order ) {
			return new \WP_REST_Response( array( 'status' => 'success', 'message' => 'Order not found, ignored.' ), 200 );
		}
		if ( 'delivery_status' === $type && ! empty( $p['status'] ) ) {
			$status = Steadfast::normalize( $p['status'] );
			$extra  = array();
			if ( isset( $p['delivery_charge'] ) && is_numeric( $p['delivery_charge'] ) ) {
				$extra['charge'] = (float) $p['delivery_charge'];
			}
			if ( 'partial' === $status && isset( $p['cod_amount'] ) && is_numeric( $p['cod_amount'] ) ) {
				$extra['collected'] = (float) $p['cod_amount'];
			}
			self::apply( $order, $status, (string) $p['status'], $extra );
		} elseif ( 'tracking_update' === $type && ! empty( $p['tracking_message'] ) ) {
			$order->update_meta_data( '_lvk_tracking_msg', sanitize_text_field( $p['tracking_message'] ) );
			$order->save();
		}
		return new \WP_REST_Response( array( 'status' => 'success', 'message' => 'Webhook received successfully.' ), 200 );
	}

	public static function pathao_webhook( \WP_REST_Request $request ) {
		$secret = (string) Settings::get( 'pathao_webhook_secret' );
		$p      = $request->get_json_params();
		$p      = is_array( $p ) ? $p : array();
		$event  = isset( $p['event'] ) ? (string) $p['event'] : '';

		$reply = static function ( $body, $code ) use ( $secret ) {
			$r = new \WP_REST_Response( $body, $code );
			$r->header( 'X-Pathao-Merchant-Webhook-Integration-Secret', $secret );
			return $r;
		};

		// Pathao checks the URL first with this event.
		if ( 'webhook_integration' === $event ) {
			return $reply( array( 'status' => 202, 'message' => 'Webhook integration successful' ), 202 );
		}
		if ( ! Pathao::verify_webhook( $request->get_header( 'x_pathao_signature' ) ) ) {
			return new \WP_REST_Response( array( 'message' => 'Unauthorized' ), 401 );
		}
		$order = self::find_order( isset( $p['consignment_id'] ) ? $p['consignment_id'] : '', isset( $p['merchant_order_id'] ) ? $p['merchant_order_id'] : '' );
		// "order.created" / "order.updated" carry no new delivery status.
		if ( $order && '' !== $event && ! in_array( $event, array( 'order.created', 'order.updated' ), true ) ) {
			$status = Pathao::normalize( $event );
			$extra  = array();
			if ( isset( $p['delivery_fee'] ) && is_numeric( $p['delivery_fee'] ) ) {
				$extra['charge'] = (float) $p['delivery_fee'];
			}
			if ( 'partial' === $status && isset( $p['collected_amount'] ) && is_numeric( $p['collected_amount'] ) ) {
				$extra['collected'] = (float) $p['collected_amount'];
			}
			self::apply( $order, $status, $event, $extra );
		}
		return $reply( array( 'status' => 202, 'message' => 'Webhook received successfully' ), 202 );
	}

	/** Find an order by consignment id, falling back to our invoice ("123" or "123-2"). */
	public static function find_order( $consignment, $invoice ) {
		$id = $consignment ? Index::find_by_consignment( $consignment ) : 0;
		if ( ! $id && $invoice && preg_match( '/^(\d+)/', (string) $invoice, $m ) ) {
			$id = (int) $m[1];
		}
		$order = $id ? wc_get_order( $id ) : null;
		if ( $order && $consignment && (string) $order->get_meta( '_lvk_cid' ) !== (string) $consignment ) {
			// Invoice matched but consignment differs: an old booking. Ignore.
			return null;
		}
		return $order ? $order : null;
	}

	/** Nightly full check: queue batches of 50. */
	public static function nightly() {
		wp_schedule_single_event( time() + 5, 'lvk_sync_batch', array( 0 ) );
	}

	public static function sync_batch( $offset ) {
		$offset = (int) $offset;
		$rows   = Index::open_consignments( 50, $offset );
		foreach ( $rows as $row ) {
			$order = wc_get_order( (int) $row['order_id'] );
			if ( $order ) {
				self::refresh( $order );
			}
		}
		// Rows that became final drop out of the list, so only advance by the unfinished ones.
		if ( count( $rows ) === 50 && $offset < 2000 ) {
			$still_open = 0;
			foreach ( $rows as $row ) {
				$r = Index::get( (int) $row['order_id'] );
				if ( $r && '' === $r['outcome'] ) {
					++$still_open;
				}
			}
			wp_schedule_single_event( time() + 30, 'lvk_sync_batch', array( $offset + $still_open ) );
		}
		update_option( 'lvk_last_sync', time(), false );
	}
}
