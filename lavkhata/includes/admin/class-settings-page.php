<?php
/**
 * Settings screen with tabs.
 *
 * @package Lavkhata
 */

namespace Lavkhata\Admin;

use Lavkhata\Couriers\Pathao;
use Lavkhata\Couriers\Steadfast;
use Lavkhata\Helpers;
use Lavkhata\Settings;

defined( 'ABSPATH' ) || exit;

class Settings_Page {

	public static function init() {
		add_action( 'admin_post_lvk_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_lvk_test_courier', array( __CLASS__, 'test_courier' ) );
	}

	public static function tabs() {
		return array(
			'general'  => __( 'General', 'lavkhata' ),
			'couriers' => __( 'Couriers', 'lavkhata' ),
			'cod'      => __( 'COD protection', 'lavkhata' ),
			'costs'    => __( 'Costs', 'lavkhata' ),
			'meta'     => __( 'Meta ads', 'lavkhata' ),
			'whatsapp' => __( 'WhatsApp', 'lavkhata' ),
			'stock'    => __( 'Restock & labels', 'lavkhata' ),
		);
	}

	/** Field definitions per tab: key => [type, label, help, options]. */
	public static function fields( $tab ) {
		switch ( $tab ) {
			case 'general':
				return array(
					'mode'                 => array( 'select', __( 'Courier updates', 'lavkhata' ), __( 'Start with "Report only": courier status is shown on each order but your order statuses are not changed. Switch to Auto-update when you are happy.', 'lavkhata' ), array( 'report' => __( 'Report only', 'lavkhata' ), 'auto' => __( 'Auto-update order status', 'lavkhata' ) ) ),
					'default_courier'      => array( 'select', __( 'Default courier', 'lavkhata' ), '', array( 'steadfast' => 'Steadfast', 'pathao' => 'Pathao' ) ),
					'hold_until_confirmed' => array( 'checkbox', __( 'Hold new COD orders', 'lavkhata' ), __( 'Put new cash-on-delivery orders "On hold" until the customer taps the WhatsApp confirm link.', 'lavkhata' ) ),
					'language'             => array( 'select', __( 'Plugin language', 'lavkhata' ), '', array( 'auto' => __( 'Same as site', 'lavkhata' ), 'en_US' => 'English', 'bn_BD' => 'বাংলা' ) ),
				);
			case 'couriers':
				return array(
					'_h_sf'                   => array( 'heading', 'Steadfast', __( 'Find these in your Steadfast merchant panel → API.', 'lavkhata' ) ),
					'steadfast_api_key'       => array( 'text', __( 'API key', 'lavkhata' ) ),
					'steadfast_secret_key'    => array( 'password', __( 'Secret key', 'lavkhata' ) ),
					'_sf_hook'                => array( 'info', __( 'Webhook URL', 'lavkhata' ), rest_url( 'lavkhata/v1/steadfast' ) ),
					'steadfast_webhook_token' => array( 'text', __( 'Webhook auth token', 'lavkhata' ), __( 'Paste this as the Auth token (Bearer) in Steadfast → Webhook, with the URL above.', 'lavkhata' ) ),
					'_h_pa'                   => array( 'heading', 'Pathao', __( 'Find these in Pathao merchant panel → Developers API → Merchant API Credentials.', 'lavkhata' ) ),
					'pathao_client_id'        => array( 'text', __( 'Client ID', 'lavkhata' ) ),
					'pathao_client_secret'    => array( 'password', __( 'Client secret', 'lavkhata' ) ),
					'pathao_username'         => array( 'text', __( 'Merchant email', 'lavkhata' ) ),
					'pathao_password'         => array( 'password', __( 'Merchant password', 'lavkhata' ) ),
					'pathao_store_id'         => array( 'text', __( 'Store ID', 'lavkhata' ), __( 'Leave empty to use your first store. "Test connection" lists your stores.', 'lavkhata' ) ),
					'pathao_sandbox'          => array( 'checkbox', __( 'Sandbox', 'lavkhata' ), __( 'Use Pathao test server.', 'lavkhata' ) ),
					'_pa_hook'                => array( 'info', __( 'Webhook URL', 'lavkhata' ), rest_url( 'lavkhata/v1/pathao' ) ),
					'pathao_webhook_secret'   => array( 'text', __( 'Webhook secret', 'lavkhata' ), __( 'Paste this as the webhook secret in Pathao merchant panel → Developers API → Webhook.', 'lavkhata' ) ),
				);
			case 'cod':
				return array(
					'cod_guard_enabled' => array( 'checkbox', __( 'COD protection', 'lavkhata' ), __( 'Check each customer\'s delivery record at checkout.', 'lavkhata' ) ),
					'cod_use_steadfast' => array( 'checkbox', __( 'Use Steadfast record', 'lavkhata' ), __( 'Parcels this phone number received or returned across Steadfast.', 'lavkhata' ) ),
					'cod_use_pathao'    => array( 'checkbox', __( 'Use Pathao record', 'lavkhata' ), __( 'Parcels this phone number received or returned across Pathao.', 'lavkhata' ) ),
					'cod_use_store'     => array( 'checkbox', __( 'Use your own shop history', 'lavkhata' ), __( 'Works even without courier keys.', 'lavkhata' ) ),
					'cod_min_parcels'   => array( 'number', __( 'Judge after this many parcels', 'lavkhata' ), __( 'Customers with fewer parcels are not judged.', 'lavkhata' ) ),
					'cod_min_ratio'     => array( 'number', __( 'Minimum delivery rate (%)', 'lavkhata' ), __( 'Below this, the customer is risky.', 'lavkhata' ) ),
					'cod_new_customer'  => array( 'select', __( 'New customers (no record)', 'lavkhata' ), '', array( 'allow' => __( 'Allow cash on delivery', 'lavkhata' ), 'advance' => __( 'Ask for advance', 'lavkhata' ) ) ),
					'cod_action'        => array( 'select', __( 'For risky customers', 'lavkhata' ), __( 'Advance uses the "Advance + Cash on Delivery" payment method. Set your bKash/Nagad numbers in WooCommerce → Settings → Payments.', 'lavkhata' ), array( 'advance' => __( 'Ask for an advance, rest on delivery', 'lavkhata' ), 'full' => __( 'Turn off cash on delivery (pay online only)', 'lavkhata' ) ) ),
					'cod_advance_type'  => array( 'select', __( 'Advance amount', 'lavkhata' ), '', array( 'fixed' => __( 'Fixed taka', 'lavkhata' ), 'percent' => __( 'Percent of order', 'lavkhata' ), 'shipping' => __( 'Delivery charge', 'lavkhata' ) ) ),
					'cod_advance_value' => array( 'number', __( 'Advance value', 'lavkhata' ), __( 'Taka for "Fixed", percent for "Percent".', 'lavkhata' ) ),
					'cod_message'       => array( 'textarea', __( 'Message shown at checkout', 'lavkhata' ), __( 'Leave empty to use the default message (shown in the site language).', 'lavkhata' ) ),
				);
			case 'costs':
				return array(
					'cost_delivery_inside'   => array( 'number', __( 'Delivery charge inside Dhaka (৳)', 'lavkhata' ), __( 'Used when the courier does not send the real charge.', 'lavkhata' ) ),
					'cost_delivery_outside'  => array( 'number', __( 'Delivery charge outside Dhaka (৳)', 'lavkhata' ) ),
					'cost_return_pct'        => array( 'number', __( 'Return charge (% of delivery charge)', 'lavkhata' ) ),
					'cost_cod_fee_pct'       => array( 'number', __( 'COD collection fee (%)', 'lavkhata' ) ),
					'cost_packaging'         => array( 'number', __( 'Packaging per order (৳)', 'lavkhata' ) ),
					'cost_gateway_fee_pct'   => array( 'number', __( 'Online payment fee (%)', 'lavkhata' ), __( 'bKash / gateway fee on money paid before delivery.', 'lavkhata' ) ),
					'advance_kept_on_return' => array( 'checkbox', __( 'Keep advance on returns', 'lavkhata' ), __( 'Count the advance as income when a parcel is returned.', 'lavkhata' ) ),
					'ads_vat_pct'            => array( 'number', __( 'VAT on ads (%)', 'lavkhata' ) ),
					'usd_rate'               => array( 'number', __( 'Dollar rate (৳ per $1)', 'lavkhata' ), __( 'Your card\'s rate for paying ads.', 'lavkhata' ) ),
				);
			case 'meta':
				return array(
					'meta_pixel_id'      => array( 'text', __( 'Pixel (dataset) ID', 'lavkhata' ) ),
					'meta_capi_token'    => array( 'password', __( 'Conversions API access token', 'lavkhata' ), __( 'Events Manager → your pixel → Settings → Generate access token.', 'lavkhata' ) ),
					'meta_purchase_on'   => array( 'select', __( 'Send Purchase when', 'lavkhata' ), __( 'Recommended: when delivered. Meta then learns from people who really pay, not fake COD orders.', 'lavkhata' ), array( 'delivered' => __( 'The courier delivers the order', 'lavkhata' ), 'placed' => __( 'The order is placed', 'lavkhata' ) ) ),
					'meta_browser_pixel' => array( 'checkbox', __( 'Browser pixel', 'lavkhata' ), __( 'Add the pixel to your site (PageView, ViewContent, AddToCart, InitiateCheckout). Turn off if another plugin already adds it.', 'lavkhata' ) ),
					'meta_test_code'     => array( 'text', __( 'Test event code', 'lavkhata' ), __( 'Only while testing in Events Manager. Leave empty for live.', 'lavkhata' ) ),
				);
			case 'whatsapp':
				return array(
					'wa_enabled'  => array( 'checkbox', __( 'WhatsApp confirm', 'lavkhata' ), __( 'Show a WhatsApp button on new orders that opens a ready message with a one-tap confirm link.', 'lavkhata' ) ),
					'wa_template' => array( 'textarea', __( 'Message', 'lavkhata' ), __( 'Leave empty for the default. You can use {name} {order} {total} {cod} {items} {shop} {link}', 'lavkhata' ) ),
				);
			case 'stock':
				return array(
					'restock_lookback' => array( 'number', __( 'Look at sales from the last (days)', 'lavkhata' ) ),
					'restock_lead'     => array( 'number', __( 'Supplier delivery time (days)', 'lavkhata' ) ),
					'restock_cover'    => array( 'number', __( 'Buy enough for (days)', 'lavkhata' ) ),
					'label_size'       => array( 'select', __( 'Label size', 'lavkhata' ), '', array( '75x100' => '75 × 100 mm (3 × 4 in)', '100x150' => '100 × 150 mm (4 × 6 in)', '58x40' => '58 × 40 mm' ) ),
					'label_phone'      => array( 'text', __( 'Shop phone on label', 'lavkhata' ) ),
					'label_show_items' => array( 'checkbox', __( 'Items on label', 'lavkhata' ), __( 'List the products and quantities on each label.', 'lavkhata' ) ),
				);
		}
		return array();
	}

