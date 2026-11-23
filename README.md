# Inventory Sync CLI for WooCommerce

[![WordPress and WooCommerce integration checks](https://github.com/aa71200/woo-inventory-sync-cli/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/aa71200/woo-inventory-sync-cli/actions/workflows/checks.yml)

A warehouse stock update can become stale while someone reviews it. This CodexaWP WP-CLI sample flags that conflict and applies current, versioned stock updates through WooCommerce's product stock API.

## Try it

Use the columns `sku,stock,version` and assign a monotonically increasing integer version for the stock snapshot. On a local test store with WP-CLI, run `wp codexawp stock examples/stock.csv > preview.json`. Review the plan and run `wp codexawp stock preview.json --apply`. Replace the fictional SKUs with matching fixture products first. The demo reports duplicates, missing products, stale versions and stock changes detected since preview. Independently managed variations are supported. Run imports during a controlled inventory window.

## Verification

Activate this plugin on a disposable WordPress site with WooCommerce active and `WP_ENVIRONMENT_TYPE` set to `local`. Set `CODEXAWP_TEST_WP_LOAD` to the absolute path to that site's `wp-load.php`, then run `php tests/check.php` from this repository.

The WordPress fixture calls the same planning and application functions as WP-CLI. It checks that preview is read-only, valid changes persist, replay is skipped, and a quantity edited between preview and apply is preserved.

The recorded run shows a read-only preview, a conflict after stock changes, and a stale replay. It also checks an independently managed variation. [Recorded integration output](docs/verification.txt)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
