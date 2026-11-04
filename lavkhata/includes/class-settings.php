<?php
/**
 * Settings storage with defaults.
 *
 * @package Lavkhata
 */

namespace Lavkhata;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'lavkhata_settings';

	/** @var array|null */
	private static $cache = null;

	public static function defaults() {
		return array(
			// General.
			'mode'                  => 'report', // report | auto.
			'language'              => 'auto',   // auto | en_US | bn_BD.
			'default_courier'       => 'steadfast',
			'hold_until_confirmed'  => 0,

			// Steadfast.
			'steadfast_api_key'       => '',
			'steadfast_secret_key'    => '',
			'steadfast_webhook_token' => '',

			// Pathao.
			'pathao_client_id'      => '',
			'pathao_client_secret'  => '',
			'pathao_username'       => '',
			'pathao_password'       => '',
			'pathao_store_id'       => '',
			'pathao_sandbox'        => 0,
			'pathao_webhook_secret' => '',

			// COD protection.
			'cod_guard_enabled'   => 1,
			'cod_use_steadfast'   => 1,
			'cod_use_pathao'      => 1,
			'cod_use_store'       => 1,
			'cod_min_parcels'     => 3,
			'cod_min_ratio'       => 70,
			'cod_new_customer'    => 'allow', // allow | advance.
			'cod_action'          => 'advance', // advance | full.
			'cod_advance_type'    => 'fixed', // fixed | percent | shipping.
			'cod_advance_value'   => 150,
			'cod_message'         => '', // Empty = translated default (see default_cod_message()).

			// Costs used for real profit.
			'cost_delivery_inside'  => 70,
			'cost_delivery_outside' => 130,
			'cost_return_pct'       => 50,
			'cost_cod_fee_pct'      => 1,
			'cost_packaging'        => 15,
			'cost_gateway_fee_pct'  => 1.5,
			'ads_vat_pct'           => 15,
			'usd_rate'              => 125,
			'advance_kept_on_return' => 1,

			// Meta.
			'meta_pixel_id'        => '',
			'meta_capi_token'      => '',
			'meta_test_code'       => '',
			'meta_browser_pixel'   => 1,
			'meta_purchase_on'     => 'delivered', // delivered | placed.

			// WhatsApp confirmation.
			'wa_enabled'  => 1,
			'wa_template' => '', // Empty = translated default (see default_wa_template()).

			// Restock.
			'restock_lookback' => 30,
			'restock_lead'     => 7,
			'restock_cover'    => 30,

			// Labels.
			'label_size'     => '75x100',
			'label_phone'    => '',
			'label_show_items' => 1,
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $values ) {
		$all = self::all();
		foreach ( $values as $k => $v ) {
			if ( array_key_exists( $k, self::defaults() ) ) {
				$all[ $k ] = $v;
			}
		}
		update_option( self::OPTION, $all, false );
		self::$cache = $all;
	}

	public static function default_cod_message() {
		return __( 'Many earlier parcels to this number were not received, so please pay a small advance to confirm this order. You pay the rest on delivery.', 'lavkhata' );
	}

	public static function default_wa_template() {
		return __( 'Assalamu alaikum {name}, thank you for your order #{order} from {shop} ({total}). Please tap this link to confirm: {link}', 'lavkhata' );
	}

	public static function cod_message() {
		$m = trim( (string) self::get( 'cod_message' ) );
		return '' !== $m ? $m : self::default_cod_message();
	}

	public static function wa_template() {
		$m = trim( (string) self::get( 'wa_template' ) );
		return '' !== $m ? $m : self::default_wa_template();
	}

	public static function is_auto() {
		return 'auto' === self::get( 'mode' );
	}

	public static function steadfast_ready() {
		return '' !== self::get( 'steadfast_api_key' ) && '' !== self::get( 'steadfast_secret_key' );
	}

	public static function pathao_ready() {
		return '' !== self::get( 'pathao_client_id' ) && '' !== self::get( 'pathao_client_secret' )
			&& '' !== self::get( 'pathao_username' ) && '' !== self::get( 'pathao_password' );
	}

	public static function meta_ready() {
		return '' !== self::get( 'meta_pixel_id' ) && '' !== self::get( 'meta_capi_token' );
	}

	/** Clear the in-memory cache (used by tests and after saving). */
	public static function flush() {
		self::$cache = null;
	}
}
