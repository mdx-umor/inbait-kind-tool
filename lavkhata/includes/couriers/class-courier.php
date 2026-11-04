<?php
/**
 * Base class for courier APIs.
 *
 * Normalised statuses used everywhere in the plugin:
 *   booked, in_transit, hold, delivered, partial, returned, cancelled
 * Final ones: delivered, partial, returned, cancelled.
 *
 * @package Lavkhata
 */

namespace Lavkhata\Couriers;

use Lavkhata\Helpers;

defined( 'ABSPATH' ) || exit;

abstract class Courier {

	const FINAL_STATUSES = array( 'delivered', 'partial', 'returned', 'cancelled' );

	/** Machine id: steadfast | pathao. */
	abstract public function id();

	abstract public function name();

	abstract public function is_ready();

	/**
	 * Book a parcel.
	 *
	 * @return array{ok:bool,consignment?:string,tracking?:string,status?:string,charge?:float,error?:string}
	 */
	abstract public function book( \WC_Order $order );

	/**
	 * Current status of a parcel.
	 *
	 * @return array{ok:bool,raw?:string,status?:string,error?:string}
	 */
	abstract public function status( $consignment );

	/**
	 * Customer delivery record from this courier's network.
	 *
	 * @return array{ok:bool,delivered?:int,returned?:int,total?:int,error?:string}
	 */
	abstract public function customer_record( $phone );

	abstract public function tracking_url( $consignment, $tracking = '' );

	/** Map a courier's own status text to our normalised status. */
	abstract public static function normalize( $raw );

	public static function get( $id ) {
		if ( 'pathao' === $id ) {
			return new Pathao();
		}
		if ( 'steadfast' === $id ) {
			return new Steadfast();
		}
		return null;
	}

	/** @return Courier[] */
	public static function all() {
		return array( 'steadfast' => new Steadfast(), 'pathao' => new Pathao() );
	}

	public static function status_label( $status ) {
		$labels = array(
			'booked'     => __( 'Booked', 'lavkhata' ),
			'in_transit' => __( 'In transit', 'lavkhata' ),
			'hold'       => __( 'On hold', 'lavkhata' ),
			'delivered'  => __( 'Delivered', 'lavkhata' ),
			'partial'    => __( 'Partly delivered', 'lavkhata' ),
			'returned'   => __( 'Returned', 'lavkhata' ),
			'cancelled'  => __( 'Cancelled', 'lavkhata' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( str_replace( '_', ' ', (string) $status ) );
	}

	/**
	 * JSON HTTP request helper.
	 *
	 * @return array{code:int,body:array,error:string}
	 */
	protected function request( $method, $url, array $headers = array(), $body = null, $timeout = 20 ) {
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array_merge(
				array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			Helpers::log( $this->name() . ' request failed: ' . $res->get_error_message(), 'warning' );
			return array( 'code' => 0, 'body' => array(), 'error' => $res->get_error_message() );
		}
		$code    = (int) wp_remote_retrieve_response_code( $res );
		$decoded = json_decode( wp_remote_retrieve_body( $res ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		$error   = '';
		if ( $code >= 400 || 0 === $code ) {
			$error = self::error_text( $decoded, $code );
			Helpers::log( $this->name() . " {$method} {$url} -> {$code}: {$error}", 'warning' );
		}
		return array( 'code' => $code, 'body' => $decoded, 'error' => $error );
	}

	/** Pull a readable error out of an API response. */
	protected static function error_text( array $body, $code ) {
		foreach ( array( 'message', 'error', 'errors' ) as $key ) {
			if ( empty( $body[ $key ] ) ) {
				continue;
			}
			$v = $body[ $key ];
			if ( is_array( $v ) ) {
				$flat = array();
				array_walk_recursive(
					$v,
					static function ( $item ) use ( &$flat ) {
						$flat[] = (string) $item;
					}
				);
				return implode( ' ', $flat );
			}
			return (string) $v;
		}
		/* translators: %d: HTTP status code */
		return sprintf( __( 'HTTP error %d', 'lavkhata' ), $code );
	}
}
