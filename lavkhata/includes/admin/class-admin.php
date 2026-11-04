<?php
/**
 * Admin menu, pages and form handlers.
 *
 * @package Lavkhata
 */

namespace Lavkhata\Admin;

use Lavkhata\Courier_Sync;
use Lavkhata\Couriers\Steadfast;
use Lavkhata\Gateway_Advance;
use Lavkhata\Helpers;
use Lavkhata\Index;
use Lavkhata\Labels;
use Lavkhata\Ledger;
use Lavkhata\Restock;
use Lavkhata\Settings;

defined( 'ABSPATH' ) || exit;

class Admin {

	const CAP = 'manage_woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 56 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_notices' ) );

		foreach ( array( 'book', 'refresh', 'labels', 'verify_advance', 'add_entry', 'delete_entry', 'export_csv' ) as $a ) {
			add_action( 'admin_post_lvk_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
		add_action( 'wp_ajax_lvk_balance', array( __CLASS__, 'ajax_balance' ) );
	}

	public static function menu() {
		add_menu_page( __( 'Lavkhata', 'lavkhata' ), __( 'Lavkhata', 'lavkhata' ), self::CAP, 'lavkhata', array( __CLASS__, 'page_overview' ), 'dashicons-chart-area', 56 );
		add_submenu_page( 'lavkhata', __( 'Overview', 'lavkhata' ), __( 'Overview', 'lavkhata' ), self::CAP, 'lavkhata', array( __CLASS__, 'page_overview' ) );
		add_submenu_page( 'lavkhata', __( 'Profit & Loss', 'lavkhata' ), __( 'Profit & Loss', 'lavkhata' ), self::CAP, 'lavkhata-pnl', array( __CLASS__, 'page_pnl' ) );
		add_submenu_page( 'lavkhata', __( 'Ads & Expenses', 'lavkhata' ), __( 'Ads & Expenses', 'lavkhata' ), self::CAP, 'lavkhata-spend', array( __CLASS__, 'page_spend' ) );
		add_submenu_page( 'lavkhata', __( 'Restock', 'lavkhata' ), __( 'Restock', 'lavkhata' ), self::CAP, 'lavkhata-restock', array( __CLASS__, 'page_restock' ) );
		add_submenu_page( 'lavkhata', __( 'Settings', 'lavkhata' ), __( 'Settings', 'lavkhata' ), self::CAP, 'lavkhata-settings', array( 'Lavkhata\\Admin\\Settings_Page', 'render' ) );
	}

	public static function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ours   = false !== strpos( (string) $hook, 'lavkhata' );
		$orders = $screen && in_array( $screen->id, array( 'edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders' ), true );
		if ( ! $ours && ! $orders ) {
			return;
		}
		wp_enqueue_style( 'lvk-admin', LVK_URL . 'assets/admin.css', array(), LVK_VERSION );
		wp_enqueue_script( 'lvk-admin', LVK_URL . 'assets/admin.js', array( 'jquery' ), LVK_VERSION, true );
		wp_localize_script(
			'lvk-admin',
			'lvkAdmin',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lvk_ajax' ),
				'loading' => __( 'Checking…', 'lavkhata' ),
				'failed'  => __( 'Could not check', 'lavkhata' ),
			)
		);
	}

	/* ---------- Notices ---------- */

	public static function notice( $message, $type = 'success' ) {
		$key  = 'lvk_notices_' . get_current_user_id();
		$list = get_transient( $key );
		$list = is_array( $list ) ? $list : array();
		$list[] = array( $message, $type );
		set_transient( $key, $list, 120 );
	}

	public static function show_notices() {
		$key  = 'lvk_notices_' . get_current_user_id();
		$list = get_transient( $key );
		if ( ! is_array( $list ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $list as $n ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $n[1] ), esc_html( $n[0] ) );
		}
	}

	private static function back( $fallback = '' ) {
		$to = wp_get_referer();
		wp_safe_redirect( $to ? $to : ( $fallback ? $fallback : admin_url( 'admin.php?page=lavkhata' ) ) );
		exit;
	}

	private static function guard( $nonce_action, $cap = self::CAP ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'lavkhata' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	/* ---------- Handlers ---------- */

	public static function handle_book() {
		self::guard( 'lvk_book', 'edit_shop_orders' );
		$order   = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$courier = isset( $_GET['courier'] ) ? sanitize_key( $_GET['courier'] ) : Settings::get( 'default_courier' ); // phpcs:ignore WordPress.Security.NonceVerification
		$res     = Courier_Sync::book( $order, $courier );
		self::notice( $res['message'], $res['ok'] ? 'success' : 'error' );
		self::back();
	}

	public static function handle_refresh() {
		self::guard( 'lvk_refresh', 'edit_shop_orders' );
		$order = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$res   = Courier_Sync::refresh( $order );
		self::notice( $res['message'], $res['ok'] ? 'success' : 'error' );
		self::back();
	}

	public static function handle_labels() {
		self::guard( 'lvk_labels', 'edit_shop_orders' );
		$ids = isset( $_GET['orders'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['orders'] ) ) ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		Labels::render( array_slice( $ids, 0, 200 ) );
		exit;
	}

	public static function handle_verify_advance() {
		self::guard( 'lvk_verify_advance', 'edit_shop_orders' );
		$order = isset( $_GET['order'] ) ? wc_get_order( absint( $_GET['order'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $order ) {
			Gateway_Advance::verify( $order );
			self::notice( __( 'Advance marked as received.', 'lavkhata' ) );
		}
		self::back();
	}

	public static function handle_add_entry() {
		self::guard( 'lvk_add_entry' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$type   = isset( $_POST['entry_type'] ) && 'expense' === $_POST['entry_type'] ? 'expense' : ( isset( $_POST['entry_type'] ) && 'other_income' === $_POST['entry_type'] ? 'other_income' : 'ad_spend' );
		$date   = isset( $_POST['entry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['entry_date'] ) ) : '';
		$amount = isset( $_POST['amount'] ) ? (float) $_POST['amount'] : 0;
		$note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = current_time( 'Y-m-d' );
		}
		if ( $amount <= 0 ) {
			self::notice( __( 'Enter an amount greater than zero.', 'lavkhata' ), 'error' );
			self::back();
		}
		if ( 'ad_spend' === $type ) {
			$platform = isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( $_POST['platform'] ) ) : 'Meta';
			$currency = isset( $_POST['currency'] ) && 'BDT' === $_POST['currency'] ? 'BDT' : 'USD';
			$vat      = isset( $_POST['vat'] ) ? (float) $_POST['vat'] : (float) Settings::get( 'ads_vat_pct' );
			$bdt      = Ledger::ad_amount_bdt( $amount, $currency, $vat, Settings::get( 'usd_rate' ) );
			$note     = trim( $platform . ' ' . ( 'USD' === $currency ? '$' . number_format( $amount, 2 ) : Helpers::taka( $amount ) ) . ' + ' . Helpers::pct( $vat ) . ' VAT' . ( $note ? ' – ' . $note : '' ) );
			Ledger::add( 0, $date, 'ad_spend', $bdt, $note );
		} else {
			Ledger::add( 0, $date, $type, $amount, $note );
		}
		// phpcs:enable
		self::notice( __( 'Saved.', 'lavkhata' ) );
		self::back();
	}

	public static function handle_delete_entry() {
		self::guard( 'lvk_delete_entry' );
		Ledger::delete_entry( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		self::notice( __( 'Deleted.', 'lavkhata' ) );
		self::back();
	}

	public static function handle_export_csv() {
		self::guard( 'lvk_export_csv' );
		list( $month, $from, $to ) = self::month_range( isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$kinds  = Ledger::kinds();
		$totals = Ledger::totals( $from, $to );
		$counts = Index::counts_between( $from . ' 00:00:00', $to . ' 00:00:00' );
		$sum    = Ledger::summarize( $totals, $counts['orders'] );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=lavkhata-pnl-' . $month . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel-friendly UTF-8.
		fputcsv( $out, array( __( 'Profit & Loss', 'lavkhata' ), $month ) );
		fputcsv( $out, array() );
		fputcsv( $out, array( __( 'Line', 'lavkhata' ), __( 'Amount (BDT)', 'lavkhata' ) ) );
		foreach ( $kinds as $k => $label ) {
			$v = in_array( $k, Ledger::INCOME, true ) ? $totals[ $k ] : -$totals[ $k ];
			fputcsv( $out, array( $label, number_format( $v, 2, '.', '' ) ) );
		}
		fputcsv( $out, array( __( 'Net profit', 'lavkhata' ), number_format( $sum['net'], 2, '.', '' ) ) );
		fputcsv( $out, array() );
		fputcsv( $out, array( __( 'Date', 'lavkhata' ), __( 'Order', 'lavkhata' ), __( 'Type', 'lavkhata' ), __( 'Amount (BDT)', 'lavkhata' ), __( 'Note', 'lavkhata' ) ) );
		foreach ( Ledger::entries( $from, $to ) as $e ) {
			$v = in_array( $e['kind'], Ledger::INCOME, true ) ? (float) $e['amount'] : -(float) $e['amount'];
			fputcsv( $out, array( $e['entry_date'], $e['order_id'] ? $e['order_id'] : '', isset( $kinds[ $e['kind'] ] ) ? $kinds[ $e['kind'] ] : $e['kind'], number_format( $v, 2, '.', '' ), $e['note'] ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public static function ajax_balance() {
		check_ajax_referer( 'lvk_ajax' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error();
		}
		$sf  = new Steadfast();
		$bal = $sf->is_ready() ? $sf->balance() : null;
		wp_send_json_success( array( 'steadfast' => null === $bal ? '—' : Helpers::taka( $bal ) ) );
	}

	/* ---------- Helpers ---------- */

	/** @return array{0:string,1:string,2:string} month, from date, to date (exclusive). */
	public static function month_range( $month ) {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ) {
			$month = current_time( 'Y-m' );
		}
		$from = $month . '-01';
		$to   = gmdate( 'Y-m-d', strtotime( $from . ' +1 month' ) );
		return array( $month, $from, $to );
	}

	private static function month_picker( $page, $month ) {
		echo '<form method="get" class="lvk-month"><input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
		echo '<label>' . esc_html__( 'Month', 'lavkhata' ) . ' <input type="month" name="month" value="' . esc_attr( $month ) . '"></label> ';
		submit_button( __( 'Show', 'lavkhata' ), 'secondary', '', false );
		echo '</form>';
	}

	private static function card( $label, $value, $sub = '', $tone = '' ) {
		printf(
			'<div class="lvk-card %4$s"><div class="lvk-card-label">%1$s</div><div class="lvk-card-value">%2$s</div><div class="lvk-card-sub">%3$s</div></div>',
			esc_html( $label ),
			esc_html( $value ),
			esc_html( $sub ),
			esc_attr( $tone )
		);
	}

	/* ---------- Pages ---------- */

	public static function page_overview() {
		list( $month, $from, $to ) = self::month_range( '' );
		$totals = Ledger::totals( $from, $to );
		$counts = Index::counts_between( $from . ' 00:00:00', $to . ' 00:00:00' );
		$sum    = Ledger::summarize( $totals, $counts['orders'] );
		$settled = Ledger::settled_counts( $from, $to );
		$done    = $settled['delivered'] + $settled['returned'];
		$rate    = $done > 0 ? $settled['delivered'] / $done * 100 : null;
		?>
		<div class="wrap lvk-wrap">
			<h1><?php esc_html_e( 'Lavkhata overview', 'lavkhata' ); ?> <span class="lvk-sub"><?php echo esc_html( date_i18n( 'F Y', strtotime( $from ) ) ); ?></span></h1>
			<?php self::setup_checklist(); ?>
			<div class="lvk-cards">
				<?php
				self::card( __( 'Net profit', 'lavkhata' ), Helpers::taka( $sum['net'] ), sprintf( /* translators: %s: margin */ __( '%s of sales', 'lavkhata' ), Helpers::pct( $sum['margin'] ) ), $sum['net'] >= 0 ? 'good' : 'bad' );
				self::card( __( 'Delivered sales', 'lavkhata' ), Helpers::taka( $totals['revenue'] ), sprintf( /* translators: %d: orders */ _n( '%d order delivered', '%d orders delivered', $settled['delivered'], 'lavkhata' ), $settled['delivered'] ) );
				self::card( __( 'Delivery rate', 'lavkhata' ), null === $rate ? '—' : Helpers::pct( $rate ), sprintf( /* translators: %d: returned */ _n( '%d returned', '%d returned', $settled['returned'], 'lavkhata' ), $settled['returned'] ), null === $rate ? '' : ( $rate >= 80 ? 'good' : ( $rate >= 65 ? 'warn' : 'bad' ) ) );
				self::card( __( 'With courier now', 'lavkhata' ), (string) $counts['in_courier'], __( 'parcels booked this month and not finished', 'lavkhata' ) );
				self::card( __( 'Steadfast balance', 'lavkhata' ), '…', __( 'COD money waiting for payout', 'lavkhata' ), 'lvk-balance' );
				?>
			</div>

			<div class="lvk-grid">
				<div class="lvk-panel">
					<h2><?php esc_html_e( 'Are your ads profitable?', 'lavkhata' ); ?></h2>
					<?php self::ads_panel( $sum, $counts['orders'] ); ?>
				</div>
				<div class="lvk-panel">
					<h2><?php esc_html_e( 'Restock soon', 'lavkhata' ); ?></h2>
					<?php
					$rows = array_slice(
						array_filter(
							Restock::rows(),
							static function ( $r ) {
								return in_array( $r['status'], array( 'out', 'now', 'soon' ), true );
							}
						),
						0,
						6
					);
					if ( ! $rows ) {
						echo '<p class="lvk-muted">' . esc_html__( 'Nothing needs restocking this week.', 'lavkhata' ) . '</p>';
					} else {
						echo '<table class="widefat striped"><tbody>';
						foreach ( $rows as $r ) {
							printf(
								'<tr><td>%1$s</td><td><span class="lvk-chip %2$s">%3$s</span></td><td>%4$s</td></tr>',
								esc_html( wp_strip_all_tags( $r['name'] ) ),
								esc_attr( 'rs-' . $r['status'] ),
								esc_html( Restock::status_label( $r['status'] ) ),
								/* translators: %d: units */
								esc_html( $r['qty'] ? sprintf( __( 'Order %d', 'lavkhata' ), $r['qty'] ) : '' )
							);
						}
						echo '</tbody></table>';
					}
					echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=lavkhata-restock' ) ) . '">' . esc_html__( 'See all stock', 'lavkhata' ) . ' →</a></p>';
					?>
				</div>
			</div>
			<p class="lvk-muted">
				<?php
				$last = (int) get_option( 'lvk_last_sync' );
				/* translators: %s: time */
				echo esc_html( $last ? sprintf( __( 'Last full courier check: %s ago.', 'lavkhata' ), human_time_diff( $last ) ) : __( 'The full courier check runs every night.', 'lavkhata' ) );
				echo ' ';
				echo esc_html( Settings::is_auto() ? __( 'Mode: Auto-update (courier status changes your orders).', 'lavkhata' ) : __( 'Mode: Report only (courier status is shown but orders are not changed).', 'lavkhata' ) );
				?>
			</p>
		</div>
		<?php
	}

	private static function setup_checklist() {
		$steps = array(
			array( Settings::steadfast_ready() || Settings::pathao_ready(), __( 'Connect Steadfast or Pathao', 'lavkhata' ), 'couriers' ),
			array( (bool) Settings::get( 'cod_guard_enabled' ), __( 'Turn on COD protection', 'lavkhata' ), 'cod' ),
			array( Settings::meta_ready(), __( 'Add your Meta pixel and Conversions API token', 'lavkhata' ), 'meta' ),
			array( self::costs_entered(), __( 'Enter cost prices on your products', 'lavkhata' ), '' ),
		);
		$left = array_filter(
			$steps,
			static function ( $s ) {
				return ! $s[0];
			}
		);
		if ( ! $left ) {
			return;
		}
		echo '<div class="lvk-panel lvk-setup"><h2>' . esc_html__( 'Finish setup', 'lavkhata' ) . '</h2><ol>';
		foreach ( $steps as $s ) {
			$url = $s[2] ? admin_url( 'admin.php?page=lavkhata-settings&tab=' . $s[2] ) : admin_url( 'edit.php?post_type=product' );
			printf( '<li class="%1$s"><a href="%2$s">%3$s</a></li>', $s[0] ? 'done' : '', esc_url( $url ), esc_html( $s[1] ) );
		}
		echo '</ol></div>';
	}

	private static function costs_entered() {
		$with = wc_get_products(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'meta_key'   => '_lvk_cost', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'EXISTS',
			)
		);
		return ! empty( $with );
	}

	private static function ads_panel( array $sum, $orders ) {
		$rate = max( 1, (float) Settings::get( 'usd_rate' ) );
		if ( $orders < 1 ) {
			echo '<p class="lvk-muted">' . esc_html__( 'No orders yet this month.', 'lavkhata' ) . '</p>';
			return;
		}
		$be = $sum['break_even_cpp'];
		echo '<table class="lvk-kv"><tbody>';
		/* translators: 1: taka, 2: dollars */
		printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html__( 'Break-even cost per purchase', 'lavkhata' ), esc_html( null === $be ? '—' : sprintf( __( '%1$s (about $%2$s)', 'lavkhata' ), Helpers::taka( $be ), number_format( $be / $rate, 2 ) ) ) );
		$cpp = $sum['actual_cpp'];
		/* translators: 1: taka, 2: dollars */
		printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html__( 'Your cost per purchase (with VAT)', 'lavkhata' ), esc_html( null === $cpp ? __( 'Add your ad spend', 'lavkhata' ) : sprintf( __( '%1$s (about $%2$s)', 'lavkhata' ), Helpers::taka( $cpp ), number_format( $cpp / $rate, 2 ) ) ) );
		printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html__( 'Orders placed', 'lavkhata' ), esc_html( (string) $orders ) );
		echo '</tbody></table>';
		if ( null !== $sum['ads_profitable'] ) {
			echo $sum['ads_profitable']
				? '<p class="lvk-chip good">' . esc_html__( 'Your ads are profitable', 'lavkhata' ) . '</p>'
				: '<p class="lvk-chip bad">' . esc_html__( 'Your ads cost more than each order earns', 'lavkhata' ) . '</p>';
		}
		echo '<p class="lvk-muted">' . esc_html__( 'Break-even = profit before ads from delivered and returned orders, divided by all orders placed. If you pay less than this per purchase, ads make money.', 'lavkhata' ) . '</p>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=lavkhata-spend' ) ) . '">' . esc_html__( 'Add ad spend', 'lavkhata' ) . ' →</a></p>';
	}

	public static function page_pnl() {
		list( $month, $from, $to ) = self::month_range( isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$kinds  = Ledger::kinds();
		$totals = Ledger::totals( $from, $to );
		$counts = Index::counts_between( $from . ' 00:00:00', $to . ' 00:00:00' );
		$sum    = Ledger::summarize( $totals, $counts['orders'] );
		$csv    = wp_nonce_url( admin_url( 'admin-post.php?action=lvk_export_csv&month=' . $month ), 'lvk_export_csv' );
		?>
		<div class="wrap lvk-wrap">
			<h1><?php esc_html_e( 'Profit & Loss', 'lavkhata' ); ?></h1>
			<div class="lvk-toolbar"><?php self::month_picker( 'lavkhata-pnl', $month ); ?> <a class="button" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Export CSV for your accountant', 'lavkhata' ); ?></a></div>
			<p class="lvk-muted"><?php esc_html_e( 'Sales count only when the courier delivers. Returned orders count their delivery, return and packaging costs. Ad spend includes VAT.', 'lavkhata' ); ?></p>
			<div class="lvk-grid">
				<div class="lvk-panel">
					<table class="widefat lvk-pnl">
						<tbody>
						<?php
						$section = static function ( $title ) {
							echo '<tr class="lvk-pnl-section"><th colspan="2">' . esc_html( $title ) . '</th></tr>';
						};
						$line = static function ( $label, $value, $class = '' ) {
							printf( '<tr class="%3$s"><td>%1$s</td><td class="num">%2$s</td></tr>', esc_html( $label ), esc_html( Helpers::taka( $value, 2 ) ), esc_attr( $class ) );
						};
						$section( __( 'Income', 'lavkhata' ) );
						foreach ( Ledger::INCOME as $k ) {
							$line( $kinds[ $k ], $totals[ $k ] );
						}
						$section( __( 'Order costs', 'lavkhata' ) );
						foreach ( array( 'cogs', 'delivery', 'cod_fee', 'return_fee', 'packaging', 'gateway_fee' ) as $k ) {
							$line( $kinds[ $k ], -$totals[ $k ] );
						}
						$line( __( 'Profit before ads', 'lavkhata' ), $sum['profit_before_ads'], 'lvk-pnl-sub' );
						$section( __( 'Marketing and overheads', 'lavkhata' ) );
						$line( $kinds['ad_spend'], -$totals['ad_spend'] );
						$line( $kinds['expense'], -$totals['expense'] );
						$line( __( 'Net profit', 'lavkhata' ), $sum['net'], 'lvk-pnl-total ' . ( $sum['net'] >= 0 ? 'good' : 'bad' ) );
						?>
						</tbody>
					</table>
				</div>
				<div class="lvk-panel">
					<h2><?php esc_html_e( 'Ads', 'lavkhata' ); ?></h2>
					<?php self::ads_panel( $sum, $counts['orders'] ); ?>
					<h2><?php esc_html_e( 'Last 6 months', 'lavkhata' ); ?></h2>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Month', 'lavkhata' ); ?></th><th class="num"><?php esc_html_e( 'Sales', 'lavkhata' ); ?></th><th class="num"><?php esc_html_e( 'Net profit', 'lavkhata' ); ?></th></tr></thead><tbody>
					<?php
					for ( $i = 0; $i < 6; $i++ ) {
						$m = gmdate( 'Y-m', strtotime( $from . " -{$i} month" ) );
						list( , $f, $t ) = self::month_range( $m );
						$tt = Ledger::totals( $f, $t );
						$ss = Ledger::summarize( $tt );
						printf(
							'<tr><td><a href="%1$s">%2$s</a></td><td class="num">%3$s</td><td class="num %5$s">%4$s</td></tr>',
							esc_url( admin_url( 'admin.php?page=lavkhata-pnl&month=' . $m ) ),
							esc_html( date_i18n( 'M Y', strtotime( $f ) ) ),
							esc_html( Helpers::taka( $tt['revenue'] ) ),
							esc_html( Helpers::taka( $ss['net'] ) ),
							$ss['net'] >= 0 ? 'good' : 'bad'
						);
					}
					?>
					</tbody></table>
				</div>
			</div>
		</div>
		<?php
	}

	public static function page_spend() {
		list( $month, $from, $to ) = self::month_range( isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$kinds   = Ledger::kinds();
		$entries = Ledger::entries( $from, $to, true );
		$post    = admin_url( 'admin-post.php' );
		?>
		<div class="wrap lvk-wrap">
			<h1><?php esc_html_e( 'Ads & Expenses', 'lavkhata' ); ?></h1>
			<div class="lvk-grid">
				<form class="lvk-panel" method="post" action="<?php echo esc_url( $post ); ?>">
					<h2><?php esc_html_e( 'Add ad spend', 'lavkhata' ); ?></h2>
					<input type="hidden" name="action" value="lvk_add_entry"><input type="hidden" name="entry_type" value="ad_spend">
					<?php wp_nonce_field( 'lvk_add_entry' ); ?>
					<p><label><?php esc_html_e( 'Date', 'lavkhata' ); ?><br><input type="date" name="entry_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required></label></p>
					<p><label><?php esc_html_e( 'Platform', 'lavkhata' ); ?><br><select name="platform"><option>Meta</option><option>TikTok</option><option>Google</option><option><?php esc_html_e( 'Other', 'lavkhata' ); ?></option></select></label></p>
					<p class="lvk-inline"><label><?php esc_html_e( 'Amount', 'lavkhata' ); ?><br><input type="number" step="0.01" min="0" name="amount" required></label>
					<label><?php esc_html_e( 'Currency', 'lavkhata' ); ?><br><select name="currency"><option value="USD">USD ($)</option><option value="BDT">BDT (৳)</option></select></label>
					<label><?php esc_html_e( 'VAT %', 'lavkhata' ); ?><br><input type="number" step="0.1" min="0" name="vat" value="<?php echo esc_attr( Settings::get( 'ads_vat_pct' ) ); ?>"></label></p>
					<?php /* translators: %s: rate */ ?>
					<p class="lvk-muted"><?php echo esc_html( sprintf( __( 'Dollars are converted at ৳%s (change in Settings → Costs).', 'lavkhata' ), Settings::get( 'usd_rate' ) ) ); ?></p>
					<p><label><?php esc_html_e( 'Note', 'lavkhata' ); ?><br><input type="text" name="note" class="regular-text" placeholder="<?php esc_attr_e( 'Campaign name', 'lavkhata' ); ?>"></label></p>
					<?php submit_button( __( 'Add ad spend', 'lavkhata' ), 'primary', 'submit', false ); ?>
				</form>
				<form class="lvk-panel" method="post" action="<?php echo esc_url( $post ); ?>">
					<h2><?php esc_html_e( 'Add expense or other income', 'lavkhata' ); ?></h2>
					<input type="hidden" name="action" value="lvk_add_entry">
					<?php wp_nonce_field( 'lvk_add_entry' ); ?>
					<p><label><?php esc_html_e( 'Type', 'lavkhata' ); ?><br><select name="entry_type"><option value="expense"><?php esc_html_e( 'Expense (rent, salary, software…)', 'lavkhata' ); ?></option><option value="other_income"><?php esc_html_e( 'Other income', 'lavkhata' ); ?></option></select></label></p>
					<p><label><?php esc_html_e( 'Date', 'lavkhata' ); ?><br><input type="date" name="entry_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required></label></p>
					<p><label><?php esc_html_e( 'Amount (৳)', 'lavkhata' ); ?><br><input type="number" step="0.01" min="0" name="amount" required></label></p>
					<p><label><?php esc_html_e( 'Note', 'lavkhata' ); ?><br><input type="text" name="note" class="regular-text" required></label></p>
					<?php submit_button( __( 'Add', 'lavkhata' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
			<div class="lvk-panel">
				<div class="lvk-toolbar"><h2><?php esc_html_e( 'Entries', 'lavkhata' ); ?></h2><?php self::month_picker( 'lavkhata-spend', $month ); ?></div>
				<?php if ( ! $entries ) : ?>
					<p class="lvk-muted"><?php esc_html_e( 'Nothing added for this month yet.', 'lavkhata' ); ?></p>
				<?php else : ?>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Date', 'lavkhata' ); ?></th><th><?php esc_html_e( 'Type', 'lavkhata' ); ?></th><th><?php esc_html_e( 'Note', 'lavkhata' ); ?></th><th class="num"><?php esc_html_e( 'Amount', 'lavkhata' ); ?></th><th></th></tr></thead><tbody>
					<?php foreach ( $entries as $e ) : ?>
						<tr>
							<td><?php echo esc_html( $e['entry_date'] ); ?></td>
							<td><?php echo esc_html( isset( $kinds[ $e['kind'] ] ) ? $kinds[ $e['kind'] ] : $e['kind'] ); ?></td>
							<td><?php echo esc_html( $e['note'] ); ?></td>
							<td class="num"><?php echo esc_html( Helpers::taka( $e['amount'], 2 ) ); ?></td>
							<td><a class="lvk-danger" onclick="return confirm('<?php echo esc_js( __( 'Delete this entry?', 'lavkhata' ) ); ?>')" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lvk_delete_entry&id=' . (int) $e['id'] ), 'lvk_delete_entry' ) ); ?>"><?php esc_html_e( 'Delete', 'lavkhata' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function page_restock() {
		$rows = Restock::rows();
		?>
		<div class="wrap lvk-wrap">
			<h1><?php esc_html_e( 'Restock planner', 'lavkhata' ); ?></h1>
			<p class="lvk-muted">
				<?php
				/* translators: 1: lookback days, 2: lead days, 3: cover days */
				echo esc_html( sprintf( __( 'Based on sales in the last %1$d days. Reorder date allows %2$d days for your supplier; suggested quantity covers %3$d days. Change these in Settings → Restock.', 'lavkhata' ), Settings::get( 'restock_lookback' ), Settings::get( 'restock_lead' ), Settings::get( 'restock_cover' ) ) );
				?>
			</p>
			<?php if ( ! $rows ) : ?>
				<div class="lvk-panel"><p><?php esc_html_e( 'No products track stock yet. Turn on "Manage stock" on your products to use the planner.', 'lavkhata' ); ?></p></div>
			<?php else : ?>
			<table class="widefat striped lvk-restock">
				<thead><tr>
					<th><?php esc_html_e( 'Product', 'lavkhata' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'lavkhata' ); ?></th>
					<th class="num"><?php esc_html_e( 'In stock', 'lavkhata' ); ?></th>
					<th class="num"><?php esc_html_e( 'Sold', 'lavkhata' ); ?></th>
					<th class="num"><?php esc_html_e( 'Per day', 'lavkhata' ); ?></th>
					<th class="num"><?php esc_html_e( 'Days left', 'lavkhata' ); ?></th>
					<th><?php esc_html_e( 'Reorder by', 'lavkhata' ); ?></th>
					<th class="num"><?php esc_html_e( 'Order qty', 'lavkhata' ); ?></th>
					<th><?php esc_html_e( 'Status', 'lavkhata' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( (string) $r['edit'] ); ?>"><?php echo esc_html( wp_strip_all_tags( $r['name'] ) ); ?></a></td>
						<td><?php echo esc_html( $r['sku'] ); ?></td>
						<td class="num"><?php echo esc_html( (string) $r['stock'] ); ?></td>
						<td class="num"><?php echo esc_html( (string) $r['sold'] ); ?></td>
						<td class="num"><?php echo esc_html( (string) $r['per_day'] ); ?></td>
						<td class="num"><?php echo esc_html( null === $r['days_left'] ? '—' : (string) $r['days_left'] ); ?></td>
						<td><?php echo esc_html( null === $r['reorder_in'] ? '—' : ( $r['reorder_in'] <= 0 ? __( 'Today', 'lavkhata' ) : date_i18n( 'd M', current_time( 'timestamp' ) + (int) floor( $r['reorder_in'] ) * DAY_IN_SECONDS ) ) ); ?></td>
						<td class="num"><strong><?php echo esc_html( $r['qty'] ? (string) $r['qty'] : '—' ); ?></strong></td>
						<td><span class="lvk-chip <?php echo esc_attr( 'rs-' . $r['status'] ); ?>"><?php echo esc_html( Restock::status_label( $r['status'] ) ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
