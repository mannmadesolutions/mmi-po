<?php
/**
 * Supplier directory. Small table (tens of rows), so it is read whole and cached per request.
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_PO_Suppliers {

	const TEXT_FIELDS     = array( 'name', 'contact_name', 'phone', 'account_number' );
	const TEXTAREA_FIELDS = array( 'instructions' );

	const LABEL_POLICIES = array(
		'any'           => 'Accepts our prepaid labels',
		'supplier_only' => 'Ships on their own labels only',
	);

	private static $cache = null;

	/** @return array<int, array> Suppliers keyed by id, name order. */
	public static function all( bool $include_inactive = true ): array {
		global $wpdb;
		if ( self::$cache === null ) {
			$table       = MMI_PO_Install::table( 'suppliers' );
			self::$cache = array();
			foreach ( $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC", ARRAY_A ) as $row ) {
				self::$cache[ (int) $row['id'] ] = self::shape( $row );
			}
		}
		if ( $include_inactive ) {
			return self::$cache;
		}
		return array_filter( self::$cache, static function ( $s ) {
			return $s['active'];
		} );
	}

	public static function get( int $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/**
	 * brand term id => supplier id, active suppliers only. When two suppliers claim a brand the
	 * first by name wins; the Suppliers tab flags the overlap so it can be fixed at the source.
	 */
	public static function brand_map(): array {
		$map = array();
		foreach ( self::all( false ) as $id => $supplier ) {
			foreach ( $supplier['brand_ids'] as $term_id ) {
				if ( ! isset( $map[ $term_id ] ) ) {
					$map[ $term_id ] = $id;
				}
			}
		}
		return $map;
	}

	/** @return int|WP_Error Saved supplier id. */
	public static function save( array $raw, int $id = 0 ) {
		global $wpdb;
		$data = array();
		foreach ( self::TEXT_FIELDS as $key ) {
			$data[ $key ] = sanitize_text_field( (string) ( $raw[ $key ] ?? '' ) );
		}
		foreach ( self::TEXTAREA_FIELDS as $key ) {
			$data[ $key ] = sanitize_textarea_field( (string) ( $raw[ $key ] ?? '' ) );
		}
		if ( $data['name'] === '' ) {
			return new WP_Error( 'mmi_po_supplier_name', __( 'Supplier name is required.', 'mmi-po' ) );
		}

		$fields                 = MMI_PO_Address::clean( (array) ( $raw['address_fields'] ?? array() ) );
		$fields['company']      = $data['name'];
		$fields['name']         = $data['contact_name'];
		$fields['phone']        = $data['phone'];
		$data['address_fields'] = wp_json_encode( $fields );
		$data['address']        = MMI_PO_Address::format( $fields, false );
		$data['label_policy']   = isset( self::LABEL_POLICIES[ $raw['label_policy'] ?? '' ] ) ? $raw['label_policy'] : 'any';

		$data['email']      = implode( ', ', MMI_PO_Settings::parse_emails( (string) ( $raw['email'] ?? '' ) ) );
		$data['cc']         = implode( ', ', MMI_PO_Settings::parse_emails( (string) ( $raw['cc'] ?? '' ) ) );
		$data['active']     = empty( $raw['active'] ) ? 0 : 1;
		$data['brand_ids']  = wp_json_encode( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $raw['brand_ids'] ?? array() ) ) ) ) ) );
		$data['updated_at'] = current_time( 'mysql' );

		$table = MMI_PO_Install::table( 'suppliers' );
		if ( $id ) {
			$ok = $wpdb->update( $table, $data, array( 'id' => $id ) );
		} else {
			$data['created_at'] = $data['updated_at'];
			$ok                 = $wpdb->insert( $table, $data );
			$id                 = (int) $wpdb->insert_id;
		}
		self::$cache = null;

		if ( $ok === false ) {
			MMI_Logger::error( 'Supplier save failed', array( 'supplier_id' => $id, 'db_error' => $wpdb->last_error ), 'database', 'MMI_PO_Suppliers' );
			return new WP_Error( 'mmi_po_supplier_db', __( 'The supplier could not be saved.', 'mmi-po' ) );
		}
		return $id;
	}

	/** Brand names for display, one term query for every supplier's brands together. */
	public static function brand_names( array $term_ids ): array {
		if ( ! $term_ids ) {
			return array();
		}
		$names = array();
		$terms = get_terms( array( 'taxonomy' => 'product_brand', 'include' => $term_ids, 'hide_empty' => false ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$names[ (int) $term->term_id ] = $term->name;
			}
		}
		return $names;
	}

	/** Every product brand, for the supplier editor's picker. */
	public static function all_brands(): array {
		$terms = get_terms( array( 'taxonomy' => 'product_brand', 'hide_empty' => false, 'orderby' => 'name' ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_map( static function ( $t ) {
			return array( 'id' => (int) $t->term_id, 'name' => $t->name, 'count' => (int) $t->count );
		}, $terms );
	}

	private static function shape( array $row ): array {
		$brands = json_decode( (string) $row['brand_ids'], true );
		$fields = json_decode( (string) $row['address_fields'], true );
		$fields = MMI_PO_Address::clean( is_array( $fields ) ? $fields : MMI_PO_Address::parse( (string) $row['address'] ) );
		// Label ship-from identity always follows the directory entry.
		$fields['company'] = $row['name'];
		$fields['name']    = $row['contact_name'];
		$fields['phone']   = $row['phone'];
		return array(
			'id'             => (int) $row['id'],
			'name'           => $row['name'],
			'contact_name'   => $row['contact_name'],
			'email'          => $row['email'],
			'cc'             => $row['cc'],
			'phone'          => $row['phone'],
			'address'        => MMI_PO_Address::is_empty( $fields ) ? (string) $row['address'] : MMI_PO_Address::format( $fields, false ),
			'address_fields' => $fields,
			'label_policy'   => isset( self::LABEL_POLICIES[ $row['label_policy'] ] ) ? $row['label_policy'] : 'any',
			'account_number' => $row['account_number'],
			'instructions'   => (string) $row['instructions'],
			'brand_ids'      => is_array( $brands ) ? array_map( 'intval', $brands ) : array(),
			'active'         => (bool) $row['active'],
		);
	}
}
