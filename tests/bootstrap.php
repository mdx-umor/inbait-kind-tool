<?php
/**
 * Tiny stand-ins for WordPress and WooCommerce so the plugin's logic can be
 * tested without a full WordPress install. Database calls run on SQLite.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ENT_QUOTES_WP', ENT_QUOTES );

$GLOBALS['lvk_test'] = array(
	'options'    => array(),
	'transients' => array(),
	'actions'    => array(),
	'orders'     => array(),
	'products'   => array(),
	'scheduled'  => array(),
	'http'       => array(), // Queue of fake HTTP responses.
	'requests'   => array(),
	'now'        => '2026-11-04 10:00:00',
);

/* ---- i18n / escaping ---- */
function __( $t, $d = null ) { return $t; }
function _x( $t, $c, $d = null ) { return $t; }
function _n( $s, $p, $n, $d = null ) { return 1 == $n ? $s : $p; }
function _n_noop( $s, $p, $d = null ) { return array( $s, $p ); }
function esc_html__( $t, $d = null ) { return htmlspecialchars( $t, ENT_QUOTES ); }
function esc_attr__( $t, $d = null ) { return htmlspecialchars( $t, ENT_QUOTES ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $t ) { return (string) $t; }
function esc_url_raw( $t ) { return (string) $t; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_specialchars_decode( $t, $q = 0 ) { return htmlspecialchars_decode( (string) $t, ENT_QUOTES ); }
function wp_salt( $s = '' ) { return 'test-salt'; }
function wp_generate_password( $n = 12, $s = true ) { return substr( str_repeat( 'abcdef0123456789', 4 ), 0, $n ); }

/* ---- options / transients ---- */
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['lvk_test']['options'] ) ? $GLOBALS['lvk_test']['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['lvk_test']['options'][ $k ] = $v; return true; }
function get_transient( $k ) { return isset( $GLOBALS['lvk_test']['transients'][ $k ] ) ? $GLOBALS['lvk_test']['transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['lvk_test']['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['lvk_test']['transients'][ $k ] ); return true; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }

/* ---- hooks ---- */
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['lvk_test']['actions'][ $h ][] = array( $cb, $n ); return true; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { return add_action( $h, $cb, $p, $n ); }
function do_action( $h, ...$args ) {
	foreach ( isset( $GLOBALS['lvk_test']['actions'][ $h ] ) ? $GLOBALS['lvk_test']['actions'][ $h ] : array() as $a ) {
		call_user_func_array( $a[0], array_slice( $args, 0, $a[1] ) );
	}
}
function wp_schedule_single_event( $t, $h, $args = array() ) { $GLOBALS['lvk_test']['scheduled'][] = array( $h, $args ); return true; }
function as_enqueue_async_action( $h, $args = array(), $g = '' ) { $GLOBALS['lvk_test']['scheduled'][] = array( $h, $args ); return 1; }

/* ---- request context ---- */
$GLOBALS['lvk_test']['is_checkout'] = false;
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function is_checkout() { return $GLOBALS['lvk_test']['is_checkout']; }
function is_wc_endpoint_url( $e = '' ) { return false; }
function wp_unslash( $v ) { return $v; }

/* ---- misc ---- */
function current_time( $type, $gmt = 0 ) {
	$now = $GLOBALS['lvk_test']['now'];
	if ( 'mysql' === $type ) { return $now; }
	if ( 'timestamp' === $type ) { return strtotime( $now ); }
	return gmdate( $type, strtotime( $now ) );
}
function home_url( $p = '' ) { return 'https://shop.test' . $p; }
function admin_url( $p = '' ) { return 'https://shop.test/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://shop.test/wp-json/' . $p; }
function get_bloginfo( $k ) { return 'Test Shop'; }
function wp_get_referer() { return false; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function absint( $n ) { return abs( (int) $n ); }
function get_woocommerce_currency() { return 'BDT'; }
function wc_get_weight( $w, $to ) { return (float) $w; }
function wc_get_order_statuses() {
	return array( 'wc-pending' => 'Pending payment', 'wc-processing' => 'Processing', 'wc-lvk-shipped' => 'With courier', 'wc-on-hold' => 'On hold', 'wc-completed' => 'Completed', 'wc-lvk-returned' => 'Returned', 'wc-cancelled' => 'Cancelled', 'wc-refunded' => 'Refunded', 'wc-failed' => 'Failed' );
}
function wc_get_logger() {
	return new class() { public function log( $l, $m, $c = array() ) { $GLOBALS['lvk_test']['log'][] = $m; } };
}
class WP_Error { public $m; public function __construct( $c = '', $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } }

/* ---- HTTP: return queued fake responses ---- */
function wp_remote_request( $url, $args ) {
	$GLOBALS['lvk_test']['requests'][] = array( $url, $args );
	$r = array_shift( $GLOBALS['lvk_test']['http'] );
	return $r ? $r : array( 'response' => array( 'code' => 500 ), 'body' => '{}' );
}
function wp_remote_post( $url, $args ) { $args['method'] = 'POST'; return wp_remote_request( $url, $args ); }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function fake_http( $code, $body ) { $GLOBALS['lvk_test']['http'][] = array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body ) ); }

/* ---- REST ---- */
class WP_REST_Request {
	private $headers = array(); private $body = '';
	public function __construct( $headers, $body ) { foreach ( $headers as $k => $v ) { $this->headers[ strtolower( str_replace( '-', '_', $k ) ) ] = $v; } $this->body = $body; }
	public function get_header( $k ) { $k = strtolower( str_replace( '-', '_', $k ) ); return isset( $this->headers[ $k ] ) ? $this->headers[ $k ] : null; }
	public function get_body() { return $this->body; }
	public function get_json_params() { return json_decode( $this->body, true ); }
	public function get_body_params() { return array(); }
}
class WP_REST_Response {
	public $data; public $status; public $headers = array();
	public function __construct( $d, $s = 200 ) { $this->data = $d; $this->status = $s; }
	public function header( $k, $v ) { $this->headers[ $k ] = $v; }
}

/* ---- WooCommerce fakes ---- */
class WC_DateTime_Fake { private $t; public function __construct( $t ) { $this->t = $t; } public function date( $f ) { return gmdate( $f, $this->t ); } public function getTimestamp() { return $this->t; } }

class Fake_Product {
	public $id; public $meta = array(); public $parent = 0; public $weight = '';
	public function __construct( $id, $cost = null, $parent = 0 ) { $this->id = $id; $this->parent = $parent; if ( null !== $cost ) { $this->meta['_lvk_cost'] = (string) $cost; } }
	public function get_meta( $k ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function get_parent_id() { return $this->parent; }
	public function get_weight() { return $this->weight; }
	public function get_id() { return $this->id; }
}

class Fake_Item {
	public $name; public $qty; public $product; public $meta = array(); public $total;
	public function __construct( $name, $qty, $product, $total ) { $this->name = $name; $this->qty = $qty; $this->product = $product; $this->total = $total; }
	public function get_name() { return $this->name; }
	public function get_quantity() { return $this->qty; }
	public function get_product() { return $this->product; }
	public function get_meta( $k ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function get_variation_id() { return 0; }
	public function get_product_id() { return $this->product ? $this->product->id : 0; }
}

class WC_Order {
	public $id; public $status = 'processing'; public $meta = array(); public $notes = array(); public $items = array();
	public $data = array();
	public function __construct( $id, array $data = array() ) {
		$this->id   = $id;
		$this->data = array_merge(
			array( 'total' => 1000, 'refunded' => 0, 'payment_method' => 'cod', 'billing_phone' => '01711000000', 'shipping_phone' => '', 'billing_first_name' => 'Rahim', 'billing_last_name' => 'Uddin', 'billing_city' => 'Dhaka', 'billing_state' => 'BD-13', 'billing_address_1' => 'House 1, Road 2, Dhanmondi', 'shipping_total' => 70, 'created' => strtotime( '2026-11-01 09:00:00' ), 'paid' => false, 'email' => 'r@example.com' ),
			$data
		);
		$GLOBALS['lvk_test']['orders'][ $id ] = $this;
	}
	public function get_id() { return $this->id; }
	public function get_type() { return 'shop_order'; }
	public function get_order_number() { return (string) $this->id; }
	public function get_order_key() { return 'wc_order_key' . $this->id; }
	public function get_status() { return $this->status; }
	public function get_meta( $k ) { return array_key_exists( $k, $this->meta ) ? $this->meta[ $k ] : ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function save() { return $this->id; }
	public function add_order_note( $n ) { $this->notes[] = $n; }
	public function update_status( $to, $note = '' ) {
		$from = $this->status; $this->status = $to;
		if ( $note ) { $this->notes[] = $note; }
		do_action( 'woocommerce_order_status_changed', $this->id, $from, $to, $this );
	}
	public function get_total() { return $this->data['total']; }
	public function get_total_refunded() { return $this->data['refunded']; }
	public function get_payment_method() { return $this->data['payment_method']; }
	public function is_paid() { return $this->data['paid']; }
	public function get_billing_phone() { return $this->data['billing_phone']; }
	public function get_shipping_phone() { return $this->data['shipping_phone']; }
	public function get_billing_first_name() { return $this->data['billing_first_name']; }
	public function get_billing_last_name() { return $this->data['billing_last_name']; }
	public function get_shipping_first_name() { return ''; }
	public function get_shipping_last_name() { return ''; }
	public function get_billing_city() { return $this->data['billing_city']; }
	public function get_shipping_city() { return ''; }
	public function get_billing_state() { return $this->data['billing_state']; }
	public function get_shipping_state() { return ''; }
	public function get_billing_address_1() { return $this->data['billing_address_1']; }
	public function get_billing_address_2() { return ''; }
	public function get_shipping_address_1() { return ''; }
	public function get_shipping_address_2() { return ''; }
	public function get_billing_email() { return $this->data['email']; }
	public function get_customer_note() { return ''; }
	public function get_customer_id() { return 0; }
	public function get_customer_ip_address() { return '103.1.2.3'; }
	public function get_currency() { return 'BDT'; }
	public function get_shipping_total() { return $this->data['shipping_total']; }
	public function get_items() { return $this->items; }
	public function get_item_subtotal( $item, $tax = true ) { return $item->total / max( 1, $item->qty ); }
	public function get_qty_refunded_for_item( $id ) { return 0; }
	public function get_date_created() { return new WC_DateTime_Fake( $this->data['created'] ); }
}

function wc_get_order( $id ) { return isset( $GLOBALS['lvk_test']['orders'][ (int) $id ] ) ? $GLOBALS['lvk_test']['orders'][ (int) $id ] : false; }
function wc_get_product( $id ) { return isset( $GLOBALS['lvk_test']['products'][ $id ] ) ? $GLOBALS['lvk_test']['products'][ $id ] : false; }
function WC() { return new class() { public $countries; public $cart = null; public $customer = null; public function __construct() { $this->countries = new class() { public function get_states( $c ) { return array( 'BD-13' => 'Dhaka' ); } }; } }; }

/* ---- $wpdb on SQLite ---- */
class Fake_WPDB {
	public $prefix = 'wp_'; public $insert_id = 0; private $pdo;
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( "CREATE TABLE wp_lvk_orders (order_id INTEGER PRIMARY KEY, phone TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, courier TEXT NOT NULL DEFAULT '', consignment TEXT NOT NULL DEFAULT '', tracking TEXT NOT NULL DEFAULT '', courier_status TEXT NOT NULL DEFAULT '', outcome TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL)" );
		$this->pdo->exec( "CREATE TABLE wp_lvk_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL DEFAULT 0, entry_date TEXT NOT NULL, kind TEXT NOT NULL, amount REAL NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '')" );
	}
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0; $pdo = $this->pdo;
		return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args, $pdo ) {
			$v = $args[ $i++ ];
			if ( '%d' === $m[0] ) { return (string) (int) $v; }
			if ( '%f' === $m[0] ) { return (string) (float) $v; }
			return $pdo->quote( (string) $v );
		}, $sql );
	}
	public function get_results( $sql, $o = null ) { return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC ); }
	public function get_row( $sql, $o = null ) { $r = $this->pdo->query( $sql )->fetch( PDO::FETCH_ASSOC ); return $r ? $r : null; }
	public function get_var( $sql ) { $r = $this->pdo->query( $sql )->fetch( PDO::FETCH_NUM ); return $r ? $r[0] : null; }
	public function insert( $t, $data, $f = null ) {
		$cols = array_keys( $data );
		$st   = $this->pdo->prepare( "INSERT INTO {$t} (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', array_fill( 0, count( $cols ), '?' ) ) . ')' );
		$st->execute( array_values( $data ) );
		$this->insert_id = (int) $this->pdo->lastInsertId();
		return 1;
	}
	public function update( $t, $data, $where, $f = null, $wf = null ) {
		$set = implode( ',', array_map( function ( $c ) { return "$c = ?"; }, array_keys( $data ) ) );
		$wh  = implode( ' AND ', array_map( function ( $c ) { return "$c = ?"; }, array_keys( $where ) ) );
		$st  = $this->pdo->prepare( "UPDATE {$t} SET {$set} WHERE {$wh}" );
		$st->execute( array_merge( array_values( $data ), array_values( $where ) ) );
		return $st->rowCount();
	}
	public function delete( $t, $where, $f = null ) {
		$wh = implode( ' AND ', array_map( function ( $c ) { return "$c = ?"; }, array_keys( $where ) ) );
		$st = $this->pdo->prepare( "DELETE FROM {$t} WHERE {$wh}" );
		$st->execute( array_values( $where ) );
		return $st->rowCount();
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

/* ---- load the plugin classes ---- */
$dir = dirname( __DIR__ ) . '/lavkhata/includes/';
foreach ( array( 'class-helpers.php', 'class-settings.php', 'class-index.php', 'class-ledger.php', 'class-profit.php', 'couriers/class-courier.php', 'couriers/class-steadfast.php', 'couriers/class-pathao.php', 'class-courier-sync.php', 'class-cod-guard.php', 'class-meta-capi.php', 'class-restock.php', 'class-code128.php', 'class-whatsapp-confirm.php' ) as $f ) {
	require_once $dir . $f;
}
