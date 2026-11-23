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

if ( '--decrease' === ( $argv[1] ?? '' ) ) {
	$product_id = (int) ( $argv[2] ?? 0 );
	add_action( 'woocommerce_product_before_set_stock', static function ( WC_Product $product ) use ( $product_id ): void {
		if ( $product_id === $product->get_id() ) {
			fwrite( STDOUT, "decreasing\n" );
			fflush( STDOUT );
		}
	} );
	wc_update_product_stock( $product_id, 3, 'decrease' );
	wp_cache_delete( $product_id, 'post_meta' );
	echo get_post_meta( $product_id, '_stock', true ) . PHP_EOL;
	exit;
}

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

$before_failure = $create( 16 );
$failed_plan = plan( [ [ 'sku' => $before_failure->get_sku(), 'stock' => '0', 'version' => '1' ] ] );
$refuse_version_write = static function ( $check, int $id, string $key ) use ( $before_failure ) {
	return $id === $before_failure->get_id() && \CodexaWP\Inventory\VERSION_KEY === $key ? false : $check;
};
add_filter( 'update_post_metadata', $refuse_version_write, 10, 3 );
try {
	check( 'error' === apply( $failed_plan )[0]['status'], 'A failed version write aborts the import' );
} finally {
	remove_filter( 'update_post_metadata', $refuse_version_write, 10 );
}
check( 16 === (int) wc_get_product( $before_failure->get_id() )->get_stock_quantity() && '' === get_post_meta( $before_failure->get_id(), \CodexaWP\Inventory\VERSION_KEY, true ), 'Rollback restores both stock and version' );
check( 'applied' === apply( $failed_plan )[0]['status'], 'The same plan can be retried after rollback' );

$concurrent = $create( 20 );
$parallel_plan = plan( [ [ 'sku' => $concurrent->get_sku(), 'stock' => '12', 'version' => '1' ] ] );
$worker = null;
$pipes = [];
$start_sale = static function ( WC_Product $product ) use ( $concurrent, &$worker, &$pipes ): void {
	if ( $product->get_id() !== $concurrent->get_id() || null !== $worker ) {
		return;
	}
	$worker = proc_open( [ PHP_BINARY, __FILE__, '--decrease', (string) $product->get_id() ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
	if ( ! is_resource( $worker ) || "decreasing\n" !== str_replace( "\r", '', (string) fgets( $pipes[1] ) ) ) {
		throw new RuntimeException( 'The separate WooCommerce stock worker did not start.' );
	}
	usleep( 100000 );
	check( proc_get_status( $worker )['running'], 'The WooCommerce stock worker waits for the import transaction' );
};
add_action( 'woocommerce_product_before_set_stock', $start_sale );
try {
	check( 'applied' === apply( $parallel_plan )[0]['status'], 'The import transaction commits while a native decrease waits' );
	if ( ! is_resource( $worker ) ) {
		throw new RuntimeException( 'Missing stock worker.' );
	}
	$sale_result = trim( (string) stream_get_contents( $pipes[1] ) );
	check( is_numeric( $sale_result ) && 9.0 === (float) $sale_result, 'The native WooCommerce decrease applies to the committed amount' );
	$fresh_stock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id=%d AND meta_key=%s', $wpdb->postmeta, $concurrent->get_id(), '_stock' ) );
	check( 9 === $fresh_stock, 'The persisted stock includes both changes' );
} finally {
	remove_action( 'woocommerce_product_before_set_stock', $start_sale );
	if ( is_resource( $worker ) ) {
		foreach ( $pipes as $pipe ) {
			fclose( $pipe );
		}
		proc_close( $worker );
	}
}
