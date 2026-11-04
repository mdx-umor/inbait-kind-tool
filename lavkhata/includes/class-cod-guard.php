<?php
/**
 * COD protection: look up a customer's delivery record and ask risky
 * customers to pay an advance (or the full amount) before ordering.
 *
 * @package Lavkhata
 */

namespace Lavkhata;

use Lavkhata\Couriers\Courier;

defined( 'ABSPATH' ) || exit;

class Cod_Guard {

	/** @var array Assessments made during this request, by phone. */
	private static $memo = array();

	public static function init() {
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_gateways' ), 50 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'scripts' ) );
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'checkout_notice' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_blocks' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'save_on_order' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'save_on_order' ) );
	}

	/**
	 * Decide the risk level from one or more record sources. Pure function.
	 *
	 * @param array $sources    name => [delivered, returned, total].
	 * @param int   $min_parcels Parcels needed before we judge.
	 * @param float $min_ratio   Minimum delivered % to count as safe.
	 * @return array{level:string,delivered:int,returned:int,total:int,ratio:float|null,reason:string}
	 */
	public static function risk_level( array $sources, $min_parcels, $min_ratio ) {
		$courier = array( 'delivered' => 0, 'returned' => 0, 'total' => 0 );
		$level   = 'new';
		$reason  = '';
		foreach ( $sources as $name => $r ) {
			if ( empty( $r['ok'] ) ) {
				continue;
			}
			$total = (int) $r['total'];
			if ( 'store' !== $name ) {
				$courier['delivered'] += (int) $r['delivered'];
				$courier['returned']  += (int) $r['returned'];
				$courier['total']     += $total;
			}
			if ( $total <= 0 ) {
				continue;
			}
			$ratio = (int) $r['delivered'] / $total * 100;
			if ( $total >= $min_parcels && $ratio < $min_ratio ) {
				$level  = 'risky';
				$reason = $name;
			} elseif ( 'risky' !== $level && $total >= $min_parcels ) {
				$level = 'good';
			} elseif ( 'new' === $level ) {
				$level = 'few';
			}
		}
		// Badge numbers: courier network if we have it, otherwise this shop's history.
		$shown = $courier;
		if ( 0 === $courier['total'] && ! empty( $sources['store']['ok'] ) ) {
			$shown = array(
				'delivered' => (int) $sources['store']['delivered'],
				'returned'  => (int) $sources['store']['returned'],
				'total'     => (int) $sources['store']['total'],
			);
		}
		$shown['ratio']  = $shown['total'] > 0 ? round( $shown['delivered'] / $shown['total'] * 100, 1 ) : null;
		$shown['level']  = $level;
		$shown['reason'] = $reason;
		return $shown;
	}

	/**
	 * Full assessment for a phone number (courier lookups cached for 6 hours).
	 */
	public static function assess( $phone, $exclude_order = 0, $fresh = false ) {
		$phone = Helpers::bd_phone( $phone );
		if ( '' === $phone ) {
			return null;
		}
		$memo_key = $phone . '|' . $exclude_order;
		if ( ! $fresh && isset( self::$memo[ $memo_key ] ) ) {
			return self::$memo[ $memo_key ];
		}
		$sources = array();
		$cache   = 'lvk_rec_' . $phone;
		$network = $fresh ? false : get_transient( $cache );
		if ( ! is_array( $network ) ) {
			$network = array();
			foreach ( Courier::all() as $id => $courier ) {
				if ( Settings::get( 'cod_use_' . $id ) && $courier->is_ready() ) {
					$network[ $id ] = $courier->customer_record( $phone );
				}
			}
			$failed = false;
			foreach ( $network as $r ) {
				$failed = $failed || empty( $r['ok'] );
			}
			// Cache good answers for 6 hours, failures for 10 minutes.
			set_transient( $cache, $network, $failed ? 10 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS );
		}
		$sources = $network;
		if ( Settings::get( 'cod_use_store' ) ) {
			$h                = Index::history( $phone, $exclude_order );
			$sources['store'] = array( 'ok' => true, 'delivered' => $h['delivered'], 'returned' => $h['returned'], 'total' => $h['total'] );
		}
		$level            = self::risk_level( $sources, (int) Settings::get( 'cod_min_parcels' ), (float) Settings::get( 'cod_min_ratio' ) );
		$level['phone']   = $phone;
		$level['sources'] = $sources;
		$level['checked'] = time();
		self::$memo[ $memo_key ] = $level;
		return $level;
	}

	/**
	 * What checkout should do: allow | advance | full.
	 */
	public static function decision( $assessment ) {
		if ( ! Settings::get( 'cod_guard_enabled' ) || ! $assessment ) {
			return 'allow';
		}
		$risky = 'risky' === $assessment['level']
			|| ( 'new' === $assessment['level'] && 'advance' === Settings::get( 'cod_new_customer' ) );
		if ( ! $risky ) {
			return 'allow';
		}
		return 'full' === Settings::get( 'cod_action' ) ? 'full' : 'advance';
	}

