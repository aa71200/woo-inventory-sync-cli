<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' || ! getenv( 'CODEXAWP_TEST_WP_LOAD' ) ) {
	throw new RuntimeException( 'Set CODEXAWP_TEST_WP_LOAD to a disposable WordPress installation.' );
}
require getenv( 'CODEXAWP_TEST_WP_LOAD' );
if ( 'local' !== wp_get_environment_type() || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'This fixture requires local WordPress with WooCommerce active.' );
}

function check( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
	echo "PASS: $message\n";
}

use function CodexaWP\Inventory\apply;
use function CodexaWP\Inventory\plan;

$create = static function ( int $stock ): \WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( 'Inventory sample' );
	$product->set_sku( 'DEMO-' . wp_generate_password( 12, false ) );
	$product->set_regular_price( '10' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( $stock );
	$product->save();
	return $product;
};
$first = $create( 20 );
$second = $create( 6 );
$line = [ 'sku' => $first->get_sku(), 'stock' => '8', 'version' => '1' ];
$other = [ 'sku' => $second->get_sku(), 'stock' => '4', 'version' => '1' ];
$preview = plan( [ $line, $other ] );
check( 'ready' === $preview[0]['status'] && 20 === (int) wc_get_product( $first->get_id() )->get_stock_quantity(), 'Preview leaves stock untouched' );
wc_update_product_stock( $second, 5 );
$result = apply( $preview );
check( 'applied' === $result[0]['status'] && 8 === (int) wc_get_product( $first->get_id() )->get_stock_quantity(), 'Applies the valid stock change' );
check( 'conflict' === $result[1]['status'] && 5 === (int) wc_get_product( $second->get_id() )->get_stock_quantity(), 'Preserves a change made after preview' );
check( 'stale' === apply( $preview )[0]['status'], 'Replaying the saved plan does not reapply stock' );
check( 'duplicate' === plan( [ $other, $other ] )[0]['status'], 'Duplicate SKU is flagged' );
check( 'unmatched' === plan( [ [ 'sku' => 'NOT-IN-FIXTURE', 'stock' => '2', 'version' => '1' ] ] )[0]['status'], 'Unmatched SKU is flagged' );
$parent = new WC_Product_Variable();
$parent->set_name( 'Variable fixture' );
$parent->save();
$variant = new WC_Product_Variation();
$variant->set_parent_id( $parent->get_id() );
$variant->set_sku( 'VAR-' . wp_generate_password( 10, false ) );
$variant->set_regular_price( '12' );
$variant->set_manage_stock( true );
$variant->set_stock_quantity( 9 );
$variant->save();
$variation_plan = plan( [ [ 'sku' => $variant->get_sku(), 'stock' => '7', 'version' => '1' ] ] );
check( 'applied' === apply( $variation_plan )[0]['status'] && 7 === (int) wc_get_product( $variant->get_id() )->get_stock_quantity(), 'Updates stock owned by a variation' );
