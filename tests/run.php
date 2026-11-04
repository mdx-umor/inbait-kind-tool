<?php
/**
 * Run: php tests/run.php
 */

require __DIR__ . '/bootstrap.php';

use Lavkhata\Cod_Guard;
use Lavkhata\Code128;
use Lavkhata\Courier_Sync;
use Lavkhata\Couriers\Pathao;
use Lavkhata\Couriers\Steadfast;
use Lavkhata\Helpers;
use Lavkhata\Index;
use Lavkhata\Ledger;
use Lavkhata\Meta_Capi;
use Lavkhata\Profit;
use Lavkhata\Restock;
use Lavkhata\Settings;
use Lavkhata\Whatsapp_Confirm;

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = array();
function check( $name, $cond, $detail = '' ) {
	if ( $cond ) {
		++$GLOBALS['passed'];
	} else {
		$GLOBALS['failed'][] = $name . ( $detail ? ' — ' . $detail : '' );
	}
}
function eq( $name, $expected, $actual ) {
	check( $name, $expected === $actual, 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

Profit::init(); // Registers the status-change hook used in the flow tests.

/* ---------- Phone numbers ---------- */
eq( 'phone plain', '01711223344', Helpers::bd_phone( '01711223344' ) );
eq( 'phone +880', '01711223344', Helpers::bd_phone( '+880 1711-223344' ) );
eq( 'phone 880 no plus', '01811223344', Helpers::bd_phone( '8801811223344' ) );
eq( 'phone missing 0', '01911223344', Helpers::bd_phone( '1911223344' ) );
eq( 'phone bangla digits', '01711223344', Helpers::bd_phone( '০১৭১১২২৩৩৪৪' ) );
eq( 'phone invalid operator', '', Helpers::bd_phone( '01211223344' ) );
eq( 'phone too short', '', Helpers::bd_phone( '0171122' ) );
eq( 'intl phone', '8801711223344', Helpers::intl_phone( '01711223344' ) );
eq( 'taka format', '৳1,250', Helpers::taka( 1250 ) );
eq( 'taka negative', '-৳50.50', Helpers::taka( -50.5, 2 ) );

/* ---------- Code 128 ---------- */
$ok = true;
foreach ( Code128::PATTERNS as $i => $p ) {
	$sum = array_sum( str_split( $p ) );
	if ( ( 106 === $i && 13 !== $sum ) || ( 106 !== $i && 11 !== $sum ) ) {
		$ok = false;
	}
}
eq( 'code128 has 107 patterns', 107, count( Code128::PATTERNS ) );
check( 'code128 pattern widths', $ok );
eq( 'code128 unique patterns', 107, count( array_unique( Code128::PATTERNS ) ) );
// "PJJ123C" in set B: (104 + 48·1 + 42·2 + 42·3 + 17·4 + 18·5 + 19·6 + 35·7) mod 103 = 879 mod 103 = 55.
$v = Code128::values( 'PJJ123C' );
eq( 'code128 checksum', 55, $v[ count( $v ) - 2 ] );
check( 'code128 svg', false !== strpos( Code128::svg( '12345' ), '<rect' ) );

/* ---------- Courier status mapping ---------- */
eq( 'sf delivered', 'delivered', Steadfast::normalize( 'delivered' ) );
eq( 'sf partial', 'partial', Steadfast::normalize( 'partial_delivered' ) );
eq( 'sf cancelled = returned', 'returned', Steadfast::normalize( 'cancelled' ) );
eq( 'sf pending approval', 'in_transit', Steadfast::normalize( 'delivered_approval_pending' ) );
eq( 'sf in review', 'booked', Steadfast::normalize( 'in_review' ) );
eq( 'sf hold', 'hold', Steadfast::normalize( 'hold' ) );
eq( 'pathao delivered event', 'delivered', Pathao::normalize( 'order.delivered' ) );
eq( 'pathao partial', 'partial', Pathao::normalize( 'order.partial-delivery' ) );
eq( 'pathao paid return', 'returned', Pathao::normalize( 'order.paid-return' ) );
eq( 'pathao returned status', 'returned', Pathao::normalize( 'Returned' ) );
eq( 'pathao pickup cancelled', 'cancelled', Pathao::normalize( 'order.pickup-cancelled' ) );
eq( 'pathao delivery failed', 'in_transit', Pathao::normalize( 'order.delivery-failed' ) );
eq( 'pathao assigned for delivery', 'in_transit', Pathao::normalize( 'order.assigned-for-delivery' ) );
eq( 'pathao pickup requested', 'booked', Pathao::normalize( 'order.pickup-requested' ) );
eq( 'pathao status name', 'partial', Pathao::normalize( 'Partial_Delivery' ) );
eq( 'pathao on hold', 'hold', Pathao::normalize( 'order.on-hold' ) );

eq( 'wc status delivered', 'completed', Courier_Sync::wc_status_for( 'delivered', 'lvk-shipped' ) );
eq( 'wc status already completed', '', Courier_Sync::wc_status_for( 'delivered', 'completed' ) );
eq( 'wc status returned', 'lvk-returned', Courier_Sync::wc_status_for( 'returned', 'lvk-shipped' ) );
eq( 'wc status in transit from processing', 'lvk-shipped', Courier_Sync::wc_status_for( 'in_transit', 'processing' ) );
eq( 'wc status in transit already shipped', '', Courier_Sync::wc_status_for( 'in_transit', 'lvk-shipped' ) );

/* ---------- Steadfast fraud-check parsing ---------- */
$r = Steadfast::parse_record( array( 'delivered_count' => 18, 'cancelled_count' => 2 ) );
eq( 'sf record counts', array( 'ok' => true, 'delivered' => 18, 'returned' => 2, 'total' => 20 ), $r );
$r = Steadfast::parse_record( array( 'total_delivered' => 5, 'total_cancelled' => 5 ) );
eq( 'sf record old keys', 10, $r['total'] );
$r = Steadfast::parse_record( array( 'delivery_ratio' => 50, 'volume_band' => 'medium' ) );
check( 'sf record estimate', $r['ok'] && 10 === $r['total'] && 5 === $r['delivered'] && ! empty( $r['estimated'] ) );
eq( 'sf record empty', array( 'ok' => false ), Steadfast::parse_record( array( 'message' => 'Not found' ) ) );

/* ---------- Webhook auth ---------- */
Settings::update( array( 'steadfast_webhook_token' => 'tok123', 'pathao_webhook_secret' => 'sec456' ) );
check( 'sf bearer ok', Steadfast::verify_webhook( 'Bearer tok123', '', '{}' ) );
check( 'sf bearer wrong', ! Steadfast::verify_webhook( 'Bearer nope', '', '{}' ) );
check( 'sf hmac ok', Steadfast::verify_webhook( '', hash_hmac( 'sha256', '{"a":1}', 'tok123' ), '{"a":1}' ) );
check( 'sf hmac wrong body', ! Steadfast::verify_webhook( '', hash_hmac( 'sha256', '{"a":1}', 'tok123' ), '{"a":2}' ) );
check( 'sf no header', ! Steadfast::verify_webhook( '', '', '{}' ) );
check( 'pathao secret ok', Pathao::verify_webhook( 'sec456' ) );
check( 'pathao secret wrong', ! Pathao::verify_webhook( 'x' ) );

/* ---------- COD risk ---------- */
$lvl = Cod_Guard::risk_level( array( 'steadfast' => array( 'ok' => true, 'delivered' => 10, 'returned' => 15, 'total' => 25 ) ), 3, 70 );
eq( 'risk risky', 'risky', $lvl['level'] );
eq( 'risk ratio', 40.0, $lvl['ratio'] );
$lvl = Cod_Guard::risk_level( array( 'steadfast' => array( 'ok' => true, 'delivered' => 19, 'returned' => 1, 'total' => 20 ), 'pathao' => array( 'ok' => true, 'delivered' => 4, 'returned' => 0, 'total' => 4 ) ), 3, 70 );
eq( 'risk good', 'good', $lvl['level'] );
eq( 'risk combined total', 24, $lvl['total'] );
$lvl = Cod_Guard::risk_level( array( 'steadfast' => array( 'ok' => true, 'delivered' => 0, 'returned' => 1, 'total' => 1 ) ), 3, 70 );
eq( 'risk too few parcels', 'few', $lvl['level'] );
$lvl = Cod_Guard::risk_level( array( 'steadfast' => array( 'ok' => false ), 'store' => array( 'ok' => true, 'delivered' => 0, 'returned' => 0, 'total' => 0 ) ), 3, 70 );
eq( 'risk new', 'new', $lvl['level'] );
$lvl = Cod_Guard::risk_level( array( 'steadfast' => array( 'ok' => true, 'delivered' => 30, 'returned' => 2, 'total' => 32 ), 'store' => array( 'ok' => true, 'delivered' => 0, 'returned' => 3, 'total' => 3 ) ), 3, 70 );
eq( 'risk: returned 3 of 3 from this shop', 'risky', $lvl['level'] );
eq( 'risk badge uses courier numbers', 32, $lvl['total'] );

Settings::update( array( 'cod_guard_enabled' => 1, 'cod_action' => 'advance', 'cod_new_customer' => 'allow' ) );
eq( 'decision risky', 'advance', Cod_Guard::decision( array( 'level' => 'risky' ) ) );
eq( 'decision new allowed', 'allow', Cod_Guard::decision( array( 'level' => 'new' ) ) );
Settings::update( array( 'cod_new_customer' => 'advance' ) );
eq( 'decision new advance', 'advance', Cod_Guard::decision( array( 'level' => 'new' ) ) );
Settings::update( array( 'cod_action' => 'full' ) );
eq( 'decision full', 'full', Cod_Guard::decision( array( 'level' => 'risky' ) ) );
Settings::update( array( 'cod_guard_enabled' => 0 ) );
eq( 'decision disabled', 'allow', Cod_Guard::decision( array( 'level' => 'risky' ) ) );
Settings::update( array( 'cod_guard_enabled' => 1, 'cod_action' => 'advance', 'cod_new_customer' => 'allow' ) );

Settings::update( array( 'cod_advance_type' => 'fixed', 'cod_advance_value' => 150 ) );
eq( 'advance fixed', 150.0, Cod_Guard::advance_amount( 1200, 70 ) );
eq( 'advance never above total', 100.0, Cod_Guard::advance_amount( 100, 70 ) );
Settings::update( array( 'cod_advance_type' => 'percent', 'cod_advance_value' => 20 ) );
eq( 'advance percent', 240.0, Cod_Guard::advance_amount( 1200, 70 ) );
Settings::update( array( 'cod_advance_type' => 'shipping', 'cod_advance_value' => 150 ) );
eq( 'advance shipping', 70.0, Cod_Guard::advance_amount( 1200, 70 ) );
eq( 'advance shipping free falls back', 150.0, Cod_Guard::advance_amount( 1200, 0 ) );
Settings::update( array( 'cod_advance_type' => 'fixed', 'cod_advance_value' => 150 ) );

/* ---------- Profit breakdown ---------- */
$s = array_merge( Settings::defaults(), array( 'cost_cod_fee_pct' => 1, 'cost_gateway_fee_pct' => 1.5, 'cost_packaging' => 15, 'cost_return_pct' => 50, 'advance_kept_on_return' => 1 ) );
$b = Profit::breakdown( array( 'outcome' => 'delivered', 'total' => 1500, 'cogs' => 700, 'prepaid' => 0, 'cod' => 1500, 'delivery_charge' => 70 ), $s );
eq( 'delivered lines', array( 'revenue' => 1500.0, 'cogs' => 700.0, 'delivery' => 70.0, 'cod_fee' => 15.0, 'packaging' => 15.0 ), $b );
eq( 'delivered net', 700.0, Profit::net_of( $b ) );

$b = Profit::breakdown( array( 'outcome' => 'returned', 'total' => 1500, 'cogs' => 700, 'prepaid' => 0, 'cod' => 1500, 'delivery_charge' => 130 ), $s );
eq( 'returned lines', array( 'delivery' => 130.0, 'return_fee' => 65.0, 'packaging' => 15.0 ), $b );
eq( 'returned net', -210.0, Profit::net_of( $b ) );

$b = Profit::breakdown( array( 'outcome' => 'returned', 'total' => 1500, 'prepaid' => 150, 'delivery_charge' => 130, 'is_advance' => true ), $s );
eq( 'returned with advance kept', -60.0 - 2.25, Profit::net_of( $b ) );

$b = Profit::breakdown( array( 'outcome' => 'partial', 'total' => 2000, 'cogs' => 1000, 'prepaid' => 0, 'cod' => 2000, 'collected' => 1000, 'delivery_charge' => 70 ), $s );
eq( 'partial revenue', 1000.0, $b['revenue'] );
eq( 'partial cogs share', 500.0, $b['cogs'] );
eq( 'partial cod fee on collected', 10.0, $b['cod_fee'] );

$b = Profit::breakdown( array( 'outcome' => 'delivered', 'total' => 1000, 'cogs' => 400, 'prepaid' => 1000, 'cod' => 0, 'delivery_charge' => 70 ), $s );
eq( 'prepaid gateway fee', 15.0, $b['gateway_fee'] );
check( 'prepaid no cod fee', ! isset( $b['cod_fee'] ) );

/* ---------- Ledger summary & ads ---------- */
eq( 'ad usd with vat', 2875.0, Ledger::ad_amount_bdt( 20, 'USD', 15, 125 ) );
eq( 'ad bdt with vat', 1150.0, Ledger::ad_amount_bdt( 1000, 'BDT', 15, 125 ) );
$sum = Ledger::summarize( array( 'revenue' => 10000, 'cogs' => 4000, 'delivery' => 1000, 'cod_fee' => 100, 'return_fee' => 200, 'packaging' => 150, 'ad_spend' => 2000, 'expense' => 500 ), 20 );
eq( 'summary before ads', 4550.0, $sum['profit_before_ads'] );
eq( 'summary net', 2050.0, $sum['net'] );
eq( 'break-even cpp', 227.5, $sum['break_even_cpp'] );
eq( 'actual cpp', 100.0, $sum['actual_cpp'] );
eq( 'ads profitable', true, $sum['ads_profitable'] );

/* ---------- Restock ---------- */
$p = Restock::plan( 20, 60, 30, 7, 30 );
eq( 'restock per day', 2.0, $p['per_day'] );
eq( 'restock days left', 10.0, $p['days_left'] );
eq( 'restock status soon', 'soon', $p['status'] );
eq( 'restock qty', 54, $p['qty'] );
eq( 'restock now', 'now', Restock::plan( 5, 60, 30, 7, 30 )['status'] );
eq( 'restock out', 'out', Restock::plan( 0, 60, 30, 7, 30 )['status'] );
eq( 'restock no sales', 'no_sales', Restock::plan( 10, 0, 30, 7, 30 )['status'] );

/* ---------- Full flow: book → webhook → ledger → P&L ---------- */
Settings::update( array( 'steadfast_api_key' => 'k', 'steadfast_secret_key' => 's', 'mode' => 'auto', 'cost_packaging' => 15, 'cost_cod_fee_pct' => 1, 'cost_delivery_inside' => 70, 'cost_return_pct' => 50, 'meta_purchase_on' => 'delivered', 'meta_pixel_id' => '123', 'meta_capi_token' => 'tok' ) );

$GLOBALS['lvk_test']['products'][11] = new Fake_Product( 11, 400 );
$o = new WC_Order( 501, array( 'total' => 1270 ) );
$o->items = array( 1 => new Fake_Item( 'Kurti', 2, $GLOBALS['lvk_test']['products'][11], 1200 ) );
Index::touch( $o );

fake_http( 200, array( 'status' => 200, 'consignment' => array( 'consignment_id' => 9988, 'tracking_code' => 'SFR1', 'status' => 'in_review' ) ) );
$res = Courier_Sync::book( $o, 'steadfast' );
check( 'book ok', $res['ok'], $res['message'] );
eq( 'book sends cod', 1270, json_decode( $GLOBALS['lvk_test']['requests'][0][1]['body'], true )['cod_amount'] );
eq( 'book sends phone', '01711000000', json_decode( $GLOBALS['lvk_test']['requests'][0][1]['body'], true )['recipient_phone'] );
eq( 'book auth header', 'k', $GLOBALS['lvk_test']['requests'][0][1]['headers']['Api-Key'] );
eq( 'book saved consignment', '9988', $o->get_meta( '_lvk_cid' ) );
eq( 'auto mode marks shipped', 'lvk-shipped', $o->get_status() );
eq( 'index has consignment', '9988', Index::get( 501 )['consignment'] );
$again = Courier_Sync::book( $o, 'steadfast' );
check( 'cannot double book', ! $again['ok'] );

// Webhook with wrong token is rejected.
$bad = Courier_Sync::steadfast_webhook( new WP_REST_Request( array( 'Authorization' => 'Bearer wrong' ), '{}' ) );
eq( 'webhook rejects bad token', 401, $bad->status );

$payload = json_encode( array( 'notification_type' => 'delivery_status', 'consignment_id' => 9988, 'invoice' => '501', 'cod_amount' => 1270, 'status' => 'delivered', 'delivery_charge' => 60 ) );
$resp    = Courier_Sync::steadfast_webhook( new WP_REST_Request( array( 'Authorization' => 'Bearer tok123', 'Content-Type' => 'application/json' ), $payload ) );
eq( 'webhook ok', 200, $resp->status );
eq( 'order completed', 'completed', $o->get_status() );
eq( 'index outcome delivered', 'delivered', Index::get( 501 )['outcome'] );
$entries = Ledger::order_entries( 501 );
$by      = array();
foreach ( $entries as $e ) {
	$by[ $e['kind'] ] = (float) $e['amount'];
}
eq( 'ledger revenue', 1270.0, $by['revenue'] );
eq( 'ledger cogs', 800.0, $by['cogs'] );
eq( 'ledger real delivery charge', 60.0, $by['delivery'] );
eq( 'ledger cod fee', 12.7, $by['cod_fee'] );
eq( 'order profit', 1270 - 800 - 60 - 12.7 - 15.0, (float) $o->get_meta( '_lvk_profit' ) );
check( 'capi queued once', 1 === count( array_filter( $GLOBALS['lvk_test']['scheduled'], function ( $s ) { return 'lvk_capi_send' === $s[0]; } ) ) );

// Duplicate webhook does not double the ledger.
Courier_Sync::steadfast_webhook( new WP_REST_Request( array( 'Authorization' => 'Bearer tok123' ), $payload ) );
eq( 'ledger idempotent', count( $entries ), count( Ledger::order_entries( 501 ) ) );

// Report-only mode: courier says returned, order status stays, note explains.
Settings::update( array( 'mode' => 'report' ) );
$o2 = new WC_Order( 502, array( 'total' => 990, 'billing_phone' => '01811000000', 'billing_state' => 'BD-01', 'billing_city' => 'Bogura' ) );
$o2->items = array( 1 => new Fake_Item( 'Cap', 1, new Fake_Product( 12, 300 ), 900 ) );
Index::touch( $o2 );
fake_http( 200, array( 'status' => 200, 'consignment' => array( 'consignment_id' => 7777, 'tracking_code' => 'SFR2' ) ) );
Courier_Sync::book( $o2, 'steadfast' );
eq( 'report mode keeps processing', 'processing', $o2->get_status() );
Courier_Sync::apply( $o2, 'returned', 'cancelled' );
eq( 'report mode no status change', 'processing', $o2->get_status() );
check( 'report mode note', false !== strpos( end( $o2->notes ), 'Report-only' ) );
$by2 = array();
foreach ( Ledger::order_entries( 502 ) as $e ) {
	$by2[ $e['kind'] ] = (float) $e['amount'];
}
eq( 'returned outside dhaka delivery', 130.0, $by2['delivery'] );
eq( 'returned fee', 65.0, $by2['return_fee'] );
check( 'returned no revenue', ! isset( $by2['revenue'] ) );

// Customer history from our own index.
$h = Index::history( '01811000000' );
eq( 'history returned', 1, $h['returned'] );
$h = Index::history( '+8801711000000' );
eq( 'history delivered', 1, $h['delivered'] );

// Pathao handshake + signature.
$hs = Courier_Sync::pathao_webhook( new WP_REST_Request( array(), json_encode( array( 'event' => 'webhook_integration' ) ) ) );
eq( 'pathao handshake 202', 202, $hs->status );
eq( 'pathao handshake header', 'sec456', $hs->headers['X-Pathao-Merchant-Webhook-Integration-Secret'] );
$nope = Courier_Sync::pathao_webhook( new WP_REST_Request( array( 'X-Pathao-Signature' => 'bad' ), json_encode( array( 'event' => 'order.delivered', 'consignment_id' => 'X' ) ) ) );
eq( 'pathao bad signature', 401, $nope->status );

// Month P&L from the ledger.
Ledger::add( 0, '2026-11-03', 'ad_spend', 575, 'Meta $4 + 15% VAT' );
$t   = Ledger::totals( '2026-11-01', '2026-12-01' );
$cnt = Index::counts_between( '2026-11-01 00:00:00', '2026-12-01 00:00:00' );
eq( 'orders placed this month', 2, $cnt['orders'] );
$sum = Ledger::summarize( $t, $cnt['orders'] );
$exp = ( 1270 - 800 - 60 - 12.7 - 15 ) + ( -130 - 65 - 15 ) - 575;
eq( 'month net profit', round( $exp, 2 ), round( $sum['net'], 2 ) );
$sc = Ledger::settled_counts( '2026-11-01', '2026-12-01' );
eq( 'settled counts', array( 'delivered' => 1, 'returned' => 1 ), $sc );

/* ---------- Meta payload ---------- */
$o->meta['_lvk_fbp'] = 'fb.1.123.456';
$pl = Meta_Capi::payload( $o );
$ev = $pl['data'][0];
eq( 'capi event', 'Purchase', $ev['event_name'] );
eq( 'capi event id', 'lvk_order_501', $ev['event_id'] );
eq( 'capi phone hashed', hash( 'sha256', '8801711000000' ), $ev['user_data']['ph'][0] );
eq( 'capi value', 1270.0, (float) $ev['custom_data']['value'] );
eq( 'capi fbp', 'fb.1.123.456', $ev['user_data']['fbp'] );
eq( 'capi currency', 'BDT', $ev['custom_data']['currency'] );

/* ---------- WhatsApp ---------- */
$link = Whatsapp_Confirm::wa_link( $o );
check( 'wa link number', 0 === strpos( $link, 'https://wa.me/8801711000000?text=' ) );
check( 'wa link has confirm url', false !== strpos( urldecode( $link ), 'lvk_confirm=501' ) );

/* ---------- Checkout gateways ---------- */
Settings::update( array( 'cod_guard_enabled' => 1, 'cod_action' => 'advance', 'cod_use_steadfast' => 1, 'cod_use_pathao' => 0, 'cod_use_store' => 1, 'cod_min_parcels' => 3, 'cod_min_ratio' => 70, 'cod_new_customer' => 'allow' ) );
$all = array( 'cod' => 1, 'lvk_advance' => 1, 'bkash' => 1 );

// Cart page: no lookups at all, advance hidden.
$GLOBALS['lvk_test']['is_checkout'] = false;
$_POST = array( 'post_data' => 'billing_phone=01999000111' );
$before = count( $GLOBALS['lvk_test']['requests'] );
eq( 'cart page gateways', array( 'cod' => 1, 'bkash' => 1 ), Cod_Guard::filter_gateways( $all ) );
eq( 'cart page makes no courier calls', $before, count( $GLOBALS['lvk_test']['requests'] ) );

// Checkout, risky phone (Steadfast: 4 delivered / 16 cancelled).
$GLOBALS['lvk_test']['is_checkout'] = true;
fake_http( 200, array( 'delivered_count' => 4, 'cancelled_count' => 16 ) );
eq( 'risky checkout hides COD', array( 'lvk_advance' => 1, 'bkash' => 1 ), Cod_Guard::filter_gateways( $all ) );
check( 'fraud check url', false !== strpos( end( $GLOBALS['lvk_test']['requests'] )[0], '/fraud_check/score/01999000111' ) );
$before = count( $GLOBALS['lvk_test']['requests'] );
Cod_Guard::filter_gateways( $all );
eq( 'record is cached', $before, count( $GLOBALS['lvk_test']['requests'] ) );

// Classic checkout submit with COD is refused for that phone.
$errors = new class() { public $e = array(); public function add( $c, $m ) { $this->e[] = $m; } };
Cod_Guard::validate_classic( array( 'payment_method' => 'cod', 'billing_phone' => '01999000111' ), $errors );
eq( 'cod submit blocked', 1, count( $errors->e ) );

// Checkout, good phone.
$_POST = array( 'post_data' => 'billing_phone=01555000222' );
fake_http( 200, array( 'delivered_count' => 30, 'cancelled_count' => 1 ) );
eq( 'good checkout keeps COD', array( 'cod' => 1, 'bkash' => 1 ), Cod_Guard::filter_gateways( $all ) );

// Courier API down: fail open (customer can still use COD).
$_POST = array( 'post_data' => 'billing_phone=01333000444' );
fake_http( 500, array( 'message' => 'down' ) );
eq( 'api down allows COD', array( 'cod' => 1, 'bkash' => 1 ), Cod_Guard::filter_gateways( $all ) );

// "Full" mode: no COD at all for risky.
Settings::update( array( 'cod_action' => 'full' ) );
$_POST = array( 'post_data' => 'billing_phone=01999000111' );
eq( 'full mode leaves online only', array( 'bkash' => 1 ), Cod_Guard::filter_gateways( $all ) );
Settings::update( array( 'cod_action' => 'advance' ) );
$_POST = array();

/* ---------- Result ---------- */
$total = $GLOBALS['passed'] + count( $GLOBALS['failed'] );
echo "{$GLOBALS['passed']} / {$total} checks passed\n";
foreach ( $GLOBALS['failed'] as $f ) {
	echo "FAIL: {$f}\n";
}
exit( $GLOBALS['failed'] ? 1 : 0 );
