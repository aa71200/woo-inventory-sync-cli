<?php
/**
 * Plugin Name: CodexaWP Inventory Sync CLI
 * Description: Review and apply versioned stock snapshots for managed products.
 * Version: 1.0.1
 * Requires Plugins: woocommerce
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace CodexaWP\Inventory;
defined( 'ABSPATH' ) || exit;

const VERSION_KEY = '_codexawp_stock_version';

function transactional_storage(): bool {
	global $wpdb;
	$tables = [ $wpdb->posts, $wpdb->postmeta, $wpdb->wc_product_meta_lookup, $wpdb->term_relationships, $wpdb->term_taxonomy ];
	$engines = $wpdb->get_col( $wpdb->prepare(
		'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s,%s,%s,%s,%s)',
		$tables
	) );
	return count( $tables ) === count( $engines ) && ! array_diff( $engines, [ 'InnoDB' ] );
}

function locked_values( int $id ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		// WooCommerce's native UPDATE scans this product's postmeta; lock that range before changing stock.
		'SELECT meta_id, meta_key, meta_value FROM %i FORCE INDEX (post_id) WHERE post_id = %d FOR UPDATE',
		$wpdb->postmeta,
		$id
	) );
	if ( '' !== $wpdb->last_error ) {
		throw new \RuntimeException( 'Could not lock stock and version.' );
	}
	$values = [];
	foreach ( $rows as $row ) {
		if ( ! in_array( $row->meta_key, [ '_stock', VERSION_KEY ], true ) ) {
			continue;
		}
		if ( array_key_exists( $row->meta_key, $values ) ) {
			throw new \RuntimeException( 'Duplicate stock or version record.' );
		}
		$values[ $row->meta_key ] = $row->meta_value;
		if ( '_stock' === $row->meta_key ) {
			$values['stock_meta_id'] = (int) $row->meta_id;
		}
	}
	return $values;
}

function validate_saved_plan( array $entries ): void {
	if ( ! array_is_list( $entries ) ) {
		throw new \InvalidArgumentException( 'Expected a list of prepared stock lines.' );
	}
	$products = [];
	foreach ( $entries as $entry ) {
		if ( ! is_array( $entry ) || ! in_array( $entry['status'] ?? null, [
			'duplicate', 'invalid', 'unmatched', 'shared_or_unmanaged_stock', 'stale', 'ready',
			'applied', 'error', 'conflict', 'busy', 'unsupported_storage',
		], true ) ) {
			throw new \InvalidArgumentException( 'Invalid saved stock line.' );
		}
		if ( 'ready' !== $entry['status'] ) {
			continue;
		}
		$id = $entry['id'] ?? null;
		if ( ! is_int( $id ) || $id < 1 || isset( $products[ $id ] ) ||
			! is_string( $entry['sku'] ?? null ) || '' === $entry['sku'] ||
			! is_int( $entry['version'] ?? null ) || $entry['version'] < 1 ||
			! is_int( $entry['after'] ?? null ) || $entry['after'] < 0 || ! is_numeric( $entry['before'] ?? null ) ) {
			throw new \InvalidArgumentException( 'Invalid or repeated product in saved stock plan.' );
		}
		$products[ $id ] = true;
	}
}

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
	validate_saved_plan( $entries );
	$transactional = transactional_storage();
	foreach ( $entries as &$entry ) {
		if ( 'ready' !== $entry['status'] ) {
			continue;
		}
		$id = $entry['id'];
		if ( ! $transactional ) {
			$entry['status'] = 'unsupported_storage';
			continue;
		}
		$transaction = false;
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'Could not start stock transaction.' );
			}
			$transaction = true;
			$values = locked_values( $id );
			wp_cache_delete( $id, 'post_meta' );
			wp_cache_delete( 'product-' . $id, 'products' );
			$product = wc_get_product( $id );
			$stock = $values['_stock'] ?? null;
			$version = (int) ( $values[ VERSION_KEY ] ?? 0 );
			if ( ! $product || $product->get_sku() !== $entry['sku'] || ! $product->managing_stock() || $id !== $product->get_stock_managed_by_id() ) {
				$entry['status'] = 'conflict';
			} elseif ( ! in_array( $product->get_data_store()->get_current_class_name(), [
				'WC_Product_Data_Store_CPT', 'WC_Product_Variation_Data_Store_CPT', 'WC_Product_Variable_Data_Store_CPT',
			], true ) ) {
				$entry['status'] = 'unsupported_storage';
			} elseif ( $version >= $entry['version'] ) {
				$entry['status'] = 'stale';
			} elseif ( ! is_numeric( $stock ) || (float) $stock !== (float) $entry['before'] ) {
				$entry['status'] = 'conflict';
			} else {
				// Keep WooCommerce's stock API and narrow its write to the row already locked above.
				$restrict_stock_query = static function ( string $sql, int $product_id, $quantity, string $operation ) use ( $wpdb, $id, $values ): string {
					return $id === $product_id && 'set' === $operation
						? rtrim( $sql, " \t\r\n;" ) . $wpdb->prepare( ' AND meta_id = %d', $values['stock_meta_id'] )
						: $sql;
				};
				add_filter( 'woocommerce_update_product_stock_query', $restrict_stock_query, 1, 4 );
				try {
					wc_update_product_stock( $product, $entry['after'], 'set' );
				} finally {
					remove_filter( 'woocommerce_update_product_stock_query', $restrict_stock_query, 1 );
				}
				if ( ! update_post_meta( $id, VERSION_KEY, $entry['version'] ) ) {
					throw new \RuntimeException( 'Could not persist the stock version.' );
				}
				$stored = locked_values( $id );
				if ( ! is_numeric( $stored['_stock'] ?? null ) || (float) $stored['_stock'] !== (float) $entry['after'] ||
					(int) ( $stored[ VERSION_KEY ] ?? 0 ) !== $entry['version'] || false === $wpdb->query( 'COMMIT' ) ) {
					throw new \RuntimeException( 'The stock change and version could not be committed.' );
				}
				$transaction = false;
				$entry['status'] = 'applied';
			}
		} catch ( \Throwable $failure ) {
			$entry['status'] = 'error';
		} finally {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			clean_post_cache( $id );
			wp_cache_delete( 'product-' . $id, 'products' );
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
		// A UTF-8 BOM must be consumed before fgetcsv detects a quoted header.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read the prefix of the local stream subsequently consumed by fgetcsv.
		if ( "\xEF\xBB\xBF" !== fread( $handle, 3 ) ) {
			rewind( $handle );
		}
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
