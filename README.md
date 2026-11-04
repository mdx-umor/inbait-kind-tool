# Lavkhata (লাভখাতা)

A WooCommerce plugin for Bangladeshi online shops: real profit after delivery, Steadfast and Pathao booking with status sync, COD fraud protection, Meta Conversions API on delivered orders, WhatsApp order confirmation and restock planning. English and Bangla.

## What's in this repo

| Folder | What it is |
|---|---|
| `lavkhata/` | The WordPress plugin. Zip this folder and upload it in WordPress → Plugins → Add New → Upload. |
| `site/` | The product landing page (static, deployed on Vercel). |
| `tests/` | Logic tests that run without WordPress (fake WP/WooCommerce functions + SQLite). |
| `tools/` | `build-zip.sh` makes the installable zip, `build-translations.py` rebuilds the Bangla translation files. |

## Features

- **Real profit**: sales count only when the courier delivers. Product cost, delivery charge, COD fee, return charge, packaging, payment fees, ad spend with VAT and other expenses are all taken off. Monthly P&L with CSV export, and profit on every order.
- **Steadfast and Pathao**: book one order or many from the orders list. Status comes back by webhook immediately, with a full check every night. Starts in **Report only** mode; turn on **Auto-update** to let the courier move orders to *With courier → Completed / Returned*. Thermal labels with barcode and items.
- **COD protection**: each order shows the customer's delivery record from Steadfast, Pathao and your own shop. At checkout, risky customers pay an advance by bKash, Nagad or Rocket (or pay in full online).
- **Meta ads**: a Purchase is sent to the Conversions API when an order is *delivered*, so one delivered order = one Purchase. Optional browser pixel. Break-even cost per purchase on the dashboard.
- **WhatsApp confirm**: one button opens WhatsApp with a ready message and a one-tap confirm link (no WhatsApp API needed).
- **Restock planner**: days of stock left, when to reorder and how many.

## Install

1. Run `tools/build-zip.sh` (or download the zip from the landing page).
2. WordPress → Plugins → Add New → Upload Plugin → choose the zip → Activate. WooCommerce must be active.
3. Lavkhata → Settings → **Couriers**: add your Steadfast and/or Pathao API details and click **Test connection**.
4. Copy the webhook URL and token shown there into your Steadfast / Pathao merchant panel.
5. Add a **Cost price** to your products (Product data → General).
6. WooCommerce → Settings → Payments: enable **Advance + Cash on Delivery (Lavkhata)** and add your bKash / Nagad numbers.
7. Optional: Lavkhata → Settings → **Meta ads**: pixel ID and Conversions API token.

## Run the tests

```bash
php tests/run.php
```

## Notes

- Steadfast endpoints used: `create_order`, `status_by_cid`, `get_balance`, `fraud_check/score` on `portal.packzy.com/api/v1`. Pathao: `issue-token`, `orders`, `orders/{id}/info`, `stores` on `api-hermes.pathao.com`, plus the customer success-rate endpoint on `merchant.pathao.com`. These come from the couriers' merchant APIs as used by open-source integrations. Check them with your own API keys before going live.
- The advance payment option needs the classic (shortcode) checkout. On the block checkout, risky customers are asked to pay online instead.
- Removing the plugin keeps your books. Add `define( 'LAVKHATA_REMOVE_ALL_DATA', true );` to `wp-config.php` first to remove everything.
