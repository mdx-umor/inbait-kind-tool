<?php
/**
 * Demo data for the Lavkhata WordPress Playground.
 * Runs once after WooCommerce and Lavkhata are installed.
 */
require_once '/wordpress/wp-load.php';

// Shop basics: Bangladesh, taka, cash on delivery, classic checkout.
update_option( 'blogname', 'Demo Fashion BD' );
update_option( 'woocommerce_default_country', 'BD:BD-13' );
update_option( 'woocommerce_currency', 'BDT' );
update_option( 'woocommerce_currency_pos', 'left' );
update_option( 'woocommerce_price_num_decimals', '0' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true, 'completed' => true ) );
update_option( 'woocommerce_task_list_hidden', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'title' => 'Cash on delivery', 'description' => 'Pay with cash when the parcel arrives.' ) );
update_option( 'woocommerce_lvk_advance_settings', array( 'enabled' => 'yes', 'bkash' => '01700000000', 'nagad' => '01800000000', 'account_type' => 'personal' ) );

// Classic checkout page (the advance payment option needs it).
$checkout_id = wc_get_page_id( 'checkout' );
if ( $checkout_id > 0 ) {
	wp_update_post( array( 'ID' => $checkout_id, 'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->' ) );
}

Lavkhata\Install::create_tables();
Lavkhata\Install::ensure_secrets();
Lavkhata\Settings::update(
	array(
		'mode'          => 'auto',
		'label_phone'   => '01700000000',
		'cod_use_steadfast' => 0,
		'cod_use_pathao'    => 0,
	)
);

// Products with cost prices and stock.
$catalog = array(
	array( 'Cotton Kurti', 1290, 620, 14 ),
	array( 'Printed Three-Piece', 2450, 1350, 6 ),
	array( 'Denim Cap', 490, 210, 40 ),
	array( 'Leather Wallet', 890, 380, 3 ),
	array( 'Silk Hijab', 650, 260, 25 ),
);
$products = array();
foreach ( $catalog as $c ) {
	$p = new WC_Product_Simple();
	$p->set_name( $c[0] );
	$p->set_regular_price( (string) $c[1] );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $c[3] );
	$p->set_status( 'publish' );
	$p->update_meta_data( '_lvk_cost', (string) $c[2] );
	$p->save();
	$products[] = $p;
}

