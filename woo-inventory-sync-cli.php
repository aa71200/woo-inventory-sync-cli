<?php
/**
 * Plugin Name: CodexaWP Inventory Sync CLI
 * Description: Review and apply versioned stock snapshots for managed products.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace CodexaWP\Inventory;
defined( 'ABSPATH' ) || exit;

const VERSION_KEY = '_codexawp_stock_version';

function plan( array $rows ): array {
	$occurrences = array_count_values( array_column( $rows, 'sku' ) );
	$result = [];
	foreach ( $rows as $row ) {
		$sku = $row['sku'];
		$product = wc_get_product( wc_get_product_id_by_sku( $sku ) );
		$version = filter_var( $row['version'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
		$quantity = filter_var( $row['stock'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] );
		$entry = [
			'sku' => $sku, 'version' => $version, 'after' => $quantity,
			'id' => $product ? $product->get_id() : 0, 'before' => $product ? $product->get_stock_quantity() : null,
		];
		if ( $occurrences[$sku] > 1 ) {
			$entry['status'] = 'duplicate';
		} elseif ( false === $version || false === $quantity || '' === $sku ) {
			$entry['status'] = 'invalid';
		} elseif ( ! $product || $sku !== $product->get_sku() ) {
			$entry['status'] = 'unmatched';
		} elseif ( ! $product->managing_stock() || $product->get_id() !== $product->get_stock_managed_by_id() ) {
			$entry['status'] = 'shared_or_unmanaged_stock';
		} elseif ( $version <= (int) $product->get_meta( VERSION_KEY ) ) {
			$entry['status'] = 'stale';
		} else {
			$entry['status'] = 'ready';
		}
		$result[] = $entry;
	}
	return $result;
}

function apply( array $entries ): array {
	global $wpdb;
	foreach ( $entries as &$entry ) {
		if ( 'ready' !== $entry['status'] ) {
			continue;
		}
		$id = $entry['id'];
		$lock = 'codexa.stock.' . md5( $wpdb->prefix . $id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock ) ) ) {
			$entry['status'] = 'busy';
			continue;
		}
		try {
			$product = wc_get_product( $id );
			$stock = $product ? get_post_meta( $id, '_stock', true ) : null;
			$version = (int) get_post_meta( $id, VERSION_KEY, true );
			if ( ! $product || $product->get_sku() !== $entry['sku'] || ! $product->managing_stock() || $id !== $product->get_stock_managed_by_id() ) {
				$entry['status'] = 'conflict';
			} elseif ( $version >= $entry['version'] ) {
				$entry['status'] = 'stale';
			} elseif ( ! is_numeric( $stock ) || (float) $stock !== (float) $entry['before'] ) {
				$entry['status'] = 'conflict';
			} else {
				$updated = wc_update_product_stock( $product, $entry['after'], 'set' );
				if ( ! is_numeric( $updated ) || (float) $updated !== (float) $entry['after'] ) {
					throw new \RuntimeException( 'WooCommerce did not report the requested quantity.' );
				}
				$product->update_meta_data( VERSION_KEY, $entry['version'] );
				$product->save_meta_data();
				$entry['status'] = 'applied';
			}
		} catch ( \Throwable $failure ) {
			$entry['status'] = 'error';
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
	unset( $entry );
	return $entries;
}

function parse( string $file ): array {
	$handle = fopen( $file, 'rb' );
	if ( ! $handle ) {
		throw new \RuntimeException( 'Cannot read the CSV file.' );
	}
	try {
		if ( [ 'sku', 'stock', 'version' ] !== fgetcsv( $handle, 0, ',', '"', '' ) ) {
			throw new \RuntimeException( 'Expected header: sku,stock,version.' );
		}
		$rows = [];
		while ( false !== ( $fields = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
			if ( [ null ] === $fields ) {
				continue;
			}
			if ( 3 !== count( $fields ) ) {
				throw new \RuntimeException( 'Every row must have exactly three columns.' );
			}
			$rows[] = array_combine( [ 'sku', 'stock', 'version' ], $fields );
		}
		return $rows;
	} finally {
		fclose( $handle );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'codexawp stock', static function ( array $args, array $flags ): void {
		try {
			if ( isset( $flags['apply'] ) ) {
				$saved = json_decode( file_get_contents( $args[0] ), true, 512, JSON_THROW_ON_ERROR );
				$entries = apply( $saved );
			} else {
				$entries = plan( parse( $args[0] ) );
			}
			\WP_CLI::line( wp_json_encode( $entries, JSON_PRETTY_PRINT ) );
		} catch ( \Throwable $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
	}, [
		'synopsis' => [
			[ 'type' => 'positional', 'name' => 'input' ],
			[ 'type' => 'flag', 'name' => 'apply', 'optional' => true ],
		],
	] );
}
