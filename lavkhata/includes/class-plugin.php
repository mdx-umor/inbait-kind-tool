<?php
/**
 * Plugin bootstrap.
 *
 * @package Lavkhata
 */

namespace Lavkhata;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'plugin_locale', array( $this, 'locale' ), 10, 2 );
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'lavkhata', false, dirname( plugin_basename( LVK_FILE ) ) . '/languages' );
			},
			0
		);

		add_action( 'init', array( 'Lavkhata\\Install', 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( 'Lavkhata\\Install', 'add_statuses' ) );
		add_action( 'admin_init', array( 'Lavkhata\\Install', 'maybe_upgrade' ) );
		add_action( 'lvk_backfill', array( 'Lavkhata\\Index', 'backfill' ) );

		// Returned parcels come back into stock.
		add_action( 'woocommerce_order_status_lvk-returned', 'wc_maybe_increase_stock_levels' );
		// Orders with the courier still count in WooCommerce reports.
		add_filter( 'woocommerce_reports_order_statuses', array( $this, 'report_statuses' ) );

		// Keep our order index up to date.
		add_action( 'woocommerce_new_order', array( $this, 'index_order' ), 20, 2 );
		add_action( 'woocommerce_update_order', array( $this, 'index_order' ), 20, 2 );

		add_filter( 'woocommerce_payment_gateways', array( $this, 'gateways' ) );

		Courier_Sync::init();
		Cod_Guard::init();
		Profit::init();
		Meta_Capi::init();
		Whatsapp_Confirm::init();

		if ( is_admin() ) {
			Admin\Admin::init();
			Admin\Orders::init();
			Admin\Products::init();
			Admin\Settings_Page::init();
		}
		add_filter( 'plugin_action_links_' . plugin_basename( LVK_FILE ), array( $this, 'action_links' ) );
	}

	public function locale( $locale, $domain ) {
		if ( 'lavkhata' === $domain ) {
			$lang = Settings::get( 'language' );
			if ( in_array( $lang, array( 'en_US', 'bn_BD' ), true ) ) {
				return $lang;
			}
		}
		return $locale;
	}

	public function index_order( $order_id, $order = null ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $order && 'shop_order' === $order->get_type() ) {
			Index::touch( $order );
		}
	}

	public function gateways( $gateways ) {
		$gateways[] = 'Lavkhata\\Gateway_Advance';
		return $gateways;
	}

	public function report_statuses( $statuses ) {
		if ( is_array( $statuses ) && ! in_array( 'lvk-shipped', $statuses, true ) ) {
			$statuses[] = 'lvk-shipped';
		}
		return $statuses;
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=lavkhata-settings' ) ) . '">' . esc_html__( 'Settings', 'lavkhata' ) . '</a>' );
		return $links;
	}
}
