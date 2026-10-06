<?php
/**
 * Catalog search for the PO editor: title words, SKU, MPN or GTIN, optionally limited to a
 * supplier's brands. One search query plus three batched enrichment queries, whatever the hit count.
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_PO_Products {

	const LIMIT      = 25;
	const MAX_WORDS  = 6;
	const COST_META  = '__mmi_cog';
	const CODE_METAS = array( 'mpn', '_global_unique_id' );
	const SIZE_METAS = array( '_length', '_width', '_height', '_weight' );
	const STATUSES   = array( 'publish', 'draft', 'private', 'pending' );

	public static function search( string $term, array $brand_ids = array() ): array {
		global $wpdb;

		$term  = trim( $term );
		$words = array_slice( preg_split( '/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY ), 0, self::MAX_WORDS );
		if ( ! $words ) {
			return array();
		}

		$title_sql = array();
		foreach ( $words as $word ) {
			$title_sql[] = $wpdb->prepare( 'p.post_title LIKE %s', '%' . $wpdb->esc_like( $word ) . '%' );
		}
		$prefix      = $wpdb->esc_like( $term ) . '%';
		$code_keys   = "'" . implode( "','", array_map( 'esc_sql', self::CODE_METAS ) ) . "'";
		$status_list = "'" . implode( "','", array_map( 'esc_sql', self::STATUSES ) ) . "'";
		$lookup      = $wpdb->prefix . 'wc_product_meta_lookup';

		$brand_sql = '';
		$brand_ids = array_filter( array_map( 'absint', $brand_ids ) );
		if ( $brand_ids ) {
			$brand_sql = " AND COALESCE(NULLIF(p.post_parent, 0), p.ID) IN (
				SELECT tr.object_id FROM {$wpdb->term_relationships} tr
				JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tt.taxonomy = 'product_brand' AND tt.term_id IN (" . implode( ',', $brand_ids ) . '))';
		}

		// Rank: exact code match, then code prefix, then title-only matches.
		$sql = $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_status, p.post_parent, l.sku, l.stock_status, l.stock_quantity,
				CASE
					WHEN l.sku = %s OR code.post_id IS NOT NULL THEN 0
					WHEN l.sku LIKE %s OR codep.post_id IS NOT NULL THEN 1
					ELSE 2
				END AS rank_order
			FROM {$wpdb->posts} p
			LEFT JOIN {$lookup} l ON l.product_id = p.ID
			LEFT JOIN (SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$code_keys}) AND meta_value = %s) code ON code.post_id = p.ID
			LEFT JOIN (SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$code_keys}) AND meta_value LIKE %s) codep ON codep.post_id = p.ID
			WHERE p.post_type IN ('product', 'product_variation')
				AND p.post_status IN ({$status_list})
				AND ( (" . implode( ' AND ', $title_sql ) . ') OR l.sku LIKE %s OR codep.post_id IS NOT NULL )'
				. $brand_sql . "
			ORDER BY rank_order ASC, (p.post_status = 'publish') DESC, p.post_title ASC
			LIMIT %d",
			$term,
			$prefix,
			$term,
			$prefix,
			$prefix,
			self::LIMIT
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		if ( ! $rows ) {
			return array();
		}

		return self::enrich( $rows );
	}

	/** Same result shape as search(), for known product ids (e.g. a WooCommerce order's lines). */
	public static function by_ids( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		$rows   = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_status, p.post_parent, l.sku, l.stock_status, l.stock_quantity
			 FROM {$wpdb->posts} p LEFT JOIN {$lookup} l ON l.product_id = p.ID
			 WHERE p.ID IN (" . implode( ',', $ids ) . ')',
			ARRAY_A
		);
		return $rows ? self::enrich( $rows ) : array();
	}

	/**
	 * Catalog size per product id, in inches and pounds, for PO lines loaded from the database.
	 *
	 * @return array<int, array{dims: ?array, weight_lb: ?float}>
	 */
	public static function sizes( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$meta = array();
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE post_id IN (" . implode( ',', $ids ) . ") AND meta_key IN ('" . implode( "','", array_merge( self::SIZE_METAS, array( '_sku', 'mpn', '_global_unique_id' ) ) ) . "')",
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			$meta[ (int) $r['post_id'] ][ $r['meta_key'] ] = $r['meta_value'];
		}
		$out       = array();
		$need_feed = array();
		foreach ( $ids as $id ) {
			$m          = $meta[ $id ] ?? array();
			$out[ $id ] = self::size_from_meta( $m );
			if ( self::size_missing( $out[ $id ] ) ) {
				$need_feed[ $id ] = array( 'gtin' => (string) ( $m['_global_unique_id'] ?? '' ), 'sku' => (string) ( $m['_sku'] ?? '' ), 'mpn' => (string) ( $m['mpn'] ?? '' ) );
			}
		}
		foreach ( MMI_PO_Feed_Sizes::lookup( $need_feed ) as $id => $size ) {
			$out[ $id ] = self::merge_size( $out[ $id ], $size );
		}
		return $out;
	}

	/** WooCommerce has neither full dimensions nor a weight. */
	private static function size_missing( array $s ): bool {
		return $s['dims'] === null || $s['weight_lb'] === null;
	}

	/** Fills only what WooCommerce lacks; WooCommerce values always win. */
	private static function merge_size( array $item, array $feed ): array {
		$used = false;
		if ( $item['dims'] === null && $feed['dims'] !== null ) {
			$item['dims'] = $feed['dims'];
			$used         = true;
		}
		if ( $item['weight_lb'] === null && $feed['weight_lb'] !== null ) {
			$item['weight_lb'] = $feed['weight_lb'];
			$used              = true;
		}
		if ( $used ) {
			$item['size_source'] = $item['size_source'] === '' ? $feed['source'] : $item['size_source'] . ' + ' . $feed['source'];
		}
		return $item;
	}

	/** WooCommerce's own dimension/weight fields, converted from the store's units to in / lb. */
	private static function size_from_meta( array $m ): array {
		$l = (float) ( $m['_length'] ?? 0 );
		$w = (float) ( $m['_width'] ?? 0 );
		$h = (float) ( $m['_height'] ?? 0 );
		$g = (float) ( $m['_weight'] ?? 0 );
		$dim_unit    = get_option( 'woocommerce_dimension_unit', 'in' );
		$weight_unit = get_option( 'woocommerce_weight_unit', 'lbs' );
		$has_dims = $l > 0 && $w > 0 && $h > 0;
		return array(
			'size_source' => $has_dims || $g > 0 ? 'WooCommerce' : '',
			'dims'      => $l > 0 && $w > 0 && $h > 0
				? array( round( wc_get_dimension( $l, 'in', $dim_unit ), 2 ), round( wc_get_dimension( $w, 'in', $dim_unit ), 2 ), round( wc_get_dimension( $h, 'in', $dim_unit ), 2 ) )
				: null,
			'weight_lb' => $g > 0 ? round( wc_get_weight( $g, 'lbs', $weight_unit ), 3 ) : null,
		);
	}

	/** Adds cost, codes, brand, last PO cost and suggested supplier to raw search rows. */
	private static function enrich( array $rows ): array {
		global $wpdb;

		$ids        = array_map( 'intval', wp_list_pluck( $rows, 'ID' ) );
		$parent_ids = array_filter( array_map( 'intval', wp_list_pluck( $rows, 'post_parent' ) ) );
		$id_list    = implode( ',', $ids );

		$meta_keys = array_merge( array( self::COST_META, '_regular_price' ), self::CODE_METAS, self::SIZE_METAS );
		$meta      = array();
		$meta_rows = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE post_id IN ({$id_list}) AND meta_key IN ('" . implode( "','", array_map( 'esc_sql', $meta_keys ) ) . "')",
			ARRAY_A
		);
		foreach ( $meta_rows as $m ) {
			$meta[ (int) $m['post_id'] ][ $m['meta_key'] ] = $m['meta_value'];
		}

		$brands = array();
		$terms  = wp_get_object_terms( array_merge( $ids, $parent_ids ), 'product_brand', array( 'fields' => 'all_with_object_id' ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( ! isset( $brands[ (int) $t->object_id ] ) ) {
					$brands[ (int) $t->object_id ] = array( 'id' => (int) $t->term_id, 'name' => $t->name );
				}
			}
		}

		$last_cost = array();
		$history   = $wpdb->get_results(
			'SELECT i.product_id, i.unit_cost, o.po_number, o.po_date FROM ' . MMI_PO_Install::table( 'items' ) . ' i
			 JOIN ' . MMI_PO_Install::table( 'orders' ) . " o ON o.id = i.po_id
			 WHERE i.product_id IN ({$id_list}) AND o.status <> 'cancelled'
			 ORDER BY o.po_date DESC, i.id DESC",
			ARRAY_A
		);
		foreach ( $history as $h ) {
			$pid = (int) $h['product_id'];
			if ( ! isset( $last_cost[ $pid ] ) ) {
				$last_cost[ $pid ] = array( 'cost' => (float) $h['unit_cost'], 'po_number' => $h['po_number'], 'date' => $h['po_date'] );
			}
		}

		$brand_map = MMI_PO_Suppliers::brand_map();
		$out       = array();
		$need_feed = array();
		foreach ( $rows as $row ) {
			$id    = (int) $row['ID'];
			$m     = $meta[ $id ] ?? array();
			$brand = $brands[ $id ] ?? ( $brands[ (int) $row['post_parent'] ] ?? null );
			$mpn   = trim( (string) ( $m['mpn'] ?? '' ) );
			$cost  = isset( $m[ self::COST_META ] ) && $m[ self::COST_META ] !== '' ? (float) $m[ self::COST_META ] : null;

			$out[] = array(
				'product_id'   => $id,
				'title'        => html_entity_decode( $row['post_title'], ENT_QUOTES, 'UTF-8' ),
				'status'       => $row['post_status'],
				'sku'          => (string) $row['sku'],
				'mpn'          => $mpn,
				'gtin'         => (string) ( $m['_global_unique_id'] ?? '' ),
				'item_number'  => $mpn !== '' ? $mpn : (string) $row['sku'],
				'brand'        => $brand ? $brand['name'] : '',
				'brand_id'     => $brand ? $brand['id'] : 0,
				'cost'         => $cost,
				'price'        => isset( $m['_regular_price'] ) && $m['_regular_price'] !== '' ? (float) $m['_regular_price'] : null,
				'last'         => $last_cost[ $id ] ?? null,
				'stock_status' => (string) $row['stock_status'],
				'stock_qty'    => $row['stock_quantity'] === null ? null : (float) $row['stock_quantity'],
				'supplier_id'  => $brand ? ( $brand_map[ $brand['id'] ] ?? 0 ) : 0,
			) + self::size_from_meta( $m );
			if ( self::size_missing( end( $out ) ) ) {
				$need_feed[ count( $out ) - 1 ] = array( 'gtin' => (string) ( $m['_global_unique_id'] ?? '' ), 'sku' => (string) $row['sku'], 'mpn' => $mpn );
			}
		}
		foreach ( MMI_PO_Feed_Sizes::lookup( $need_feed ) as $i => $size ) {
			$out[ $i ] = self::merge_size( $out[ $i ], $size );
		}
		return $out;
	}
}
