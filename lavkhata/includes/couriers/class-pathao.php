<?php
/**
 * Pathao Courier merchant API (api-hermes.pathao.com).
 *
 * @package Lavkhata
 */

namespace Lavkhata\Couriers;

use Lavkhata\Helpers;
use Lavkhata\Settings;

defined( 'ABSPATH' ) || exit;

class Pathao extends Courier {

	const LIVE    = 'https://api-hermes.pathao.com';
	const SANDBOX = 'https://courier-api-sandbox.pathao.com';
	const MERCHANT = 'https://merchant.pathao.com';

	public function id() {
		return 'pathao';
	}

	public function name() {
		return 'Pathao';
	}

	public function is_ready() {
		return Settings::pathao_ready();
	}

	private function base() {
		return Settings::get( 'pathao_sandbox' ) ? self::SANDBOX : self::LIVE;
	}

	/** Access token, cached until shortly before it expires. */
	public function token( $force = false ) {
		$key = 'lvk_pathao_token_' . md5( $this->base() . Settings::get( 'pathao_client_id' ) . Settings::get( 'pathao_username' ) );
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( $cached ) {
				return $cached;
			}
		}
		$res = $this->request(
			'POST',
			$this->base() . '/aladdin/api/v1/issue-token',
			array(),
			array(
				'client_id'     => (string) Settings::get( 'pathao_client_id' ),
				'client_secret' => (string) Settings::get( 'pathao_client_secret' ),
				'grant_type'    => 'password',
				'username'      => (string) Settings::get( 'pathao_username' ),
				'password'      => (string) Settings::get( 'pathao_password' ),
			)
		);
		if ( empty( $res['body']['access_token'] ) ) {
			return '';
		}
		$ttl = isset( $res['body']['expires_in'] ) ? max( 300, (int) $res['body']['expires_in'] - 600 ) : DAY_IN_SECONDS;
		set_transient( $key, $res['body']['access_token'], min( $ttl, 30 * DAY_IN_SECONDS ) );
		return $res['body']['access_token'];
	}

	private function auth_request( $method, $url, $body = null, $timeout = 20 ) {
		$token = $this->token();
		if ( '' === $token ) {
			return array( 'code' => 401, 'body' => array(), 'error' => __( 'Could not log in to Pathao. Check your Pathao API credentials.', 'lavkhata' ) );
		}
		$res = $this->request( $method, $url, array( 'Authorization' => 'Bearer ' . $token ), $body, $timeout );
		if ( 401 === $res['code'] ) {
			$token = $this->token( true );
			if ( $token ) {
				$res = $this->request( $method, $url, array( 'Authorization' => 'Bearer ' . $token ), $body, $timeout );
			}
		}
		return $res;
	}

	/** Stores on this Pathao account (to pick the store id in settings). */
	public function stores() {
		$res = $this->auth_request( 'GET', $this->base() . '/aladdin/api/v1/stores' );
		$out = array();
		$list = isset( $res['body']['data']['data'] ) ? $res['body']['data']['data'] : array();
		foreach ( (array) $list as $s ) {
			if ( isset( $s['store_id'] ) ) {
				$out[ (string) $s['store_id'] ] = isset( $s['store_name'] ) ? $s['store_name'] : $s['store_id'];
			}
		}
		return $out;
	}

	public function book( \WC_Order $order ) {
		if ( ! $this->is_ready() ) {
			return array( 'ok' => false, 'error' => __( 'Add your Pathao API credentials in Lavkhata settings.', 'lavkhata' ) );
		}
		$store_id = (int) Settings::get( 'pathao_store_id' );
		if ( ! $store_id ) {
			$stores   = $this->stores();
			$store_id = $stores ? (int) key( $stores ) : 0;
		}
		if ( ! $store_id ) {
			return array( 'ok' => false, 'error' => __( 'Set your Pathao store ID in Lavkhata settings.', 'lavkhata' ) );
		}
		$phone = Helpers::bd_phone( $order->get_billing_phone() );
		if ( '' === $phone ) {
			return array( 'ok' => false, 'error' => __( 'The customer phone number is not a valid Bangladeshi mobile number.', 'lavkhata' ) );
		}
		$attempt = (int) $order->get_meta( '_lvk_book_attempt' ) + 1;
		$invoice = (string) $order->get_order_number() . ( $attempt > 1 ? '-' . $attempt : '' );
		$qty     = 0;
		foreach ( $order->get_items() as $item ) {
			$qty += (int) $item->get_quantity();
		}
		$address = Helpers::address_line( $order );
		if ( mb_strlen( $address ) < 10 ) {
			$address = str_pad( $address, 10, '.' );
		}
		$payload = array(
			'store_id'            => $store_id,
			'merchant_order_id'   => $invoice,
			'recipient_name'      => mb_substr( Helpers::customer_name( $order ), 0, 100 ),
			'recipient_phone'     => $phone,
			'recipient_address'   => mb_substr( $address, 0, 220 ),
			'delivery_type'       => 48, // Normal delivery.
			'item_type'           => 2,  // Parcel.
			'special_instruction' => mb_substr( (string) $order->get_customer_note(), 0, 200 ),
			'item_quantity'       => max( 1, $qty ),
			'item_weight'         => Helpers::weight_kg( $order ),
			'item_description'    => Helpers::items_text( $order, 200 ),
			'amount_to_collect'   => (int) Helpers::cod_amount( $order ),
		);
		$alt = Helpers::bd_phone( $order->get_shipping_phone() );
		if ( $alt && $alt !== $phone ) {
			$payload['recipient_secondary_phone'] = $alt;
		}

		$res = $this->auth_request( 'POST', $this->base() . '/aladdin/api/v1/orders', $payload );
		$order->update_meta_data( '_lvk_book_attempt', $attempt );
		$d = isset( $res['body']['data'] ) && is_array( $res['body']['data'] ) ? $res['body']['data'] : array();
		if ( empty( $d['consignment_id'] ) ) {
			return array( 'ok' => false, 'error' => $res['error'] ? $res['error'] : __( 'Pathao did not return a consignment ID.', 'lavkhata' ) );
		}
		return array(
			'ok'          => true,
			'consignment' => (string) $d['consignment_id'],
			'tracking'    => (string) $d['consignment_id'],
			'status'      => 'booked',
			'charge'      => isset( $d['delivery_fee'] ) ? (float) $d['delivery_fee'] : null,
			'invoice'     => $invoice,
		);
	}

	public function status( $consignment ) {
		$res = $this->auth_request( 'GET', $this->base() . '/aladdin/api/v1/orders/' . rawurlencode( (string) $consignment ) . '/info' );
		$d   = isset( $res['body']['data'] ) && is_array( $res['body']['data'] ) ? $res['body']['data'] : array();
		$raw = isset( $d['order_status_slug'] ) ? $d['order_status_slug'] : ( isset( $d['order_status'] ) ? $d['order_status'] : '' );
		if ( '' === $raw ) {
			return array( 'ok' => false, 'error' => $res['error'] ? $res['error'] : __( 'No status from Pathao.', 'lavkhata' ) );
		}
		return array( 'ok' => true, 'raw' => (string) $raw, 'status' => self::normalize( $raw ) );
	}

	/** Pathao customer success rate (same token, merchant host). */
	public function customer_record( $phone ) {
		$phone = Helpers::bd_phone( $phone );
		if ( '' === $phone || ! $this->is_ready() ) {
			return array( 'ok' => false );
		}
		$res  = $this->auth_request( 'POST', self::MERCHANT . '/api/v1/user/success', array( 'phone' => $phone ), 8 );
		$body = $res['body'];
		$c    = isset( $body['data']['customer'] ) ? $body['data']['customer'] : ( isset( $body['data']['data']['customer'] ) ? $body['data']['data']['customer'] : null );
		if ( ! is_array( $c ) || ! isset( $c['total_delivery'] ) ) {
			return array( 'ok' => false );
		}
		$total     = (int) $c['total_delivery'];
		$delivered = isset( $c['successful_delivery'] ) ? (int) $c['successful_delivery'] : 0;
		return array( 'ok' => true, 'delivered' => $delivered, 'returned' => max( 0, $total - $delivered ), 'total' => $total );
	}

	public function tracking_url( $consignment, $tracking = '' ) {
		return 'https://merchant.pathao.com/tracking?consignment_id=' . rawurlencode( (string) $consignment );
	}

	/**
	 * Pathao uses both status names ("Delivered", "Partial_Delivery") and
	 * webhook event names ("order.delivered", "order.pickup-cancelled").
	 */
	public static function normalize( $raw ) {
		$s = strtolower( str_replace( array( '_', ' ', '.' ), '-', trim( (string) $raw ) ) );
		$s = preg_replace( '/^order-/', '', $s );
		if ( false !== strpos( $s, 'partial' ) ) {
			return 'partial';
		}
		if ( false !== strpos( $s, 'return' ) ) {
			return 'returned';
		}
		if ( false !== strpos( $s, 'cancel' ) ) {
			return 'cancelled';
		}
		if ( 'delivered' === $s || 'paid' === $s ) {
			return 'delivered';
		}
		if ( false !== strpos( $s, 'hold' ) ) {
			return 'hold';
		}
		if ( in_array( $s, array( 'pending', 'created', 'pickup-requested', 'assigned-for-pickup', 'updated' ), true ) ) {
			return 'booked';
		}
		return 'in_transit';
	}

	/** Pathao sends the secret you set in the X-PATHAO-Signature header. */
	public static function verify_webhook( $signature_header ) {
		$secret = (string) Settings::get( 'pathao_webhook_secret' );
		return '' !== $secret && hash_equals( $secret, trim( (string) $signature_header ) );
	}
}