	public static function render() {
		if ( ! current_user_can( Admin::CAP ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		$vals = Settings::all();
		?>
		<div class="wrap lvk-wrap">
			<h1><?php esc_html_e( 'Lavkhata settings', 'lavkhata' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $k => $label ) : ?>
					<a class="nav-tab <?php echo $k === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=lavkhata-settings&tab=' . $k ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lvk-settings">
				<input type="hidden" name="action" value="lvk_save_settings">
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
				<?php wp_nonce_field( 'lvk_save_settings' ); ?>
				<table class="form-table" role="presentation"><tbody>
				<?php
				foreach ( self::fields( $tab ) as $key => $f ) {
					self::field( $key, $f, isset( $vals[ $key ] ) ? $vals[ $key ] : '' );
				}
				?>
				</tbody></table>
				<?php submit_button(); ?>
			</form>
			<?php if ( 'couriers' === $tab ) : ?>
				<p>
					<?php foreach ( array( 'steadfast' => 'Steadfast', 'pathao' => 'Pathao' ) as $id => $name ) : ?>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lvk_test_courier&courier=' . $id ), 'lvk_test_courier' ) ); ?>">
							<?php /* translators: %s: courier */ echo esc_html( sprintf( __( 'Test %s connection', 'lavkhata' ), $name ) ); ?>
						</a>
					<?php endforeach; ?>
				</p>
				<p class="lvk-muted"><?php esc_html_e( 'Some hosts remove the Authorization header. If Steadfast webhooks fail, ask your host to pass it through, or rely on the nightly status check.', 'lavkhata' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function field( $key, array $f, $value ) {
		$type  = $f[0];
		$label = $f[1];
		$help  = isset( $f[2] ) ? $f[2] : '';
		$id    = 'lvk_' . $key;
		if ( 'heading' === $type ) {
			echo '<tr><th colspan="2"><h2 class="lvk-h">' . esc_html( $label ) . '</h2>' . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</th></tr>';
			return;
		}
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		switch ( $type ) {
			case 'info':
				echo '<input type="text" readonly class="large-text code" id="' . esc_attr( $id ) . '" value="' . esc_attr( $help ) . '" onclick="this.select()">';
				$help = '';
				break;
			case 'checkbox':
				echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="lvk[' . esc_attr( $key ) . ']" value="1" ' . checked( (int) $value, 1, false ) . '> ' . esc_html( $help ) . '</label>';
				$help = '';
				break;
			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="lvk[' . esc_attr( $key ) . ']">';
				foreach ( $f[3] as $k => $l ) {
					echo '<option value="' . esc_attr( $k ) . '" ' . selected( (string) $value, (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
				}
				echo '</select>';
				break;
			case 'textarea':
				$placeholder = 'cod_message' === $key ? Settings::default_cod_message() : ( 'wa_template' === $key ? Settings::default_wa_template() : '' );
				echo '<textarea id="' . esc_attr( $id ) . '" name="lvk[' . esc_attr( $key ) . ']" rows="4" class="large-text" placeholder="' . esc_attr( $placeholder ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
				break;
			case 'number':
				echo '<input type="number" step="any" min="0" id="' . esc_attr( $id ) . '" name="lvk[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '" class="small-text">';
				break;
			case 'password':
				// Never print saved secrets back into the page.
				$ph = '' !== (string) $value ? __( 'Saved — leave empty to keep', 'lavkhata' ) : '';
				echo '<input type="password" autocomplete="new-password" id="' . esc_attr( $id ) . '" name="lvk[' . esc_attr( $key ) . ']" value="" placeholder="' . esc_attr( $ph ) . '" class="regular-text">';
				break;
			default:
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="lvk[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '" class="regular-text">';
		}
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function save() {
		if ( ! current_user_can( Admin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'lavkhata' ), 403 );
		}
		check_admin_referer( 'lvk_save_settings' );
		$tabs  = self::tabs();
		$tab   = isset( $_POST['tab'] ) && isset( $tabs[ $_POST['tab'] ] ) ? sanitize_key( $_POST['tab'] ) : 'general';
		$input = isset( $_POST['lvk'] ) && is_array( $_POST['lvk'] ) ? wp_unslash( $_POST['lvk'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised per field below.
		$clean = array();
		foreach ( self::fields( $tab ) as $key => $f ) {
			$type = $f[0];
			if ( in_array( $type, array( 'heading', 'info' ), true ) ) {
				continue;
			}
			$raw = isset( $input[ $key ] ) ? $input[ $key ] : null;
			switch ( $type ) {
				case 'checkbox':
					$clean[ $key ] = $raw ? 1 : 0;
					break;
				case 'number':
					$clean[ $key ] = max( 0, (float) $raw );
					break;
				case 'select':
					$clean[ $key ] = isset( $f[3][ $raw ] ) ? (string) $raw : Settings::get( $key );
					break;
				case 'textarea':
					$clean[ $key ] = sanitize_textarea_field( (string) $raw );
					break;
				case 'password':
					if ( null !== $raw && '' !== trim( (string) $raw ) ) {
						$clean[ $key ] = trim( (string) $raw );
					}
					break;
				default:
					$clean[ $key ] = sanitize_text_field( (string) $raw );
			}
		}
		if ( isset( $clean['meta_pixel_id'] ) ) {
			$clean['meta_pixel_id'] = preg_replace( '/\D/', '', $clean['meta_pixel_id'] );
		}
		Settings::update( $clean );
		\Lavkhata\Install::ensure_secrets();
		Admin::notice( __( 'Settings saved.', 'lavkhata' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=lavkhata-settings&tab=' . $tab ) );
		exit;
	}

	public static function test_courier() {
		if ( ! current_user_can( Admin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'lavkhata' ), 403 );
		}
		check_admin_referer( 'lvk_test_courier' );
		$id = isset( $_GET['courier'] ) ? sanitize_key( $_GET['courier'] ) : '';
		if ( 'steadfast' === $id ) {
			$sf  = new Steadfast();
			$bal = $sf->is_ready() ? $sf->balance() : null;
			if ( null === $bal ) {
				Admin::notice( __( 'Steadfast: could not connect. Check the API key and secret key.', 'lavkhata' ), 'error' );
			} else {
				/* translators: %s: balance */
				Admin::notice( sprintf( __( 'Steadfast connected. Current balance: %s', 'lavkhata' ), Helpers::taka( $bal ) ) );
			}
		} elseif ( 'pathao' === $id ) {
			$pa = new Pathao();
			if ( ! $pa->is_ready() || '' === $pa->token( true ) ) {
				Admin::notice( __( 'Pathao: could not log in. Check client ID, secret, email and password.', 'lavkhata' ), 'error' );
			} else {
				$stores = $pa->stores();
				$list   = array();
				foreach ( $stores as $sid => $name ) {
					$list[] = $name . ' (ID ' . $sid . ')';
				}
				/* translators: %s: stores */
				Admin::notice( sprintf( __( 'Pathao connected. Stores: %s', 'lavkhata' ), $list ? implode( ', ', $list ) : __( 'none found', 'lavkhata' ) ) );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=lavkhata-settings&tab=couriers' ) );
		exit;
	}
}