	/** Advance to collect for an order total. */
	public static function advance_amount( $total, $shipping ) {
		$type  = Settings::get( 'cod_advance_type' );
		$value = (float) Settings::get( 'cod_advance_value' );
		if ( 'percent' === $type ) {
			$amount = $total * $value / 100;
		} elseif ( 'shipping' === $type ) {
			$amount = $shipping > 0 ? $shipping : $value;
		} else {
			$amount = $value;
		}
		return (float) min( max( 0, round( $amount ) ), round( (float) $total ) );
	}

	/** Phone number typed on the classic checkout (also during AJAX refresh). */
	public static function checkout_phone() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Read-only, WooCommerce verifies its own nonce.
		if ( isset( $_POST['post_data'] ) ) {
			parse_str( wp_unslash( $_POST['post_data'] ), $data ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! empty( $data['billing_phone'] ) ) {
				return sanitize_text_field( $data['billing_phone'] );
			}
		}
		if ( isset( $_POST['billing_phone'] ) ) {
			return sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) );
		}
		// phpcs:enable
		if ( function_exists( 'WC' ) && WC()->customer ) {
			return WC()->customer->get_billing_phone();
		}
		return '';
	}

	/** Classic checkout page, or its AJAX refresh / submit. Never the cart or order-pay pages. */
	private static function is_checkout_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ajax = isset( $_REQUEST['wc-ajax'] ) ? sanitize_key( wp_unslash( $_REQUEST['wc-ajax'] ) ) : '';
		if ( in_array( $ajax, array( 'update_order_review', 'checkout' ), true ) ) {
			return true;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			return false;
		}
		return function_exists( 'is_checkout' ) && is_checkout();
	}

	private static function current_decision() {
		if ( ! Settings::get( 'cod_guard_enabled' ) ) {
			return array( 'allow', null );
		}
		$a = self::assess( self::checkout_phone() );
		return array( self::decision( $a ), $a );
	}

	public static function filter_gateways( $gateways ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $gateways;
		}
		if ( ! self::is_checkout_request() ) {
			// Only the classic checkout shows the advance option.
			unset( $gateways['lvk_advance'] );
			return $gateways;
		}
		list( $decision ) = self::current_decision();
		if ( 'allow' === $decision ) {
			unset( $gateways['lvk_advance'] );
		} elseif ( 'advance' === $decision ) {
			unset( $gateways['cod'] );
		} else {
			unset( $gateways['cod'], $gateways['lvk_advance'] );
		}
		return $gateways;
	}

	public static function message( $decision ) {
		$msg = Settings::cod_message();
		if ( 'full' === $decision ) {
			$msg = __( 'Cash on delivery is not available for this phone number. Please pay online to place the order.', 'lavkhata' );
		} elseif ( function_exists( 'WC' ) && WC()->cart ) {
			$advance = self::advance_amount( (float) WC()->cart->get_total( 'edit' ), (float) WC()->cart->get_shipping_total() );
			/* translators: %s: advance amount */
			$msg .= ' ' . sprintf( __( 'Advance: %s.', 'lavkhata' ), Helpers::taka( $advance ) );
		}
		return $msg;
	}

	public static function checkout_notice() {
		list( $decision ) = self::current_decision();
		if ( 'allow' === $decision ) {
			return;
		}
		echo '<div class="woocommerce-info lvk-cod-notice" role="status">' . esc_html( self::message( $decision ) ) . '</div>';
	}

	public static function validate_classic( $data, $errors ) {
		if ( isset( $data['payment_method'] ) && 'cod' === $data['payment_method'] ) {
			$decision = self::decision( self::assess( isset( $data['billing_phone'] ) ? $data['billing_phone'] : '' ) );
			if ( 'allow' !== $decision ) {
				$errors->add( 'lvk_cod', self::message( $decision ) );
			}
		}
	}

	public static function validate_blocks( $order, $request ) {
		$method = $request->get_param( 'payment_method' );
		if ( 'cod' !== $method ) {
			return;
		}
		$decision = self::decision( self::assess( $order->get_billing_phone() ) );
		if ( 'allow' !== $decision && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'lvk_cod_blocked', esc_html( self::message( 'full' ) ), 400 );
		}
	}

	/** Remember the record on the order so the orders list can show it. */
	public static function save_on_order( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		Index::touch( $order );
		$a = self::assess( $order->get_billing_phone(), $order->get_id() );
		if ( $a ) {
			self::store_record( $order, $a );
		}
	}

	public static function store_record( \WC_Order $order, array $a ) {
		$order->update_meta_data(
			'_lvk_record',
			array(
				'level'     => $a['level'],
				'delivered' => $a['delivered'],
				'returned'  => $a['returned'],
				'total'     => $a['total'],
				'ratio'     => $a['ratio'],
				'sources'   => $a['sources'],
				'checked'   => $a['checked'],
			)
		);
		$order->save();
	}

	public static function scripts() {
		if ( function_exists( 'is_checkout' ) && is_checkout() && Settings::get( 'cod_guard_enabled' ) ) {
			wp_enqueue_script( 'lvk-checkout', LVK_URL . 'assets/checkout.js', array( 'jquery', 'wc-checkout' ), LVK_VERSION, true );
		}
	}
}