// Orders: some delivered, some returned, some with the courier, some new.
$people = array(
	array( 'Rahim', 'Uddin', '01711000001', 'Dhanmondi 27, Dhaka', 'Dhaka', 'BD-13' ),
	array( 'Nusrat', 'Jahan', '01811000002', 'Mirpur 10, Dhaka', 'Dhaka', 'BD-13' ),
	array( 'Karim', 'Hossain', '01911000003', 'Agrabad, Chattogram', 'Chattogram', 'BD-10' ),
	array( 'Sadia', 'Akter', '01611000004', 'Zindabazar, Sylhet', 'Sylhet', 'BD-60' ),
	array( 'Fahim', 'Rahman', '01511000005', 'Satmasjid Road, Dhaka', 'Dhaka', 'BD-13' ),
	array( 'Tania', 'Sultana', '01311000006', 'Shaheb Bazar, Rajshahi', 'Rajshahi', 'BD-69' ),
);
// [person, product index, qty, days ago, outcome]
$plan = array(
	array( 0, 0, 2, 20, 'delivered' ), array( 1, 1, 1, 18, 'delivered' ), array( 2, 2, 3, 16, 'returned' ),
	array( 3, 4, 2, 14, 'delivered' ), array( 4, 3, 1, 12, 'delivered' ), array( 5, 0, 1, 11, 'returned' ),
	array( 0, 4, 1, 9, 'delivered' ), array( 1, 2, 2, 8, 'delivered' ), array( 2, 1, 1, 6, 'returned' ),
	array( 3, 0, 1, 4, 'in_transit' ), array( 4, 2, 2, 3, 'in_transit' ), array( 5, 4, 1, 2, 'booked' ),
	array( 0, 1, 1, 1, 'new' ), array( 2, 3, 1, 0, 'new' ), array( 1, 0, 1, 0, 'new' ),
);
$cid = 880001;
foreach ( $plan as $row ) {
	list( $pi, $prod, $qty, $ago, $outcome ) = $row;
	$person = $people[ $pi ];
	$order  = wc_create_order();
	$item_id = $order->add_product( $products[ $prod ], $qty );
	$item    = $order->get_item( $item_id );
	if ( $item ) {
		$item->add_meta_data( '_lvk_unit_cost', $products[ $prod ]->get_meta( '_lvk_cost' ), true );
		$item->save();
	}
	$addr = array(
		'first_name' => $person[0],
		'last_name'  => $person[1],
		'phone'      => $person[2],
		'address_1'  => $person[3],
		'city'       => $person[4],
		'state'      => $person[5],
		'country'    => 'BD',
	);
	$order->set_address( $addr, 'billing' );
	$order->set_address( $addr, 'shipping' );
	$ship = new WC_Order_Item_Shipping();
	$ship->set_method_title( 'BD-13' === $person[5] ? 'Inside Dhaka' : 'Outside Dhaka' );
	$ship->set_total( 'BD-13' === $person[5] ? 70 : 130 );
	$order->add_item( $ship );
	$order->set_payment_method( 'cod' );
	$order->set_payment_method_title( 'Cash on delivery' );
	$order->calculate_totals( false );
	$order->set_date_created( time() - $ago * DAY_IN_SECONDS );
	$order->set_status( 'processing' );
	$order->save();
	Lavkhata\Index::touch( $order );

	if ( 'new' === $outcome ) {
		continue;
	}
	// Pretend it was booked with Steadfast, then let the plugin process the courier updates.
	$order->update_meta_data( '_lvk_courier', 'steadfast' );
	$order->update_meta_data( '_lvk_cid', (string) $cid );
	$order->update_meta_data( '_lvk_tracking', 'SFDEMO' . $cid );
	$order->update_meta_data( '_lvk_courier_status', 'booked' );
	$order->save();
	Lavkhata\Index::set( $order->get_id(), array( 'courier' => 'steadfast', 'consignment' => (string) $cid, 'courier_status' => 'booked' ) );
	++$cid;
	$order->update_status( 'lvk-shipped', 'Handed to courier (demo).' );
	if ( 'in_transit' === $outcome ) {
		Lavkhata\Courier_Sync::apply( $order, 'in_transit', 'in_review' );
	} elseif ( 'delivered' === $outcome ) {
		Lavkhata\Courier_Sync::apply( $order, 'delivered', 'delivered', array( 'charge' => 'BD-13' === $person[5] ? 60 : 110 ) );
	} elseif ( 'returned' === $outcome ) {
		Lavkhata\Courier_Sync::apply( $order, 'returned', 'cancelled', array( 'charge' => 'BD-13' === $person[5] ? 60 : 110 ) );
	}
}

// Delivery record for the new orders (from this shop's own history).
foreach ( wc_get_orders( array( 'status' => 'processing', 'limit' => 10 ) ) as $o ) {
	$a = Lavkhata\Cod_Guard::assess( $o->get_billing_phone(), $o->get_id(), true );
	if ( $a ) {
		Lavkhata\Cod_Guard::store_record( $o, $a );
	}
}

// Ad spend and an expense for this month.
Lavkhata\Ledger::add( 0, current_time( 'Y-m-d' ), 'ad_spend', Lavkhata\Ledger::ad_amount_bdt( 12, 'USD', 15, 125 ), 'Meta $12.00 + 15% VAT – Demo campaign' );
Lavkhata\Ledger::add( 0, current_time( 'Y-m-d' ), 'expense', 800, 'Packaging materials' );

update_option( 'lvk_backfill_done', time(), false );
