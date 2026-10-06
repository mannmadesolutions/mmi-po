<?php
/**
 * Fallback product sizes from mmi-data-pipeline's supplier feeds, used only when a product has
 * no WooCommerce weight/dimensions. Read-only: nothing is written to products or to the pipeline.
 *
 * Import profiles don't map size columns today, so columns are recognised by header name
 * ("UNIT WEIGHT (LBS.)", "UNIT LENGTH (IN.)", …) with units taken from the header. A feed is
 * sniffed from its first 64 KB before any full parse, so a large feed with no size columns
 * (XChange, ~13 MB) is never decoded; feeds that have them are indexed once per file version
 * and cached. Without mmi-data-pipeline (no sources table) this class simply finds nothing.
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_PO_Feed_Sizes {

	const SNIFF_BYTES    = 65536;
	const CACHE_PREFIX   = 'mmi_po_feed_sizes_';
	const CACHE_TTL      = 12 * HOUR_IN_SECONDS;
	const MAX_FEED_BYTES = 64 * 1024 * 1024;

	/** Header patterns → size part. Checked against lower-cased keys. */
	const SIZE_PATTERNS = array(
		'weight' => '/\bweight\b|\bwt\b/',
		'length' => '/\blength\b|\bdepth\b/',
		'width'  => '/\bwidth\b/',
		'height' => '/\bheight\b/',
	);

	/** Identifier headers: gtin-like values match _global_unique_id; codes match SKU / MPN. */
	const GTIN_PATTERN = '/\b(upc|gtin|ean|barcode|upc_code)\b/';
	const CODE_PATTERN = '/^(item|sku|mpn|model|part|part number|item number|item #|our_sku|manufacturer part number)$/';

	/** Keys that mention a size word but describe something else (a cable's length, a taxonomy). */
	const EXCLUDE_PATTERN = '/^tax:|cable|cord|term|subscription|screen|display/';

	/**
	 * Sizes for products that WooCommerce has none for.
	 *
	 * @param array $products id => array{gtin: string, sku: string, mpn: string}
	 * @return array id => array{dims: ?array, weight_lb: ?float, source: string}
	 */
	public static function lookup( array $products ): array {
		if ( ! $products ) {
			return array();
		}
		$found = array();
		foreach ( self::indexes() as $index ) {
			foreach ( $products as $id => $keys ) {
				if ( isset( $found[ $id ] ) ) {
					continue;
				}
				foreach ( array( 'g:' . self::norm_gtin( $keys['gtin'] ?? '' ), 'c:' . self::norm_code( $keys['mpn'] ?? '' ), 'c:' . self::norm_code( $keys['sku'] ?? '' ) ) as $k ) {
					if ( strlen( $k ) > 2 && isset( $index['rows'][ $k ] ) ) {
						$found[ $id ] = $index['rows'][ $k ] + array( 'source' => $index['label'] );
						break;
					}
				}
			}
		}
		return $found;
	}

	/** Every data source's size index (only the ones whose feed actually has size columns). */
	private static function indexes(): array {
		static $memo = null;
		if ( $memo !== null ) {
			return $memo;
		}
		$memo = array();
		foreach ( self::sources() as $source ) {
			$index = self::index_for( $source['supplier_id'], $source['label'] );
			if ( $index && $index['rows'] ) {
				$memo[] = $index;
			}
		}
		return $memo;
	}

	private static function sources(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'mmi_data_sources';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$out = array();
		foreach ( $wpdb->get_results( "SELECT supplier_id, supplier_name FROM {$table} ORDER BY display_order, id", ARRAY_A ) as $row ) {
			$out[] = array(
				'supplier_id' => sanitize_file_name( (string) $row['supplier_id'] ),
				'label'       => (string) $row['supplier_name'],
			);
		}
		return $out;
	}

	/** Cached per feed file version (path + mtime + size), so a refreshed feed re-indexes itself. */
	private static function index_for( string $supplier_id, string $label ): ?array {
		if ( ! function_exists( 'mmi_shared_lib_private_subdir' ) ) {
			return null;
		}
		$file = mmi_shared_lib_private_subdir( 'json' ) . $supplier_id . '-products.json';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$size    = (int) filesize( $file );
		$version = md5( $file . '|' . filemtime( $file ) . '|' . $size );
		$key     = self::CACHE_PREFIX . md5( $supplier_id );
		$cached  = get_transient( $key );
		if ( is_array( $cached ) && ( $cached['version'] ?? '' ) === $version ) {
			return $cached;
		}

		$index = array( 'version' => $version, 'label' => $label, 'rows' => array() );
		$sniff = (string) file_get_contents( $file, false, null, 0, self::SNIFF_BYTES );
		preg_match_all( '/"([^"\\\\]{1,80})"\s*:/', $sniff, $m );
		$columns = self::detect_columns( array_unique( $m[1] ) );

		if ( $columns && $size <= self::MAX_FEED_BYTES ) {
			$started = microtime( true );
			$data    = json_decode( (string) file_get_contents( $file ), true );
			$rows    = is_array( $data ) && array_is_list( $data ) ? $data : ( is_array( $data ) ? self::first_list( $data ) : array() );
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$entry = self::size_from_row( $row, $columns );
				if ( ! $entry ) {
					continue;
				}
				foreach ( $columns['gtin'] as $col ) {
					$g = self::norm_gtin( (string) ( $row[ $col ] ?? '' ) );
					if ( $g !== '' ) {
						$index['rows'][ 'g:' . $g ] = $entry;
					}
				}
				foreach ( $columns['code'] as $col ) {
					$c = self::norm_code( (string) ( $row[ $col ] ?? '' ) );
					if ( $c !== '' ) {
						$index['rows'][ 'c:' . $c ] = $entry;
					}
				}
			}
			unset( $data, $rows );
			MMI_Logger::info( 'Indexed feed sizes for PO box estimates', array( 'source' => $supplier_id, 'keys' => count( $index['rows'] ), 'ms' => (int) ( ( microtime( true ) - $started ) * 1000 ) ), 'general', 'MMI_PO_Feed_Sizes' );
		}
		set_transient( $key, $index, self::CACHE_TTL );
		return $index;
	}

	/** @return array|null {weight: [col, factor], length/width/height: [col, factor], gtin: [], code: []} */
	private static function detect_columns( array $keys ): ?array {
		$cols = array( 'gtin' => array(), 'code' => array() );
		foreach ( $keys as $key ) {
			$k = strtolower( trim( $key ) );
			if ( preg_match( self::GTIN_PATTERN, $k ) ) {
				$cols['gtin'][] = $key;
				continue;
			}
			if ( preg_match( self::CODE_PATTERN, $k ) ) {
				$cols['code'][] = $key;
				continue;
			}
			if ( preg_match( self::EXCLUDE_PATTERN, $k ) ) {
				continue;
			}
			foreach ( self::SIZE_PATTERNS as $part => $pattern ) {
				if ( ! isset( $cols[ $part ] ) && preg_match( $pattern, $k ) ) {
					$cols[ $part ] = array( $key, $part === 'weight' ? self::weight_factor( $k ) : self::length_factor( $k ) );
				}
			}
		}
		$has_size = isset( $cols['weight'] ) || ( isset( $cols['length'], $cols['width'], $cols['height'] ) );
		return $has_size && ( $cols['gtin'] || $cols['code'] ) ? $cols : null;
	}

	private static function size_from_row( array $row, array $cols ): ?array {
		$val = static function ( $part ) use ( $row, $cols ) {
			if ( ! isset( $cols[ $part ] ) ) {
				return 0.0;
			}
			return (float) preg_replace( '/[^0-9.]/', '', (string) ( $row[ $cols[ $part ][0] ] ?? '' ) ) * $cols[ $part ][1];
		};
		$l = $val( 'length' );
		$w = $val( 'width' );
		$h = $val( 'height' );
		$g = $val( 'weight' );
		$dims = $l > 0 && $w > 0 && $h > 0 ? array( round( $l, 2 ), round( $w, 2 ), round( $h, 2 ) ) : null;
		if ( ! $dims && $g <= 0 ) {
			return null;
		}
		return array( 'dims' => $dims, 'weight_lb' => $g > 0 ? round( $g, 3 ) : null );
	}

	/** Header unit → pounds. Unmarked weight headers are taken as pounds (US supplier feeds). */
	private static function weight_factor( string $k ): float {
		if ( preg_match( '/\b(oz|ounces?)\b/', $k ) ) {
			return 1 / 16;
		}
		if ( preg_match( '/\b(kg|kilograms?)\b/', $k ) ) {
			return 2.20462;
		}
		if ( preg_match( '/\b(g|grams?)\b/', $k ) ) {
			return 1 / 453.592;
		}
		return 1.0;
	}

	/** Header unit → inches. Unmarked is inches. */
	private static function length_factor( string $k ): float {
		if ( preg_match( '/\b(cm|centimet(er|re)s?)\b/', $k ) ) {
			return 1 / 2.54;
		}
		if ( preg_match( '/\b(mm|millimet(er|re)s?)\b/', $k ) ) {
			return 1 / 25.4;
		}
		return 1.0;
	}

	private static function first_list( array $data ): array {
		foreach ( $data as $v ) {
			if ( is_array( $v ) && array_is_list( $v ) ) {
				return $v;
			}
		}
		return array();
	}

	private static function norm_gtin( string $v ): string {
		$d = preg_replace( '/\D/', '', $v );
		return strlen( $d ) >= 8 ? ltrim( $d, '0' ) : '';
	}

	private static function norm_code( string $v ): string {
		$c = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $v ) );
		return strlen( $c ) >= 3 ? $c : '';
	}
}
