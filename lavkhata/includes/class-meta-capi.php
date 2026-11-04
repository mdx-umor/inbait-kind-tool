<?php
/**
 * Meta Pixel + Conversions API.
 * By default a Purchase is sent only when the courier delivers the order,
 * so one delivered order = one Purchase and Meta learns from real buyers.
 *
 * @package Lavkhata
 */

namespace Lavkhata;

defined( 'ABSPATH' ) || exit;

class Meta_Capi {

	const GRAPH = 'https://graph.facebook.com/v21.0/';

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'pixel' ), 5 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'capture_browser' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'capture_browser' ) );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'on_thankyou' ), 5 );
		add_action( 'lvk_capi_send', array( __CLASS__, 'send_purchase' ), 10, 1 );
	}

	private static function active() {
		return '' !== (string) Settings::get( 'meta_pixel_id' );
	}

	/** Browser pixel: PageView, ViewContent, AddToCart, InitiateCheckout (and Purchase if set to "placed"). */
	public static function pixel() {
		if ( ! self::active() || ! Settings::get( 'meta_browser_pixel' ) || is_admin() ) {
			return;
		}
		$pixel  = preg_replace( '/\D/', '', (string) Settings::get( 'meta_pixel_id' ) );
		$events = array();
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product ) {
				$events[] = array( 'ViewContent', array( 'content_ids' => array( (string) $product->get_id() ), 'content_type' => 'product', 'content_name' => $product->get_name(), 'value' => (float) $product->get_price(), 'currency' => get_woocommerce_currency() ) );
			}
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && WC()->cart ) {
			$events[] = array( 'InitiateCheckout', array( 'value' => (float) WC()->cart->get_total( 'edit' ), 'currency' => get_woocommerce_currency(), 'num_items' => WC()->cart->get_cart_contents_count() ) );
		}
		?>
