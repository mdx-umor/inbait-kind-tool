<?php
/**
 * Cost price fields on products and variations.
 *
 * @package Lavkhata
 */

namespace Lavkhata\Admin;

defined( 'ABSPATH' ) || exit;

class Products {

	public static function init() {
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'simple_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_simple' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
	}

	public static function simple_field() {
		woocommerce_wp_text_input(
			array(
				'id'          => '_lvk_cost',
				'label'       => __( 'Cost price', 'lavkhata' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'What one unit costs you to buy or make. Lavkhata uses it to show real profit.', 'lavkhata' ),
			)
		);
	}

	public static function save_simple( $product ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product form.
		if ( isset( $_POST['_lvk_cost'] ) ) {
			$v = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_lvk_cost'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' === $v ) {
				$product->delete_meta_data( '_lvk_cost' );
			} else {
				$product->update_meta_data( '_lvk_cost', $v );
			}
		}
	}

	public static function variation_field( $loop, $data, $variation ) {
		woocommerce_wp_text_input(
			array(
				'id'            => "_lvk_cost_{$loop}",
				'name'          => "_lvk_cost[{$loop}]",
				'value'         => get_post_meta( $variation->ID, '_lvk_cost', true ),
				'label'         => __( 'Cost price', 'lavkhata' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'data_type'     => 'price',
				'wrapper_class' => 'form-row form-row-full',
				'placeholder'   => __( 'Same as parent', 'lavkhata' ),
			)
		);
	}

	public static function save_variation( $variation_id, $i ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the variation form.
		if ( ! isset( $_POST['_lvk_cost'][ $i ] ) ) {
			return;
		}
		$v         = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_lvk_cost'][ $i ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			return;
		}
		if ( '' === $v ) {
			$variation->delete_meta_data( '_lvk_cost' );
		} else {
			$variation->update_meta_data( '_lvk_cost', $v );
		}
		$variation->save();
	}
}
