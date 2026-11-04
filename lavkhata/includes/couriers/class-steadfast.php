<?php
/**
 * Steadfast Courier API (portal.packzy.com).
 *
 * @package Lavkhata
 */

namespace Lavkhata\Couriers;

use Lavkhata\Helpers;
use Lavkhata\Settings;

defined( 'ABSPATH' ) || exit;

class Steadfast extends Courier {

	const BASE = 'https://portal.packzy.com/api/v1';

	public function id() {
		return 'steadfast';
	}

	public function name() {
		return 'Steadfast';
	}

	public function is_ready() {
		return Settings::steadfast_ready();
	}

	private function headers() {
		return array(
			'Api-Key'    => (string) Settings::get( 'steadfast_api_key' ),
			'Secret-Key' => (string) Settings::get( 'steadfast_secret_key' ),
		);
	}

	public function book( \WC_Order $order ) {
		if ( ! $this->is_ready() ) {
			return array( 'ok' => false, 'error' => __( 'Add your Steadfast API key and secret key in Lavkhata settings.', 'lavkhata' ) );
		}
		$phone = Helpers::bd_phone( $order->get_billing_phone() );
		if ( '' === $phone ) {
			return array( 'ok' => false, 'error' => __( 'The customer phone number is not a valid Bangladeshi mobile number.', 'lavkhata' ) );
		}
		$attempt = (int) $order->get_meta( '_lvk_book_attempt' ) + 1;
		$invoice = (string) $order->get_order_number() . ( $attempt > 1 ? '-' . $attempt : '' );

		$payload = array(
			'invoice'           => $invoice,
			'recipient_name'    => mb_substr( Helpers::customer_name( $order ), 0, 100 ),
			'recipient_phone'   => $phone,
			'recipient_address' => mb_substr( Helpers::address_line( $order ), 0, 250 ),
			'cod_amount'        => Helpers::cod_amount( $order ),
			'note'              => mb_substr( (string) $order->get_customer_note(), 0, 250 ),
			'item_description'  => Helpers::items_text( $order ),
		);
		$alt = Helpers::bd_phone( $order->get_shipping_phone() );
		if ( $alt && $alt !== $phone ) {
			$payload['alternative_phone'] = $alt;
		}

		$res = $this->request( 'POST', self::BASE . '/create_order', $this->headers(), $payload );
		$order->update_meta_data( '_lvk_book_attempt', $attempt );

		$c = isset( $res['body']['consignment'] ) && is_array( $res['body']['consignment'] ) ? $res['body']['consignment'] : array();
		if ( empty( $c['consignment_id'] ) ) {
			return array( 'ok' => false, 'error' => $res['error'] ? $res['error'] : __( 'Steadfast did not return a consignment ID.', 'lavkhata' ) );
		}
		return array(
			'ok'          => true,
			'consignment' => (string) $c['consignment_id'],
			'tracking'    => isset( $c['tracking_code'] ) ? (string) $c['tracking_code'] : '',
			'status'      => 'booked',
			'invoice'     => $invoice,
		);
	}

	public function status( $consignment ) {
		$res = $this->request( 'GET', self::BASE . '/status_by_cid/' . rawurlencode( (string) $consignment ), $this->headers() );
		if ( empty( $res['body']['delivery_status'] ) ) {
			return array( 'ok' => false, 'error' => $res['error'] ? $res['error'] : __( 'No status from Steadfast.', 'lavkhata' ) );
		}
		$raw = (string) $res['body']['delivery_status'];
		return array( 'ok' => true, 'raw' => $raw, 'status' => self::normalize( $raw ) );
	}

	public function balance() {
		$res = $this->request( 'GET', self::BASE . '/get_balance', $this->headers(), null, 10 );
		return isset( $res['body']['current_balance'] ) ? (float) $res['body']['current_balance'] : null;
	}

	/**
	 * Steadfast fraud check: GET /fraud_check/score/{phone}.
	 * The response shape is not formally documented, so read it defensively.
	 */
	public function customer_record( $phone ) {
		$phone = Helpers::bd_phone( $phone );
		if ( '' === $phone || ! $this->is_ready() ) {
			return array( 'ok' => false );
		}
		$res  = $this->request( 'GET', self::BASE . '/fraud_check/score/' . $phone, $this->headers(), null, 8 );
		$body = $res['body'];
		if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
			$body = array_merge( $body, $body['data'] );
		}
		return self::parse_record( $body );
	}

	/** Parse a fraud-check response into delivered / returned counts. */
	public static function parse_record( array $body ) {
		$pick = static function ( array $keys ) use ( $body ) {
			foreach ( $keys as $k ) {
				if ( isset( $body[ $k ] ) && is_numeric( $body[ $k ] ) ) {
					return (float) $body[ $k ];
				}
			}
			return null;
		};
		$delivered = $pick( array( 'delivered_count', 'total_delivered', 'delivered' ) );
		$cancelled = $pick( array( 'cancelled_count', 'total_cancelled', 'cancelled' ) );
		$total     = $pick( array( 'total_parcels', 'total_parcel', 'total_count', 'total' ) );

		if ( null !== $delivered && null !== $cancelled ) {
			return array( 'ok' => true, 'delivered' => (int) $delivered, 'returned' => (int) $cancelled, 'total' => (int) ( $delivered + $cancelled ) );
		}
		if ( null !== $delivered && null !== $total ) {
			return array( 'ok' => true, 'delivered' => (int) $delivered, 'returned' => (int) max( 0, $total - $delivered ), 'total' => (int) $total );
		}
		// Only ratios and a volume band: turn them into an estimate.
		$ratio = $pick( array( 'delivery_ratio', 'success_ratio' ) );
		if ( null !== $ratio ) {
			$bands = array( 'low' => 3, 'medium' => 10, 'high' => 30, 'very_high' => 60 );
			$band  = isset( $body['volume_band'] ) ? (string) $body['volume_band'] : '';
			$n     = null !== $total ? (int) $total : ( isset( $bands[ $band ] ) ? $bands[ $band ] : 0 );
			$d     = (int) round( $n * $ratio / 100 );
			return array( 'ok' => true, 'delivered' => $d, 'returned' => max( 0, $n - $d ), 'total' => $n, 'estimated' => true );
		}
		return array( 'ok' => false );
	}

	public function tracking_url( $consignment, $tracking = '' ) {
		return $tracking ? 'https://steadfast.com.bd/t/' . rawurlencode( $tracking ) : '';
	}

	public static function normalize( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		switch ( $raw ) {
			case 'delivered':
				return 'delivered';
			case 'partial_delivered':
				return 'partial';
			case 'cancelled':
				return 'returned';
			case 'hold':
				return 'hold';
			case 'pending':
			case 'in_review':
				return 'booked';
			default:
				// *_approval_pending and unknown: still with the courier.
				return 'in_transit';
		}
	}

	/** Steadfast webhook auth: "Authorization: Bearer <token>" or HMAC "X-Signature". */
	public static function verify_webhook( $auth_header, $signature_header, $raw_body ) {
		$token = (string) Settings::get( 'steadfast_webhook_token' );
		if ( '' === $token ) {
			return false;
		}
		$auth_header = trim( (string) $auth_header );
		if ( '' !== $auth_header && hash_equals( 'Bearer ' . $token, $auth_header ) ) {
			return true;
		}
		$signature_header = trim( (string) $signature_header );
		if ( '' !== $signature_header ) {
			return hash_equals( hash_hmac( 'sha256', (string) $raw_body, $token ), $signature_header );
		}
		return false;
	}
}
