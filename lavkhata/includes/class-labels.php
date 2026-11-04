<?php
/**
 * Printable thermal labels with the items on them.
 *
 * @package Lavkhata
 */

namespace Lavkhata;

use Lavkhata\Couriers\Courier;

defined( 'ABSPATH' ) || exit;

class Labels {

	public static function url( array $order_ids ) {
		return add_query_arg(
			array(
				'action'   => 'lvk_labels',
				'orders'   => implode( ',', array_map( 'absint', $order_ids ) ),
				'_wpnonce' => wp_create_nonce( 'lvk_labels' ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public static function render( array $order_ids ) {
		$size = (string) Settings::get( 'label_size' );
		$dims = array(
			'75x100'  => array( '75mm', '100mm' ),
			'100x150' => array( '100mm', '150mm' ),
			'58x40'   => array( '58mm', '40mm' ),
		);
		$d    = isset( $dims[ $size ] ) ? $dims[ $size ] : $dims['75x100'];
		$shop = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$tel  = (string) Settings::get( 'label_phone' );
		$small = '58x40' === $size;
		?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?php esc_html_e( 'Shipping labels', 'lavkhata' ); ?></title>
<style>
@page { size: <?php echo esc_html( $d[0] . ' ' . $d[1] ); ?>; margin: 0; }
* { box-sizing: border-box; }
body { margin: 0; font-family: Arial, "Noto Sans Bengali", sans-serif; color: #000; }
.label { width: <?php echo esc_html( $d[0] ); ?>; height: <?php echo esc_html( $d[1] ); ?>; padding: 3mm; page-break-after: always; overflow: hidden; display: flex; flex-direction: column; gap: 1.5mm; font-size: <?php echo $small ? '8pt' : '9pt'; ?>; }
.row { display: flex; justify-content: space-between; align-items: baseline; gap: 2mm; }
.shop { font-weight: 700; font-size: 1.15em; }
.muted { font-size: .85em; }
.box { border: .3mm solid #000; border-radius: 1mm; padding: 1.5mm; }
.to-name { font-weight: 700; font-size: 1.15em; }
.cod { text-align: center; font-weight: 700; font-size: <?php echo $small ? '12pt' : '16pt'; ?>; border: .5mm solid #000; padding: 1mm; }
.items { margin: 0; padding-left: 4mm; }
.items li { margin: 0; }
.bc { text-align: center; }
.bc svg { height: 12mm; }
.bc small { display: block; font-family: monospace; letter-spacing: .5mm; }
.noprint { padding: 12px; font-family: system-ui, sans-serif; }
@media print { .noprint { display: none; } }
</style>
</head>
<body>
<div class="noprint"><button onclick="window.print()"><?php esc_html_e( 'Print', 'lavkhata' ); ?></button></div>
		<?php
		foreach ( $order_ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$cid     = (string) $order->get_meta( '_lvk_cid' );
			$courier = Courier::get( $order->get_meta( '_lvk_courier' ) );
			$code    = '' !== $cid ? $cid : (string) $order->get_order_number();
			?>
<div class="label">
	<div class="row"><span class="shop"><?php echo esc_html( $shop ); ?></span><span class="muted"><?php echo esc_html( $tel ); ?></span></div>
	<div class="bc"><?php echo Code128::svg( $code ); // phpcs:ignore WordPress.Security.EscapeOutput -- Built from escaped parts. ?><small><?php echo esc_html( ( $courier ? $courier->name() . ' · ' : '' ) . $code ); ?></small></div>
	<div class="box">
		<div class="to-name"><?php echo esc_html( Helpers::customer_name( $order ) ); ?></div>
		<div><strong><?php echo esc_html( Helpers::bd_phone( $order->get_billing_phone() ) ? Helpers::bd_phone( $order->get_billing_phone() ) : $order->get_billing_phone() ); ?></strong></div>
		<div><?php echo esc_html( Helpers::address_line( $order ) ); ?></div>
	</div>
	<div class="cod"><?php echo esc_html( Helpers::cod_amount( $order ) > 0 ? sprintf( /* translators: %s: amount */ __( 'COD %s', 'lavkhata' ), Helpers::taka( Helpers::cod_amount( $order ) ) ) : __( 'PAID', 'lavkhata' ) ); ?></div>
			<?php if ( Settings::get( 'label_show_items' ) && ! $small ) : ?>
	<ul class="items">
				<?php foreach ( $order->get_items() as $item ) : ?>
		<li><?php echo esc_html( $item->get_name() . ' × ' . $item->get_quantity() ); ?></li>
				<?php endforeach; ?>
	</ul>
			<?php endif; ?>
	<div class="row muted"><span><?php echo esc_html( '#' . $order->get_order_number() ); ?></span><span><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd M Y' ) : '' ); ?></span></div>
			<?php if ( $order->get_customer_note() && ! $small ) : ?>
	<div class="muted"><?php echo esc_html( mb_substr( $order->get_customer_note(), 0, 120 ) ); ?></div>
			<?php endif; ?>
</div>
			<?php
		}
		?>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 300); });</script>
</body>
</html>
		<?php
	}
}
