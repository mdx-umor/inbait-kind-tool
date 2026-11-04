<?php
/**
 * Small shared helpers.
 *
 * @package Lavkhata
 */

namespace Lavkhata;

defined( 'ABSPATH' ) || exit;

class Helpers {

	/**
	 * Normalise a Bangladeshi mobile number to 01XXXXXXXXX.
	 * Returns '' when it is not a valid BD mobile number.
	 */
	public static function bd_phone( $phone ) {
		// Customers often type Bangla digits.
		$digits = preg_replace( '/\D+/', '', self::ascii_digits( $phone ) );
		if ( 0 === strpos( $digits, '880' ) ) {
			$digits = substr( $digits, 2 );
		}
		if ( 10 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = '0' . $digits;
		}
		return preg_match( '/^01[3-9]\d{8}$/', $digits ) ? $digits : '';
	}

	/**
	 * Convert Bangla digits (০-৯) to ASCII before parsing a phone number.
	 */
	public static function ascii_digits( $text ) {
		return str_replace(
			array( '০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯' ),
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			(string) $text
		);
	}

	/** Phone in international format without plus: 8801XXXXXXXXX. */
	public static function intl_phone( $phone ) {
		$p = self::bd_phone( $phone );
		return $p ? '88' . $p : '';
	}

	/** Format money in taka. */
	public static function taka( $amount, $decimals = 0 ) {
		$amount = (float) $amount;
		$sign   = $amount < 0 ? '-' : '';
		return $sign . '৳' . number_format( abs( $amount ), $decimals );
	}

	/** Percent with no trailing zeros. */
	public static function pct( $value ) {
		return rtrim( rtrim( number_format( (float) $value, 1 ), '0' ), '.' ) . '%';
	}

	/** HMAC used for customer-facing links (WhatsApp confirm). */
	public static function sign( $data ) {
		return substr( hash_hmac( 'sha256', (string) $data, wp_salt( 'auth' ) . 'lavkhata' ), 0, 20 );
	}

	/** Is the order shipping inside Dhaka city? Used for default delivery charge. */
	public static function is_inside_dhaka( $order ) {
		$state = strtoupper( (string) ( $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state() ) );
		$city  = strtolower( (string) ( $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city() ) );
		$addr  = strtolower( $order->get_billing_address_1() . ' ' . $order->get_shipping_address_1() );
		if ( 'BD-13' === $state || 'DHA' === $state ) {
			return true;
		}
		return false !== strpos( $city, 'dhaka' ) || false !== strpos( $city, 'ঢাকা' ) || false !== strpos( $addr, 'dhaka city' );
	}

	/** Customer full address on one line. */
	public static function address_line( $order ) {
		$parts = array(
			$order->get_shipping_address_1() ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
			$order->get_shipping_address_2() ? $order->get_shipping_address_2() : $order->get_billing_address_2(),
			$order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city(),
		);
		$state = $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state();
		if ( $state && function_exists( 'WC' ) ) {
			$states = WC()->countries->get_states( 'BD' );
			$parts[] = isset( $states[ $state ] ) ? $states[ $state ] : $state;
		}
		return trim( implode( ', ', array_filter( array_map( 'trim', $parts ) ) ) );
	}

	/** Customer name. */
	public static function customer_name( $order ) {
		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		return '' !== $name ? $name : __( 'Customer', 'lavkhata' );
	}

	/** Amount the courier must collect from the customer. */
	public static function cod_amount( $order ) {
		$total = (float) $order->get_total() - (float) $order->get_total_refunded();
		$method = $order->get_payment_method();
		if ( 'cod' === $method ) {
			return max( 0, round( $total ) );
		}
		if ( 'lvk_advance' === $method ) {
			return max( 0, round( $total - (float) $order->get_meta( '_lvk_advance_amount' ) ) );
		}
		// Paid online (bKash gateway, SSLCommerz, etc.): nothing to collect.
		return $order->is_paid() ? 0 : max( 0, round( $total ) );
	}

	/** Amount already paid before delivery (advance or full online payment). */
	public static function prepaid_amount( $order ) {
		$total = (float) $order->get_total() - (float) $order->get_total_refunded();
		return max( 0, $total - self::cod_amount( $order ) );
	}

	/** Short item description for couriers. */
	public static function items_text( $order, $max = 250 ) {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			$out[] = $item->get_name() . ' x' . $item->get_quantity();
		}
		$text = implode( ', ', $out );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/** Total parcel weight in kg (falls back to 0.5). */
	public static function weight_kg( $order ) {
		$w = 0.0;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && $product->get_weight() ) {
				$w += (float) wc_get_weight( $product->get_weight(), 'kg' ) * $item->get_quantity();
			}
		}
		return $w > 0 ? round( min( max( $w, 0.5 ), 10 ), 2 ) : 0.5;
	}

	/** Log to WooCommerce logs. */
	public static function log( $message, $level = 'info' ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, is_string( $message ) ? $message : wp_json_encode( $message ), array( 'source' => 'lavkhata' ) );
		}
	}
}
