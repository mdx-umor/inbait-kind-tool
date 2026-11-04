=== Lavkhata – COD Profit, Courier Sync & Fraud Guard ===
Tags: woocommerce, bangladesh, steadfast, pathao, cod
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Real profit after delivery, one-click Steadfast & Pathao booking, COD fraud protection, Meta Conversions API on delivered orders, WhatsApp confirmation and restock planning for Bangladeshi WooCommerce shops.

== Description ==

**Real profit, not "sales"**
* Sales count only when the courier delivers.
* Every cost is taken off: product cost, delivery charge, COD fee, return charge, packaging, payment fees, ad spend with VAT, and your other expenses.
* Monthly Profit & Loss with a CSV export for your accountant.
* Real profit shown on every order.

**Steadfast & Pathao**
* "Send to Steadfast" / "Send to Pathao" on every order, or in bulk.
* Courier status comes back by webhook immediately, plus a full check every night.
* Starts in "Report only" mode. Switch on "Auto-update" to let the courier move orders to With courier → Completed / Returned.
* Thermal labels (75×100, 100×150 or 58×40 mm) with barcode and items.

**COD protection**
* Every order shows the customer's delivery record: parcels and delivery rate across Steadfast, Pathao and your own shop.
* At checkout, risky customers are asked to pay an advance by bKash / Nagad / Rocket (or pay in full online). The rest is paid on delivery.

**Meta ads**
* Sends a Purchase to Meta's Conversions API when an order is delivered: one delivered order = one Purchase.
* Optional browser pixel (PageView, ViewContent, AddToCart, InitiateCheckout). No GTM or Stape needed.
* Break-even cost per purchase and "are my ads profitable?" on the dashboard.

**WhatsApp confirm**
* One button opens WhatsApp with a ready message and a one-tap confirm link. No WhatsApp API needed.

**Restock planner**
* Days of stock left, when to reorder and how many.

English and Bangla (বাংলা) included.

== Installation ==

1. Upload the plugin and activate it (WooCommerce must be active).
2. Go to Lavkhata → Settings → Couriers and add your Steadfast and/or Pathao API details. Click "Test connection".
3. Copy the webhook URL and token into your Steadfast / Pathao merchant panel.
4. Add cost prices to your products (Product → General → Cost price).
5. In WooCommerce → Settings → Payments, enable "Advance + Cash on Delivery (Lavkhata)" and add your bKash / Nagad numbers.
6. Optional: add your Meta pixel ID and Conversions API token under Lavkhata → Settings → Meta ads.

== Frequently Asked Questions ==

= Will the courier status change my orders by itself? =
Only after you turn on Auto-update. New installs start in Report only mode: the status is shown and a note is added, but nothing changes.

= Does it work with the block checkout? =
Delivery records, booking, profit, Meta and WhatsApp work everywhere. The advance payment option needs the classic checkout. On the block checkout, risky customers are asked to pay online instead of COD.

= Is my data deleted when I remove the plugin? =
No. Your books are kept. Add `define( 'LAVKHATA_REMOVE_ALL_DATA', true );` to wp-config.php before deleting the plugin to remove everything.

== Changelog ==

= 1.0.0 =
* First release.
