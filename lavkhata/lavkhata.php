<?php
/**
 * Plugin Name:       Lavkhata – COD Profit, Courier Sync & Fraud Guard
 * Plugin URI:        https://lavkhata.vercel.app/
 * Description:       Real profit after delivery, one-click Steadfast & Pathao booking with status sync, COD fraud protection, Meta Conversions API on delivered orders, WhatsApp order confirmation and restock planning for Bangladeshi WooCommerce shops.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Lavkhata
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lavkhata
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   10.3
 *
 * @package Lavkhata
 */

defined( 'ABSPATH' ) || exit;

define( 'LVK_VERSION', '1.0.0' );
define( 'LVK_FILE', __FILE__ );
define( 'LVK_DIR', plugin_dir_path( __FILE__ ) );
define( 'LVK_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'Lavkhata\\' ) ) {
			return;
		}
		$map = array(
			'Lavkhata\\Plugin'              => 'includes/class-plugin.php',
			'Lavkhata\\Install'             => 'includes/class-install.php',
			'Lavkhata\\Settings'            => 'includes/class-settings.php',
			'Lavkhata\\Helpers'             => 'includes/class-helpers.php',
			'Lavkhata\\Index'               => 'includes/class-index.php',
			'Lavkhata\\Courier_Sync'        => 'includes/class-courier-sync.php',
			'Lavkhata\\Cod_Guard'           => 'includes/class-cod-guard.php',
			'Lavkhata\\Gateway_Advance'     => 'includes/class-gateway-advance.php',
			'Lavkhata\\Ledger'              => 'includes/class-ledger.php',
			'Lavkhata\\Profit'              => 'includes/class-profit.php',
			'Lavkhata\\Meta_Capi'           => 'includes/class-meta-capi.php',
			'Lavkhata\\Whatsapp_Confirm'    => 'includes/class-whatsapp-confirm.php',
			'Lavkhata\\Restock'             => 'includes/class-restock.php',
			'Lavkhata\\Labels'              => 'includes/class-labels.php',
			'Lavkhata\\Code128'             => 'includes/class-code128.php',
			'Lavkhata\\Couriers\\Courier'   => 'includes/couriers/class-courier.php',
			'Lavkhata\\Couriers\\Steadfast' => 'includes/couriers/class-steadfast.php',
			'Lavkhata\\Couriers\\Pathao'    => 'includes/couriers/class-pathao.php',
			'Lavkhata\\Admin\\Admin'        => 'includes/admin/class-admin.php',
			'Lavkhata\\Admin\\Orders'       => 'includes/admin/class-orders.php',
			'Lavkhata\\Admin\\Products'     => 'includes/admin/class-products.php',
			'Lavkhata\\Admin\\Settings_Page' => 'includes/admin/class-settings-page.php',
		);
		if ( isset( $map[ $class ] ) ) {
			require_once LVK_DIR . $map[ $class ];
		}
	}
);

register_activation_hook( __FILE__, array( 'Lavkhata\\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Lavkhata\\Install', 'deactivate' ) );

// Tell WooCommerce we support High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Lavkhata needs WooCommerce. Please install and activate WooCommerce first.', 'lavkhata' ) . '</p></div>';
				}
			);
			return;
		}
		Lavkhata\Plugin::instance();
	},
	20
);
