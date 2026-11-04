<?php
/**
 * "Pay an advance by bKash / Nagad, rest on delivery" payment method.
 * Only shown to customers COD protection marks as risky.
 *
 * @package Lavkhata
 */

namespace Lavkhata;

defined( 'ABSPATH' ) || exit;

class Gateway_Advance extends \WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'lvk_advance';
		$this->method_title       = __( 'Advance + Cash on Delivery (Lavkhata)', 'lavkhata' );
		$this->method_description = __( 'Shown only to customers with a poor delivery record. They send a small advance by bKash, Nagad or Rocket and pay the rest on delivery. You check the transaction ID and confirm the order.', 'lavkhata' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title        = $this->get_option( 'title' );
		$this->description  = $this->get_option( 'description' );
		$this->enabled      = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable', 'lavkhata' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer advance + COD to risky customers', 'lavkhata' ),
				'default' => 'yes',
			),
			'title'       => array(
				'title'   => __( 'Title', 'lavkhata' ),
				'type'    => 'text',
				'default' => __( 'Pay advance now, rest on delivery', 'lavkhata' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'lavkhata' ),
				'type'    => 'textarea',
				'default' => __( 'Send the advance by Send Money to one of the numbers below, then enter the number you paid from and the transaction ID.', 'lavkhata' ),
			),
			'bkash'       => array(
				'title'       => __( 'bKash number', 'lavkhata' ),
				'type'        => 'text',
				'placeholder' => '01XXXXXXXXX',
			),
			'nagad'       => array(
				'title'       => __( 'Nagad number', 'lavkhata' ),
				'type'        => 'text',
				'placeholder' => '01XXXXXXXXX',
			),
			'rocket'      => array(
				'title'       => __( 'Rocket number', 'lavkhata' ),
				'type'        => 'text',
				'placeholder' => '01XXXXXXXXX-X',
			),
			'account_type' => array(
				'title'   => __( 'Number type', 'lavkhata' ),
				'type'    => 'select',
				'default' => 'personal',
				'options' => array(
					'personal' => __( 'Personal (customer uses Send Money)', 'lavkhata' ),
					'merchant' => __( 'Merchant (customer uses Payment)', 'lavkhata' ),
				),
			),
		);
	}

	private function cart_advance() {
		if ( ! WC()->cart ) {
			return 0;
		}
		return Cod_Guard::advance_amount( (float) WC()->cart->get_total( 'edit' ), (float) WC()->cart->get_shipping_total() );
	}

	private function numbers() {
		$out = array();
		foreach ( array( 'bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket' ) as $k => $label ) {
			$n = trim( (string) $this->get_option( $k ) );
			if ( '' !== $n ) {
				$out[ $label ] = $n;
			}
		}
		return $out;
	}

	public function payment_fields() {
		$advance = $this->cart_advance();
		$total   = WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : 0;
		$verb    = 'merchant' === $this->get_option( 'account_type' ) ? __( 'Payment', 'lavkhata' ) : __( 'Send Money', 'lavkhata' );
		echo '<div class="lvk-advance">';
		echo '<p class="lvk-advance-amount"><strong>' . esc_html__( 'Pay now:', 'lavkhata' ) . ' ' . esc_html( Helpers::taka( $advance ) ) . '</strong> &middot; ' . esc_html__( 'On delivery:', 'lavkhata' ) . ' ' . esc_html( Helpers::taka( max( 0, $total - $advance ) ) ) . '</p>';
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}
		$numbers = $this->numbers();
		if ( $numbers ) {
			echo '<ul class="lvk-advance-numbers">';
			foreach ( $numbers as $label => $n ) {
				/* translators: 1: wallet, 2: Send Money / Payment, 3: number */
				echo '<li>' . esc_html( sprintf( __( '%1$s (%2$s): %3$s', 'lavkhata' ), $label, $verb, $n ) ) . '</li>';
			}
			echo '</ul>';
		}
		woocommerce_form_field(
			'lvk_adv_sender',
			array(
				'type'        => 'tel',
				'label'       => __( 'Number you paid from', 'lavkhata' ),
				'required'    => true,
				'placeholder' => '01XXXXXXXXX',
			)
		);
		woocommerce_form_field(
			'lvk_adv_trx',
			array(
				'type'        => 'text',
				'label'       => __( 'Transaction ID (TrxID)', 'lavkhata' ),
				'required'    => true,
				'placeholder' => '8N7A6B5C4D',
			)
		);
		echo '</div>';
	}

	public function validate_fields() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checks the checkout nonce.
		$sender = isset( $_POST['lvk_adv_sender'] ) ? sanitize_text_field( wp_unslash( $_POST['lvk_adv_sender'] ) ) : '';
		$trx    = isset( $_POST['lvk_adv_trx'] ) ? sanitize_text_field( wp_unslash( $_POST['lvk_adv_trx'] ) ) : '';
		// phpcs:enable
		$ok = true;
		if ( '' === Helpers::bd_phone( $sender ) ) {
			wc_add_notice( __( 'Please enter the mobile number you sent the advance from.', 'lavkhata' ), 'error' );
			$ok = false;
		}
		if ( ! preg_match( '/^[A-Za-z0-9]{6,20}$/', $trx ) ) {
			wc_add_notice( __( 'Please enter a valid transaction ID.', 'lavkhata' ), 'error' );
			$ok = false;
		}
		return $ok;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$sender = isset( $_POST['lvk_adv_sender'] ) ? Helpers::bd_phone( sanitize_text_field( wp_unslash( $_POST['lvk_adv_sender'] ) ) ) : '';
		$trx    = isset( $_POST['lvk_adv_trx'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['lvk_adv_trx'] ) ) ) : '';
		// phpcs:enable
		$advance = Cod_Guard::advance_amount( (float) $order->get_total(), (float) $order->get_shipping_total() );

		$order->update_meta_data( '_lvk_advance_amount', $advance );
		$order->update_meta_data( '_lvk_advance_sender', $sender );
		$order->update_meta_data( '_lvk_advance_trx', $trx );
		$order->save();

		/* translators: 1: advance amount, 2: sender, 3: transaction id */
		$order->update_status( 'on-hold', sprintf( __( 'Waiting to verify advance of %1$s from %2$s (TrxID %3$s).', 'lavkhata' ), Helpers::taka( $advance ), $sender, $trx ) );
		wc_reduce_stock_levels( $order_id );
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	public function thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		/* translators: 1: advance, 2: amount due on delivery */
		echo '<p class="lvk-advance-thanks">' . esc_html( sprintf( __( 'We received your advance details (%1$s). We will confirm your order after checking the payment. Pay %2$s on delivery.', 'lavkhata' ), Helpers::taka( $order->get_meta( '_lvk_advance_amount' ) ), Helpers::taka( Helpers::cod_amount( $order ) ) ) ) . '</p>';
	}

	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $sent_to_admin || $this->id !== $order->get_payment_method() ) {
			return;
		}
		/* translators: 1: advance, 2: amount due on delivery */
		$text = sprintf( __( 'Advance paid: %1$s. Amount to pay on delivery: %2$s.', 'lavkhata' ), Helpers::taka( $order->get_meta( '_lvk_advance_amount' ) ), Helpers::taka( Helpers::cod_amount( $order ) ) );
		echo $plain_text ? esc_html( $text ) . "\n\n" : '<p>' . esc_html( $text ) . '</p>';
	}

	/** Admin: mark the advance as received. */
	public static function verify( \WC_Order $order ) {
		$order->update_meta_data( '_lvk_advance_verified', time() );
		$order->set_transaction_id( (string) $order->get_meta( '_lvk_advance_trx' ) );
		$order->save();
		/* translators: %s: advance amount */
		$note = sprintf( __( 'Advance of %s verified.', 'lavkhata' ), Helpers::taka( $order->get_meta( '_lvk_advance_amount' ) ) );
		if ( in_array( $order->get_status(), array( 'on-hold', 'pending' ), true ) ) {
			$order->update_status( 'processing', $note );
		} else {
			$order->add_order_note( $note );
		}
	}
}
