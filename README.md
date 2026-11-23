# Inventory Sync CLI for WooCommerce

An original CodexaWP CLI demo for reviewing and applying versioned warehouse stock snapshots. The storefront remains on WooCommerce's product stock API.

## Try it

Use the columns `sku,stock,version` and assign a monotonically increasing integer version for the stock snapshot. On a local test store with WP-CLI, run `wp codexawp stock examples/stock.csv > preview.json`. Review the plan and run `wp codexawp stock preview.json --apply`. Replace the fictional SKUs with matching fixture products first. The demo reports duplicates, missing products, stale versions and stock changes detected since preview. Independently managed variations are supported. Run imports during a controlled inventory window.

## Verification

Activate this plugin on a disposable WordPress site with WooCommerce active and `WP_ENVIRONMENT_TYPE` set to `local`. Set `CODEXAWP_TEST_WP_LOAD` to the absolute path to that site's `wp-load.php`, then run `php tests/check.php` from this repository.

The WordPress fixture calls the same planning and application functions as WP-CLI. It checks that preview is read-only, valid changes persist, replay is skipped, and a quantity edited between preview and apply is preserved.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
