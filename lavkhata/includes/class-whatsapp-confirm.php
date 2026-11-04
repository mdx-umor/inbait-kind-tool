<?php
/**
 * One-tap order confirmation over WhatsApp (click-to-chat, no API needed).
 *
 * @package Lavkhata
 */

namespace Lavkhata;

defined( 'ABSPATH' ) || exit;

class Whatsapp_Confirm {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'maybe_hold' ), 30, 3 );
	}

	public static function confirm_url( \WC_Order $order ) {
		return add_query_arg(
			array(
				'lvk_confirm' => $order->get_id(),
				'k'           => Helpers::sign( $order->get_id() . '|' . $order->get_order_key() ),
			),
			home_url( '/' )
		);
	}

	public static function message( \WC_Order $order ) {
		$replace = array(
			'{name}'  => $order->get_billing_first_name() ? $order->get_billing_first_name() : Helpers::customer_name( $order ),
			'{order}' => $order->get_order_number(),
			'{total}' => Helpers::taka( $order->get_total() ),
			'{cod}'   => Helpers::taka( Helpers::cod_amount( $order ) ),
			'{shop}'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{items}' => Helpers::items_text( $order, 300 ),
			'{link}'  => self::confirm_url( $order ),
		);
		return strtr( Settings::wa_template(), $replace );
	}

	/** wa.me link that opens WhatsApp with the message ready to send. */
	public static function wa_link( \WC_Order $order ) {
		$phone = Helpers::intl_phone( $order->get_billing_phone() );
		if ( '' === $phone ) {
			return '';
		}
		return 'https://wa.me/' . $phone . '?text=' . rawurlencode( self::message( $order ) );
	}

	/** New COD orders wait "on hold" until the customer confirms (optional). */
	public static function maybe_hold( $order_id, $posted, $order ) {
		if ( ! Settings::get( 'hold_until_confirmed' ) || ! $order instanceof \WC_Order ) {
			return;
		}
		if ( 'cod' === $order->get_payment_method() && 'processing' === $order->get_status() ) {
			$order->update_status( 'on-hold', __( 'Waiting for the customer to confirm on WhatsApp.', 'lavkhata' ) );
		}
	}

	public static function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Signed link, no nonce possible.
		if ( empty( $_GET['lvk_confirm'] ) || empty( $_GET['k'] ) ) {
			return;
		}
		$order_id = absint( $_GET['lvk_confirm'] );
		$key      = sanitize_text_field( wp_unslash( $_GET['k'] ) );
		// phpcs:enable
		$order = wc_get_order( $order_id );
		$valid = $order && hash_equals( Helpers::sign( $order->get_id() . '|' . $order->get_order_key() ), $key );

		if ( $valid && ! $order->get_meta( '_lvk_confirmed' ) && ! in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			$order->update_meta_data( '_lvk_confirmed', time() );
			$order->save();
			if ( 'on-hold' === $order->get_status() && 'cod' === $order->get_payment_method() ) {
				$order->update_status( 'processing', __( 'Customer confirmed the order on WhatsApp.', 'lavkhata' ) );
			} else {
				$order->add_order_note( __( 'Customer confirmed the order on WhatsApp.', 'lavkhata' ) );
			}
		}
		self::render( $valid ? $order : null );
		exit;
	}

	private static function render( $order ) {
		nocache_headers();
		status_header( $order ? 200 : 404 );
		$shop  = get_bloginfo( 'name' );
		$title = $order ? __( 'Order confirmed', 'lavkhata' ) : __( 'Link not valid', 'lavkhata' );
		/* translators: 1: order number, 2: COD amount */
		$body = $order ? sprintf( __( 'Thank you! Order #%1$s is confirmed. We will send it soon. Please keep %2$s ready for the delivery person.', 'lavkhata' ), $order->get_order_number(), Helpers::taka( Helpers::cod_amount( $order ) ) ) : __( 'This confirmation link is not valid or has expired. Please contact the shop.', 'lavkhata' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( $title . ' · ' . $shop ); ?></title>
<style>
body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans Bengali",sans-serif;background:#f4f6f4;color:#1c2a22;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}
.card{background:#fff;border-radius:16px;padding:32px 24px;max-width:420px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.06)}
.tick{width:64px;height:64px;border-radius:50%;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;font-size:32px;color:#fff;background:<?php echo $order ? '#1a8f4e' : '#b54a3c'; ?>}
h1{font-size:22px;margin:0 0 8px}p{line-height:1.6;margin:0 0 20px;color:#3d4d44}
a{display:inline-block;background:#1c2a22;color:#fff;text-decoration:none;padding:12px 20px;border-radius:10px}
</style>
</head>
<body><div class="card"><div class="tick"><?php echo $order ? '&#10003;' : '!'; ?></div><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $body ); ?></p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $shop ); ?></a></div></body>
</html>
		<?php
	}
}