<!-- Meta Pixel (Lavkhata) -->
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', <?php echo wp_json_encode( $pixel ); ?>);
fbq('track', 'PageView');
<?php foreach ( $events as $e ) : ?>
fbq('track', <?php echo wp_json_encode( $e[0] ); ?>, <?php echo wp_json_encode( $e[1] ); ?>);
<?php endforeach; ?>
(function(){
  function addToCart(id, qty){ if(window.fbq){ fbq('track','AddToCart',{content_ids:[String(id)],content_type:'product',num_items:qty||1,currency:<?php echo wp_json_encode( get_woocommerce_currency() ); ?>}); } }
  if (window.jQuery) {
    jQuery(document.body).on('adding_to_cart', function(e, btn){ var b = jQuery(btn); addToCart(b.data('product_id'), b.data('quantity')); });
    jQuery(document).on('submit', 'form.cart', function(){ var f = jQuery(this); addToCart(f.find('[name=add-to-cart]').val() || f.find('[name=product_id]').val(), parseInt(f.find('[name=quantity]').val(),10) || 1); });
  }
})();
</script>
<!-- /Meta Pixel -->
		<?php
	}

	/** Remember the browser ids Meta needs for matching. */
	public static function capture_browser( $order ) {
		if ( ! $order instanceof \WC_Order || ! self::active() ) {
			return;
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$fbp = isset( $_COOKIE['_fbp'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) : '';
		$fbc = isset( $_COOKIE['_fbc'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) ) : '';
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		// phpcs:enable
		$order->update_meta_data( '_lvk_fbp', $fbp );
		$order->update_meta_data( '_lvk_fbc', $fbc );
		$order->update_meta_data( '_lvk_ua', mb_substr( $ua, 0, 400 ) );
		$order->update_meta_data( '_lvk_source_url', wp_get_referer() ? esc_url_raw( wp_get_referer() ) : home_url( '/' ) );
		$order->save();

		if ( 'placed' === Settings::get( 'meta_purchase_on' ) ) {
			self::queue( $order );
		}
	}

	/** Browser Purchase on the thank-you page, deduplicated with the server event. */
	public static function on_thankyou( $order_id ) {
		if ( ! self::active() || 'placed' !== Settings::get( 'meta_purchase_on' ) || ! Settings::get( 'meta_browser_pixel' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_lvk_pixel_shown' ) ) {
			return;
		}
		$order->update_meta_data( '_lvk_pixel_shown', 1 );
		$order->save();
		$data = self::custom_data( $order );
		echo '<script>if(window.fbq){fbq("track","Purchase",' . wp_json_encode( $data ) . ',{eventID:' . wp_json_encode( self::event_id( $order ) ) . '});}</script>';
	}

	/** Called when the courier (or the shop) marks an order delivered. */
	public static function on_delivered( \WC_Order $order ) {
		if ( 'delivered' === Settings::get( 'meta_purchase_on' ) ) {
			self::queue( $order );
		}
	}

	private static function queue( \WC_Order $order ) {
		if ( ! Settings::meta_ready() || $order->get_meta( '_lvk_capi_sent' ) ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'lvk_capi_send', array( $order->get_id() ), 'lavkhata' );
		} else {
			wp_schedule_single_event( time(), 'lvk_capi_send', array( $order->get_id() ) );
		}
	}

	public static function event_id( \WC_Order $order ) {
		return 'lvk_order_' . $order->get_id();
	}

	private static function custom_data( \WC_Order $order ) {
		$contents = array();
		$ids      = array();
		foreach ( $order->get_items() as $item ) {
			$pid        = (string) ( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
			$ids[]      = $pid;
			$contents[] = array(
				'id'         => $pid,
				'quantity'   => (int) $item->get_quantity(),
				'item_price' => round( (float) $order->get_item_subtotal( $item, true ), 2 ),
			);
		}
		return array(
			'currency'     => $order->get_currency(),
			'value'        => round( (float) $order->get_total() - (float) $order->get_total_refunded(), 2 ),
			'content_ids'  => $ids,
			'contents'     => $contents,
			'content_type' => 'product',
			'num_items'    => count( $contents ),
			'order_id'     => (string) $order->get_order_number(),
		);
	}

	private static function hash( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return '' === $value ? null : hash( 'sha256', $value );
	}

	/** Build the Conversions API payload. Public for tests. */
	public static function payload( \WC_Order $order ) {
		$user = array(
			'ph'          => array_filter( array( self::hash( Helpers::intl_phone( $order->get_billing_phone() ) ) ) ),
			'em'          => array_filter( array( self::hash( $order->get_billing_email() ) ) ),
			'fn'          => array_filter( array( self::hash( $order->get_billing_first_name() ) ) ),
			'ln'          => array_filter( array( self::hash( $order->get_billing_last_name() ) ) ),
			'ct'          => array_filter( array( self::hash( preg_replace( '/[^a-z]/', '', strtolower( $order->get_billing_city() ) ) ) ) ),
			'country'     => array( hash( 'sha256', 'bd' ) ),
			'external_id' => array( hash( 'sha256', $order->get_customer_id() ? 'user_' . $order->get_customer_id() : 'phone_' . Helpers::bd_phone( $order->get_billing_phone() ) ) ),
		);
		$ip = $order->get_customer_ip_address();
		if ( $ip ) {
			$user['client_ip_address'] = $ip;
		}
		foreach ( array( '_lvk_ua' => 'client_user_agent', '_lvk_fbp' => 'fbp', '_lvk_fbc' => 'fbc' ) as $meta => $key ) {
			$v = (string) $order->get_meta( $meta );
			if ( '' !== $v ) {
				$user[ $key ] = $v;
			}
		}
		$user = array_filter(
			$user,
			static function ( $v ) {
				return array() !== $v && '' !== $v;
			}
		);

		$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
		// Meta accepts events up to 7 days old. Older deliveries are sent with today's time.
		$event_time = ( 'placed' === Settings::get( 'meta_purchase_on' ) && ( time() - $created ) < 6 * DAY_IN_SECONDS ) ? $created : time();

		$event = array(
			'event_name'       => 'Purchase',
			'event_time'       => $event_time,
			'event_id'         => self::event_id( $order ),
			'action_source'    => 'website',
			'event_source_url' => (string) ( $order->get_meta( '_lvk_source_url' ) ? $order->get_meta( '_lvk_source_url' ) : home_url( '/' ) ),
			'user_data'        => $user,
			'custom_data'      => self::custom_data( $order ),
		);
		$body = array( 'data' => array( $event ) );
		$test = trim( (string) Settings::get( 'meta_test_code' ) );
		if ( '' !== $test ) {
			$body['test_event_code'] = $test;
		}
		return $body;
	}

	public static function send_purchase( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_lvk_capi_sent' ) || ! Settings::meta_ready() ) {
			return;
		}
		$pixel = preg_replace( '/\D/', '', (string) Settings::get( 'meta_pixel_id' ) );
		$url   = self::GRAPH . $pixel . '/events?access_token=' . rawurlencode( (string) Settings::get( 'meta_capi_token' ) );
		$res   = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( self::payload( $order ) ),
			)
		);
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( 200 === $code ) {
			$order->update_meta_data( '_lvk_capi_sent', time() );
			$order->save();
			$order->add_order_note( __( 'Purchase sent to Meta (Conversions API).', 'lavkhata' ) );
		} else {
			$err = is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_body( $res );
			Helpers::log( 'Meta CAPI failed for order ' . $order_id . ': ' . $err, 'warning' );
			/* translators: %s: error */
			$order->add_order_note( sprintf( __( 'Meta Conversions API error: %s', 'lavkhata' ), mb_substr( (string) $err, 0, 300 ) ) );
		}
	}
}
