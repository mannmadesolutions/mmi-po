<?php
/**
 * Schema and first-run seed data.
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_PO_Install {

	const DB_VERSION     = '1.1.0';
	const DB_VERSION_KEY = 'mmi_po_db_version';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'mmi_po_' . $name;
	}

	public static function maybe_upgrade(): void {
		if ( MMI_Settings::get( self::DB_VERSION_KEY, '' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta( 'CREATE TABLE ' . self::table( 'suppliers' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			contact_name varchar(191) NOT NULL DEFAULT '',
			email varchar(255) NOT NULL DEFAULT '',
			cc varchar(255) NOT NULL DEFAULT '',
			phone varchar(64) NOT NULL DEFAULT '',
			address text NULL,
			account_number varchar(100) NOT NULL DEFAULT '',
			instructions text NULL,
			brand_ids text NULL,
			address_fields longtext NULL,
			label_policy varchar(20) NOT NULL DEFAULT 'any',
			active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY active (active)
		) $charset;" );

		dbDelta( 'CREATE TABLE ' . self::table( 'orders' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			po_number varchar(64) NOT NULL,
			supplier_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'draft',
			po_date date NOT NULL,
			ship_to text NULL,
			ship_to_fields longtext NULL,
			wc_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			label_mode varchar(20) NOT NULL DEFAULT 'supplier',
			package longtext NULL,
			instructions text NULL,
			notes text NULL,
			subtotal decimal(12,2) NOT NULL DEFAULT 0,
			tax decimal(12,2) NOT NULL DEFAULT 0,
			shipping decimal(12,2) NOT NULL DEFAULT 0,
			total decimal(12,2) NOT NULL DEFAULT 0,
			item_count int(11) NOT NULL DEFAULT 0,
			supplier_snapshot longtext NULL,
			sent_at datetime NULL,
			sent_to text NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY po_number (po_number),
			KEY supplier_status (supplier_id,status),
			KEY status_date (status,po_date),
			KEY wc_order_id (wc_order_id)
		) $charset;" );

		dbDelta( 'CREATE TABLE ' . self::table( 'shipments' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			po_id bigint(20) unsigned NOT NULL,
			provider varchar(20) NOT NULL DEFAULT 'shipstation',
			external_id varchar(64) NOT NULL DEFAULT '',
			carrier_code varchar(50) NOT NULL DEFAULT '',
			service_code varchar(100) NOT NULL DEFAULT '',
			service_name varchar(191) NOT NULL DEFAULT '',
			tracking_number varchar(100) NOT NULL DEFAULT '',
			cost decimal(12,2) NOT NULL DEFAULT 0,
			package longtext NULL,
			ship_from longtext NULL,
			ship_to longtext NULL,
			label_file varchar(191) NOT NULL DEFAULT '',
			is_test tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			voided_at datetime NULL,
			PRIMARY KEY  (id),
			KEY po_status (po_id,status)
		) $charset;" );

		dbDelta( 'CREATE TABLE ' . self::table( 'items' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			po_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			item_number varchar(100) NOT NULL DEFAULT '',
			sku varchar(100) NOT NULL DEFAULT '',
			description text NULL,
			qty int(11) NOT NULL DEFAULT 1,
			unit_cost decimal(12,4) NOT NULL DEFAULT 0,
			line_total decimal(12,2) NOT NULL DEFAULT 0,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY po_sort (po_id,sort_order),
			KEY product_id (product_id)
		) $charset;" );

		dbDelta( 'CREATE TABLE ' . self::table( 'log' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			po_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(40) NOT NULL DEFAULT '',
			message text NULL,
			meta longtext NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY po_id (po_id),
			KEY created_at (created_at)
		) $charset;" );

		// dbDelta reports nothing on failure; only stamp the version once every table really exists.
		foreach ( array( 'suppliers', 'orders', 'items', 'log', 'shipments' ) as $name ) {
			$table = self::table( $name );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				MMI_Logger::error( 'Purchase order table missing after install', array( 'table' => $table, 'db_error' => $wpdb->last_error ), 'database', 'MMI_PO_Install' );
				return;
			}
		}

		self::seed_suppliers();
		self::migrate_supplier_addresses();
		MMI_Settings::set( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/** 1.1.0: suppliers created with a free-text address get structured ship-from fields. */
	private static function migrate_supplier_addresses(): void {
		global $wpdb;
		require_once MMI_PO_PATH . 'includes/class-po-address.php';
		$table = self::table( 'suppliers' );
		foreach ( $wpdb->get_results( "SELECT id, name, phone, address FROM {$table} WHERE address_fields IS NULL", ARRAY_A ) as $row ) {
			$fields            = MMI_PO_Address::parse( (string) $row['address'] );
			$fields['company'] = $row['name'];
			$fields['phone']   = $row['phone'];
			$data              = array( 'address_fields' => wp_json_encode( $fields ) );
			// The Music People (and KMC, below) refuse drop-shipper labels.
			if ( $row['name'] === 'The Music People' ) {
				$data['label_policy'] = 'supplier_only';
			}
			$wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) );
		}
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s", 'KMC Music' ) ) ) {
			$now = current_time( 'mysql' );
			$wpdb->insert( $table, array(
				'name'           => 'KMC Music',
				'brand_ids'      => '[]',
				'address_fields' => wp_json_encode( MMI_PO_Address::clean( array( 'company' => 'KMC Music' ) ) ),
				'label_policy'   => 'supplier_only',
				'active'         => 1,
				'created_at'     => $now,
				'updated_at'     => $now,
			) );
		}
	}

	/**
	 * First install only: the suppliers named when the plugin was commissioned, with whatever is
	 * already known (the Marshall address from the old Numbers template, brand links from the
	 * catalog). Contact emails were not known and must be filled in before a PO can be emailed.
	 */
	private static function seed_suppliers(): void {
		global $wpdb;
		$table = self::table( 'suppliers' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
			return;
		}

		$seed = array(
			array(
				'name'         => 'Marshall Electronics',
				'address'      => "20608 Madrona Ave\nTorrance, CA 90503",
				'instructions' => 'SHIP COMPLETE',
				'brands'       => array( 'Mogami' ),
			),
			array(
				'name'         => 'Full Scale AV',
				'address'      => '',
				'instructions' => '',
				'brands'       => array( 'Prism Sound' ),
			),
			array(
				'name'         => 'The Music People',
				'address'      => '',
				'instructions' => '',
				'brands'       => array(),
				'label_policy' => 'supplier_only',
			),
		);

		$now = current_time( 'mysql' );
		foreach ( $seed as $supplier ) {
			$brand_ids = array();
			foreach ( $supplier['brands'] as $brand_name ) {
				$term = get_term_by( 'name', $brand_name, 'product_brand' );
				if ( $term ) {
					$brand_ids[] = (int) $term->term_id;
				}
			}
			$wpdb->insert(
				$table,
				array(
					'name'         => $supplier['name'],
					'address'      => $supplier['address'],
					'instructions' => $supplier['instructions'],
					'brand_ids'    => wp_json_encode( $brand_ids ),
					'label_policy' => $supplier['label_policy'] ?? 'any',
					'active'       => 1,
					'created_at'   => $now,
					'updated_at'   => $now,
				)
			);
		}
	}
}
