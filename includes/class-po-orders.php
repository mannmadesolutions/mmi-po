<?php
/**
 * Purchase orders and their line items. Totals are always recomputed here from the lines,
 * never trusted from the browser.
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_PO_Orders {

	const STATUSES = array(
		'draft'     => 'Draft',
		'sent'      => 'Sent',
		'received'  => 'Received',
		'cancelled' => 'Cancelled',
	);

	/** Badge color per status, matching .mmi-badge modifiers. */
	const STATUS_BADGES = array(
		'draft'     => 'warning',
		'sent'      => 'info',
		'received'  => 'success',
		'cancelled' => 'error',
	);

	const MAX_LINES          = 500;
	const RECENT_WC_ORDERS   = 25;
	const MAX_NUMBER_RETRIES = 20;

	public static function get( int $id ): ?array {
		global $wpdb;
		$order = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MMI_PO_Install::table( 'orders' ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		if ( ! $order ) {
			return null;
		}
		$order['items'] = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . MMI_PO_Install::table( 'items' ) . ' WHERE po_id = %d ORDER BY sort_order ASC, id ASC', $id ),
			ARRAY_A
		);
		$order['supplier_snapshot'] = $order['supplier_snapshot'] ? json_decode( $order['supplier_snapshot'], true ) : null;
		$order['ship_to_fields']    = $order['ship_to_fields'] ? json_decode( $order['ship_to_fields'], true ) : null;
		$order['package']           = $order['package'] ? json_decode( $order['package'], true ) : null;
		return $order;
	}

	/**
	 * Latest WooCommerce orders, for numbering a PO after the sale it fulfils.
	 * Each row carries the PO number already using that order number, if any.
	 */
	public static function recent_wc_orders(): array {
		global $wpdb;
		$orders = wc_get_orders( array(
			'limit'   => self::RECENT_WC_ORDERS,
			'orderby' => 'date',
			'order'   => 'DESC',
			'type'    => 'shop_order',
			'status'  => array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) ),
		) );
		$prefix = (string) MMI_PO_Settings::get( 'number_prefix' );
		$rows   = array();
		foreach ( $orders as $o ) {
			$name   = trim( $o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name() );
			$rows[] = array(
				'id'     => $o->get_id(),
				'number' => (string) $o->get_order_number(),
				'date'   => $o->get_date_created() ? $o->get_date_created()->date_i18n( 'M j' ) : '',
				'name'   => $name !== '' ? $name : trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
				'total'  => html_entity_decode( wp_strip_all_tags( wc_price( (float) $o->get_total(), array( 'currency' => $o->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
				'status' => wc_get_order_status_name( $o->get_status() ),
				'items'  => (int) $o->get_item_count(),
			);
		}
		// One query for which of these numbers already have POs (prefix + number, or with a -N suffix).
		if ( $rows ) {
			$likes = array();
			foreach ( $rows as $r ) {
				$likes[] = $wpdb->prepare( 'po_number = %s OR po_number LIKE %s', $prefix . $r['number'], $wpdb->esc_like( $prefix . $r['number'] . '-' ) . '%' );
			}
			$used = array_flip( $wpdb->get_col( 'SELECT po_number FROM ' . MMI_PO_Install::table( 'orders' ) . ' WHERE ' . implode( ' OR ', $likes ) ) );
			foreach ( $rows as &$r ) {
				$base         = $prefix . $r['number'];
				$r['po_uses'] = 0;
				$r['po_next'] = $base;
				for ( $n = 1; isset( $used[ $n === 1 ? $base : $base . '-' . $n ] ); $n++ ) {
					$r['po_uses']++;
					$r['po_next'] = $base . '-' . ( $n + 1 );
				}
			}
		}
		return $rows;
	}

	/** First free PO number at $base, $base-2, $base-3 … */
	public static function free_number( string $base ): string {
		global $wpdb;
		$table = MMI_PO_Install::table( 'orders' );
		$used  = array_flip( $wpdb->get_col( $wpdb->prepare( "SELECT po_number FROM {$table} WHERE po_number = %s OR po_number LIKE %s", $base, $wpdb->esc_like( $base . '-' ) . '%' ) ) );
		if ( ! isset( $used[ $base ] ) ) {
			return $base;
		}
		$n = 2;
		while ( isset( $used[ $base . '-' . $n ] ) ) {
			$n++;
		}
		return $base . '-' . $n;
	}

	/**
	 * The supplier as printed on this PO: the snapshot taken when it was sent, so a later edit to
	 * the supplier directory never rewrites what a supplier already received.
	 */
	public static function supplier_for( array $order ): array {
		if ( ! empty( $order['supplier_snapshot'] ) && is_array( $order['supplier_snapshot'] ) ) {
			return $order['supplier_snapshot'];
		}
		$supplier = MMI_PO_Suppliers::get( (int) $order['supplier_id'] );
		return $supplier ? $supplier : array( 'name' => '', 'address' => '', 'email' => '', 'cc' => '', 'contact_name' => '', 'phone' => '', 'account_number' => '' );
	}

	/**
	 * @return array{rows: array, total: int, counts: array}
	 */
	public static function query( array $args ): array {
		global $wpdb;
		$orders = MMI_PO_Install::table( 'orders' );
		$sups   = MMI_PO_Install::table( 'suppliers' );

		$where = array( '1=1' );
		if ( ! empty( $args['status'] ) && isset( self::STATUSES[ $args['status'] ] ) ) {
			$where[] = $wpdb->prepare( 'o.status = %s', $args['status'] );
		}
		if ( ! empty( $args['supplier_id'] ) ) {
			$where[] = $wpdb->prepare( 'o.supplier_id = %d', $args['supplier_id'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare(
				'(o.po_number LIKE %s OR s.name LIKE %s OR o.id IN (SELECT i.po_id FROM ' . MMI_PO_Install::table( 'items' ) . ' i WHERE i.item_number LIKE %s OR i.sku LIKE %s OR i.description LIKE %s))',
				$like,
				$like,
				$like,
				$like,
				$like
			);
		}
		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, (int) ( $args['per_page'] ?? 50 ) );
		$offset    = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.id, o.po_number, o.status, o.po_date, o.total, o.item_count, o.sent_at, o.sent_to, o.updated_at, s.name AS supplier_name
				 FROM {$orders} o LEFT JOIN {$sups} s ON s.id = o.supplier_id
				 WHERE {$where_sql} ORDER BY o.id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders} o LEFT JOIN {$sups} s ON s.id = o.supplier_id WHERE {$where_sql}" );
		$counts = array_fill_keys( array_keys( self::STATUSES ), 0 );
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$orders} GROUP BY status", ARRAY_A ) as $c ) {
			$counts[ $c['status'] ] = (int) $c['n'];
		}

		return array( 'rows' => $rows, 'total' => $total, 'counts' => $counts );
	}

	/**
	 * Creates or updates a PO from an editor payload.
	 *
	 * @return int|WP_Error PO id.
	 */
	public static function save( array $payload, int $id = 0 ) {
		global $wpdb;

		$existing = $id ? self::get( $id ) : null;
		if ( $id && ! $existing ) {
			return new WP_Error( 'mmi_po_missing', __( 'That purchase order no longer exists.', 'mmi-po' ) );
		}

		$supplier_id = absint( $payload['supplier_id'] ?? 0 );
		if ( $supplier_id && ! MMI_PO_Suppliers::get( $supplier_id ) ) {
			return new WP_Error( 'mmi_po_supplier', __( 'Choose a supplier from the list.', 'mmi-po' ) );
		}

		$items = self::clean_items( (array) ( $payload['items'] ?? array() ) );
		if ( count( $items ) > self::MAX_LINES ) {
			return new WP_Error( 'mmi_po_lines', sprintf( /* translators: %d: limit */ __( 'A purchase order can have at most %d lines.', 'mmi-po' ), self::MAX_LINES ) );
		}

		$subtotal = 0.0;
		foreach ( $items as $item ) {
			$subtotal += $item['line_total'];
		}
		$tax      = round( max( 0, (float) ( $payload['tax'] ?? 0 ) ), 2 );
		$shipping = round( max( 0, (float) ( $payload['shipping'] ?? 0 ) ), 2 );
		$po_date  = sanitize_text_field( $payload['po_date'] ?? '' );

		$ship_to_fields = MMI_PO_Address::clean( (array) ( $payload['ship_to_fields'] ?? array() ) );
		$supplier       = $supplier_id ? MMI_PO_Suppliers::get( $supplier_id ) : null;
		$label_mode     = ( $payload['label_mode'] ?? '' ) === 'ours' && ( ! $supplier || $supplier['label_policy'] !== 'supplier_only' ) ? 'ours' : 'supplier';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $po_date ) ) {
			$po_date = current_time( 'Y-m-d' );
		}

		$data = array(
			'supplier_id'  => $supplier_id,
			'po_date'      => $po_date,
			'ship_to'        => MMI_PO_Address::format( $ship_to_fields ),
			'ship_to_fields' => wp_json_encode( $ship_to_fields ),
			'wc_order_id'    => absint( $payload['wc_order_id'] ?? 0 ),
			'label_mode'     => $label_mode,
			'package'        => wp_json_encode( MMI_PO_Shipping::clean_package( $payload['package'] ?? array() ) ),
			'instructions' => sanitize_textarea_field( (string) ( $payload['instructions'] ?? '' ) ),
			'notes'        => sanitize_textarea_field( (string) ( $payload['notes'] ?? '' ) ),
			'subtotal'     => round( $subtotal, 2 ),
			'tax'          => $tax,
			'shipping'     => $shipping,
			'total'        => round( $subtotal + $tax + $shipping, 2 ),
			'item_count'   => count( $items ),
			'updated_at'   => current_time( 'mysql' ),
		);

		$orders    = MMI_PO_Install::table( 'orders' );
		$po_number = mb_substr( preg_replace( '/[^A-Za-z0-9\-_.\/#]/', '', (string) ( $payload['po_number'] ?? '' ) ), 0, 64 );
		if ( $po_number !== '' && ( ! $existing || $po_number !== $existing['po_number'] ) ) {
			if ( $existing && $existing['sent_at'] ) {
				return new WP_Error( 'mmi_po_number_locked', __( 'This PO has already been emailed, so its number can no longer change.', 'mmi-po' ) );
			}
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$orders} WHERE po_number = %s", $po_number ) ) ) {
				return new WP_Error( 'mmi_po_number_taken', sprintf( /* translators: 1: number, 2: suggestion */ __( '%1$s is already used by another PO. Try %2$s.', 'mmi-po' ), $po_number, self::free_number( $po_number ) ) );
			}
			$data['po_number'] = $po_number;
		}
		if ( $existing ) {
			if ( $wpdb->update( $orders, $data, array( 'id' => $id ) ) === false ) {
				return self::db_error( 'update', $id );
			}
		} else {
			$data['status']     = 'draft';
			$data['created_by'] = get_current_user_id();
			$data['created_at'] = $data['updated_at'];
			$id                 = isset( $data['po_number'] ) ? self::insert_fixed( $data ) : self::insert_with_number( $data );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
		}

		if ( ! self::replace_items( $id, $items ) ) {
			return self::db_error( 'items', $id );
		}

		$order = self::get( $id );
		if ( $existing ) {
			$message = sprintf( '%d lines, total %s', $data['item_count'], self::money( $data['total'] ) );
			if ( isset( $data['po_number'] ) ) {
				$message = sprintf( 'Renumbered %s → %s; ', $existing['po_number'], $data['po_number'] ) . $message;
			}
			if ( $existing['status'] !== 'draft' ) {
				$message .= sprintf( ' (edited while %s)', strtolower( self::STATUSES[ $existing['status'] ] ?? $existing['status'] ) );
			}
			MMI_PO_Log::add( $id, 'updated', $message );
		} else {
			MMI_PO_Log::add( $id, 'created', sprintf( '%s created with %d lines, total %s', $order['po_number'], $data['item_count'], self::money( $data['total'] ) ) );
		}
		return $id;
	}

	public static function set_status( int $id, string $status ) {
		global $wpdb;
		$order = self::get( $id );
		if ( ! $order ) {
			return new WP_Error( 'mmi_po_missing', __( 'That purchase order no longer exists.', 'mmi-po' ) );
		}
		if ( ! isset( self::STATUSES[ $status ] ) ) {
			return new WP_Error( 'mmi_po_status', __( 'Unknown status.', 'mmi-po' ) );
		}
		if ( $order['status'] === $status ) {
			return true;
		}
		$wpdb->update( MMI_PO_Install::table( 'orders' ), array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		MMI_PO_Log::add( $id, 'status_changed', sprintf( '%s → %s', self::STATUSES[ $order['status'] ] ?? $order['status'], self::STATUSES[ $status ] ), array( 'from' => $order['status'], 'to' => $status ) );
		return true;
	}

	/** Records a successful email: freezes the supplier as sent, stamps recipients, moves draft → sent. */
	public static function mark_sent( int $id, array $recipients ): void {
		global $wpdb;
		$order    = self::get( $id );
		$supplier = MMI_PO_Suppliers::get( (int) $order['supplier_id'] );
		$data     = array(
			'sent_at'           => current_time( 'mysql' ),
			'sent_to'           => implode( ', ', $recipients ),
			'supplier_snapshot' => $supplier ? wp_json_encode( $supplier ) : null,
			'updated_at'        => current_time( 'mysql' ),
		);
		if ( $order['status'] === 'draft' ) {
			$data['status'] = 'sent';
		}
		$wpdb->update( MMI_PO_Install::table( 'orders' ), $data, array( 'id' => $id ) );
	}

	/** @return int|WP_Error New draft id. */
	public static function duplicate( int $id ) {
		$order = self::get( $id );
		if ( ! $order ) {
			return new WP_Error( 'mmi_po_missing', __( 'That purchase order no longer exists.', 'mmi-po' ) );
		}
		$new_id = self::save(
			array(
				'supplier_id'  => $order['supplier_id'],
				'po_date'      => current_time( 'Y-m-d' ),
				'ship_to_fields' => $order['ship_to_fields'],
				'label_mode'     => $order['label_mode'],
				'wc_order_id'    => $order['wc_order_id'],
				'instructions' => $order['instructions'],
				'tax'          => 0,
				'shipping'     => 0,
				'items'        => $order['items'],
			)
		);
		if ( ! is_wp_error( $new_id ) ) {
			MMI_PO_Log::add( $new_id, 'duplicated', sprintf( 'Copied from %s', $order['po_number'] ), array( 'source_po' => $order['po_number'] ) );
		}
		return $new_id;
	}

	/** Drafts only: anything that reached a supplier stays on record (cancel it instead). */
	public static function delete( int $id ) {
		global $wpdb;
		$order = self::get( $id );
		if ( ! $order ) {
			return new WP_Error( 'mmi_po_missing', __( 'That purchase order no longer exists.', 'mmi-po' ) );
		}
		if ( $order['status'] !== 'draft' || $order['sent_at'] ) {
			return new WP_Error( 'mmi_po_locked', __( 'Only unsent drafts can be deleted. Mark a sent PO as cancelled instead.', 'mmi-po' ) );
		}
		$wpdb->delete( MMI_PO_Install::table( 'items' ), array( 'po_id' => $id ) );
		$wpdb->delete( MMI_PO_Install::table( 'orders' ), array( 'id' => $id ) );
		MMI_PO_Log::add( $id, 'deleted', sprintf( 'Draft %s deleted', $order['po_number'] ), array( 'po_number' => $order['po_number'] ) );
		return true;
	}

	public static function money( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/** Normalizes editor lines; drops rows with no item number and no description. */
	private static function clean_items( array $raw ): array {
		$items = array();
		foreach ( array_values( $raw ) as $i => $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$item_number = sanitize_text_field( (string) ( $line['item_number'] ?? '' ) );
			$description = sanitize_textarea_field( (string) ( $line['description'] ?? '' ) );
			if ( $item_number === '' && $description === '' ) {
				continue;
			}
			$qty       = max( 1, (int) ( $line['qty'] ?? 1 ) );
			$unit_cost = round( max( 0, (float) ( $line['unit_cost'] ?? 0 ) ), 4 );
			$items[]   = array(
				'product_id'  => absint( $line['product_id'] ?? 0 ),
				'item_number' => mb_substr( $item_number, 0, 100 ),
				'sku'         => mb_substr( sanitize_text_field( (string) ( $line['sku'] ?? '' ) ), 0, 100 ),
				'description' => $description,
				'qty'         => $qty,
				'unit_cost'   => $unit_cost,
				'line_total'  => round( $qty * $unit_cost, 2 ),
				'sort_order'  => $i,
			);
		}
		return $items;
	}

	private static function replace_items( int $po_id, array $items ): bool {
		global $wpdb;
		$table = MMI_PO_Install::table( 'items' );
		$wpdb->delete( $table, array( 'po_id' => $po_id ) );
		if ( ! $items ) {
			return true;
		}
		$placeholders = array();
		$values       = array();
		foreach ( $items as $item ) {
			$placeholders[] = '(%d, %d, %s, %s, %s, %d, %f, %f, %d)';
			array_push( $values, $po_id, $item['product_id'], $item['item_number'], $item['sku'], $item['description'], $item['qty'], $item['unit_cost'], $item['line_total'], $item['sort_order'] );
		}
		$sql = "INSERT INTO {$table} (po_id, product_id, item_number, sku, description, qty, unit_cost, line_total, sort_order) VALUES " . implode( ', ', $placeholders );
		return $wpdb->query( $wpdb->prepare( $sql, $values ) ) !== false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
	}

	/** A chosen number (e.g. the WooCommerce order number). The global counter is left alone. */
	private static function insert_fixed( array $data ) {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$ok = $wpdb->insert( MMI_PO_Install::table( 'orders' ), $data );
		$wpdb->suppress_errors( false );
		if ( ! $ok ) {
			return stripos( (string) $wpdb->last_error, 'Duplicate' ) !== false
				? new WP_Error( 'mmi_po_number_taken', sprintf( /* translators: 1: number, 2: suggestion */ __( '%1$s is already used by another PO. Try %2$s.', 'mmi-po' ), $data['po_number'], self::free_number( $data['po_number'] ) ) )
				: self::db_error( 'insert', 0 );
		}
		return (int) $wpdb->insert_id;
	}

	/** The UNIQUE key on po_number settles any race: on a collision take the next number. */
	private static function insert_with_number( array $data ) {
		global $wpdb;
		$prefix = (string) MMI_PO_Settings::get( 'number_prefix' );
		$number = MMI_PO_Settings::next_number();
		$table  = MMI_PO_Install::table( 'orders' );

		for ( $attempt = 0; $attempt < self::MAX_NUMBER_RETRIES; $attempt++, $number++ ) {
			$data['po_number'] = $prefix . $number;
			$wpdb->suppress_errors( true );
			$ok = $wpdb->insert( $table, $data );
			$wpdb->suppress_errors( false );
			if ( $ok ) {
				$id = (int) $wpdb->insert_id; // Read before advance_number(): its settings write replaces insert_id.
				MMI_PO_Settings::advance_number( $number );
				return $id;
			}
			if ( stripos( (string) $wpdb->last_error, 'Duplicate' ) === false ) {
				return self::db_error( 'insert', 0 );
			}
		}
		return new WP_Error( 'mmi_po_number', __( 'Could not reserve a PO number. Check the next-number setting.', 'mmi-po' ) );
	}

	private static function db_error( string $op, int $id ): WP_Error {
		global $wpdb;
		MMI_Logger::error( 'Purchase order write failed', array( 'op' => $op, 'po_id' => $id, 'db_error' => $wpdb->last_error ), 'database', 'MMI_PO_Orders' );
		return new WP_Error( 'mmi_po_db', __( 'The purchase order could not be saved.', 'mmi-po' ) );
	}
}
