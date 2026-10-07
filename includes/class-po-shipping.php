<?php
/**
 * Shipping labels for POs: inbound prepaid (supplier → us) and drop-ship (supplier → customer).
 * Ship-from is always the supplier; ship-to is the PO's structured Deliver To. Labels are bought
 * through MMI_PO_ShipStation and kept in wp_mmi_po_shipments with the label PDF archived privately.
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_PO_Shipping {

	const CONFIRMATIONS = array(
		'none'             => 'No confirmation',
		'delivery'         => 'Delivery confirmation',
		'signature'        => 'Signature',
		'adult_signature'  => 'Adult signature',
		'direct_signature' => 'Direct signature',
	);

	const OZ_PER_LB = 16;

	/** ShipStation's generic package code: what createlabel buys, so what the price check quotes. */
	const LABEL_PACKAGE = 'package';

	/** Rounding slack (in currency) when comparing ShipStation's live price with the confirmed one. */
	const PRICE_TOLERANCE = 0.01;

	/**
	 * A shipments row is written as 'pending' before ShipStation is called. It becomes 'active' on
	 * success and is deleted on a definite failure; a timeout or 5xx leaves it 'pending', which
	 * blocks another purchase until the user checks ShipStation and resolves it.
	 */
	const STATUS_PENDING = 'pending';

	/** Normalizes the editor's package block. Weight is stored in ounces. */
	public static function clean_package( $raw ): array {
		$raw  = is_array( $raw ) ? $raw : array();
		$date = sanitize_text_field( (string) ( $raw['ship_date'] ?? '' ) );
		$today = current_time( 'Y-m-d' );
		return array(
			'weight_oz'    => round( max( 0, (float) ( $raw['weight_lb'] ?? 0 ) ) * self::OZ_PER_LB + max( 0, (float) ( $raw['weight_oz_part'] ?? 0 ) ), 2 ),
			'length'       => round( max( 0, (float) ( $raw['length'] ?? 0 ) ), 1 ),
			'width'        => round( max( 0, (float) ( $raw['width'] ?? 0 ) ), 1 ),
			'height'       => round( max( 0, (float) ( $raw['height'] ?? 0 ) ), 1 ),
			'confirmation' => isset( self::CONFIRMATIONS[ $raw['confirmation'] ?? '' ] ) ? $raw['confirmation'] : 'none',
			'ship_date'    => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && $date >= $today ? $date : $today,
		);
	}

	/** Package for the editor: the PO's own, else the last one used for this supplier, else catalog weights. */
	public static function package_for_editor( array $order ): array {
		$package = is_array( $order['package'] ?? null ) ? $order['package'] : null;
		if ( ! $package && ! empty( $order['supplier_id'] ) ) {
			$package = self::last_package_for_supplier( (int) $order['supplier_id'] );
		}
		if ( ! $package ) {
			$package = self::clean_package( array() );
			$package['weight_oz'] = self::catalog_weight_oz( $order['items'] ?? array() );
		}
		return $package;
	}

	/** Why a label cannot be bought for this PO right now ('' when it can). */
	public static function label_blocker( array $order ): string {
		$supplier = MMI_PO_Suppliers::get( (int) $order['supplier_id'] );
		if ( ! $supplier ) {
			return __( 'Choose a supplier first.', 'mmi-po' );
		}
		if ( $supplier['label_policy'] === 'supplier_only' ) {
			return sprintf( /* translators: %s: supplier */ __( '%s ships on their own labels only.', 'mmi-po' ), $supplier['name'] );
		}
		if ( ( $order['label_mode'] ?? 'supplier' ) !== 'ours' ) {
			return __( 'This PO is set to "Supplier ships on their own label".', 'mmi-po' );
		}
		$missing = MMI_PO_Address::missing_for_label( $supplier['address_fields'] );
		if ( $missing ) {
			return sprintf( /* translators: 1: supplier, 2: fields */ __( 'The ship-from address for %1$s is missing: %2$s (edit it in Suppliers).', 'mmi-po' ), $supplier['name'], implode( ', ', $missing ) );
		}
		$missing = MMI_PO_Address::missing_for_label( self::ship_to( $order ) );
		if ( $missing ) {
			return sprintf( /* translators: %s: fields */ __( 'The Deliver To address is missing: %s.', 'mmi-po' ), implode( ', ', $missing ) );
		}
		if ( (float) ( $order['package']['weight_oz'] ?? 0 ) <= 0 ) {
			return __( 'Enter the package weight.', 'mmi-po' );
		}
		if ( ! MMI_PO_ShipStation::is_configured() ) {
			return __( 'Connect ShipStation in Settings first.', 'mmi-po' );
		}
		$pending = self::pending_label( (int) $order['id'] );
		if ( $pending ) {
			return sprintf(
				/* translators: %s: date and time */
				__( 'A label purchase started %s is still running or got no clear answer from ShipStation. Check ShipStation → Shipments for this PO and void any label there, then click "Resolved" under Label history.', 'mmi-po' ),
				$pending['created_at']
			);
		}
		return '';
	}

	/** The saved package with any stale ship date moved up to today. */
	public static function effective_package( array $order ): array {
		$package = is_array( $order['package'] ?? null ) ? $order['package'] : array();
		$package = array_merge( array( 'weight_oz' => 0, 'length' => 0, 'width' => 0, 'height' => 0, 'confirmation' => 'none', 'ship_date' => '' ), $package );
		$package['ship_date'] = max( (string) $package['ship_date'], current_time( 'Y-m-d' ) );
		return $package;
	}

	public static function ship_from( array $order ): array {
		$supplier = MMI_PO_Suppliers::get( (int) $order['supplier_id'] );
		return $supplier ? $supplier['address_fields'] : MMI_PO_Address::clean( array() );
	}

	public static function ship_to( array $order ): array {
		return is_array( $order['ship_to_fields'] ?? null ) ? MMI_PO_Address::clean( $order['ship_to_fields'] ) : MMI_PO_Address::clean( array() );
	}

	/** The Rate Browser's starting values: this PO's addresses and package. */
	public static function default_quote( array $order ): array {
		$from    = self::ship_from( $order );
		$to      = self::ship_to( $order );
		$package = self::effective_package( $order );
		return array(
			'from_postal'  => $from['postal_code'],
			'from_city'    => $from['city'],
			'from_state'   => $from['state'],
			'to_country'   => $to['country'],
			'to_postal'    => $to['postal_code'],
			'to_city'      => $to['city'],
			'to_state'     => $to['state'],
			'residential'  => (bool) $to['residential'],
			'weight_oz'    => (float) $package['weight_oz'],
			'length'       => (float) $package['length'],
			'width'        => (float) $package['width'],
			'height'       => (float) $package['height'],
			'confirmation' => $package['confirmation'],
			'package_code' => '',
		);
	}

	/** Sanitizes a quote coming back from the browser. */
	public static function clean_quote( array $raw ): array {
		$text = static function ( $k ) use ( $raw ) {
			return sanitize_text_field( (string) ( $raw[ $k ] ?? '' ) );
		};
		return array(
			'from_postal'  => $text( 'from_postal' ),
			'from_city'    => $text( 'from_city' ),
			'from_state'   => strtoupper( $text( 'from_state' ) ),
			'to_country'   => strtoupper( $text( 'to_country' ) ?: 'US' ),
			'to_postal'    => $text( 'to_postal' ),
			'to_city'      => $text( 'to_city' ),
			'to_state'     => strtoupper( $text( 'to_state' ) ),
			'residential'  => ! empty( $raw['residential'] ) && $raw['residential'] !== 'false',
			'weight_oz'    => max( 0, (float) ( $raw['weight_oz'] ?? 0 ) ),
			'length'       => max( 0, (float) ( $raw['length'] ?? 0 ) ),
			'width'        => max( 0, (float) ( $raw['width'] ?? 0 ) ),
			'height'       => max( 0, (float) ( $raw['height'] ?? 0 ) ),
			'confirmation' => isset( self::CONFIRMATIONS[ $raw['confirmation'] ?? '' ] ) ? $raw['confirmation'] : 'none',
			'package_code' => sanitize_key( (string) ( $raw['package_code'] ?? '' ) ),
		);
	}

	/**
	 * Buys a label for a saved PO. A database lock plus a 'pending' row mean a double click, a
	 * second tab or a retry after a timeout can never buy postage twice, and the purchase is
	 * refused if ShipStation now quotes more than the price the user confirmed.
	 *
	 * @return array|WP_Error Shipment row.
	 */
	public static function buy_label( int $po_id, string $carrier_code, string $service_code, string $service_name, float $expected_cost ) {
		global $wpdb;
		$order = MMI_PO_Orders::get( $po_id );
		if ( ! $order ) {
			return new WP_Error( 'mmi_po_missing', __( 'That purchase order no longer exists.', 'mmi-po' ) );
		}
		$blocker = self::label_blocker( $order );
		if ( $blocker !== '' ) {
			return new WP_Error( 'mmi_po_label_blocked', $blocker );
		}
		if ( $carrier_code === '' || $service_code === '' || $expected_cost <= 0 ) {
			return new WP_Error( 'mmi_po_label_rate', __( 'Choose a rate in the Rate Browser first.', 'mmi-po' ) );
		}

		$lock = $wpdb->prefix . 'mmi_po_label_' . $po_id;
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) !== 1 ) {
			return new WP_Error( 'mmi_po_label_busy', __( 'A label purchase for this PO is already in progress.', 'mmi-po' ) );
		}
		try {
			if ( self::active_label( $po_id ) ) {
				return new WP_Error( 'mmi_po_label_exists', __( 'This PO already has an active label. Void it before buying another.', 'mmi-po' ) );
			}
			if ( self::pending_label( $po_id ) ) {
				return new WP_Error( 'mmi_po_label_pending', self::label_blocker( $order ) );
			}

			$from    = self::ship_from( $order );
			$to      = self::ship_to( $order );
			$package = self::effective_package( $order );

			$price = self::live_price( $order, $carrier_code, $service_code );
			if ( is_wp_error( $price ) ) {
				return $price;
			}
			if ( $price > $expected_cost + self::PRICE_TOLERANCE ) {
				MMI_PO_Log::add( $po_id, 'label_failed', sprintf( 'Price changed from %s to %s; not bought', MMI_PO_Orders::money( $expected_cost ), MMI_PO_Orders::money( $price ) ), array( 'carrier' => $carrier_code, 'service' => $service_code ) );
				return new WP_Error(
					'mmi_po_label_price',
					sprintf(
						/* translators: 1: confirmed price, 2: current price */
						__( 'Nothing was bought: ShipStation now quotes %2$s for this label, not the %1$s you confirmed. Browse rates again and confirm the new price.', 'mmi-po' ),
						MMI_PO_Orders::money( $expected_cost ),
						MMI_PO_Orders::money( $price )
					)
				);
			}

			$table = MMI_PO_Install::table( 'shipments' );
			$row   = array(
				'po_id'        => $po_id,
				'provider'     => 'shipstation',
				'carrier_code' => $carrier_code,
				'service_code' => $service_code,
				'service_name' => $service_name,
				'cost'         => round( $price, 2 ),
				'package'      => wp_json_encode( $package ),
				'ship_from'    => wp_json_encode( $from ),
				'ship_to'      => wp_json_encode( $to ),
				'is_test'      => 0,
				'status'       => self::STATUS_PENDING,
				'created_by'   => get_current_user_id(),
				'created_at'   => current_time( 'mysql' ),
			);
			if ( ! $wpdb->insert( $table, $row ) ) {
				return new WP_Error( 'mmi_po_label_db', __( 'Could not record the purchase, so nothing was bought.', 'mmi-po' ) );
			}
			$row['id'] = (int) $wpdb->insert_id;

			$result = MMI_PO_ShipStation::create_label( $from, $to, $package, $carrier_code, $service_code );
			if ( is_wp_error( $result ) ) {
				$data = $result->get_error_data();
				if ( is_array( $data ) && ! empty( $data['uncertain'] ) ) {
					// ShipStation may have bought the label anyway; keep the pending row so it blocks a re-buy.
					MMI_PO_Log::add( $po_id, 'label_failed', 'No clear answer from ShipStation: ' . $result->get_error_message(), array( 'carrier' => $carrier_code, 'service' => $service_code ) );
					self::audit_label( 'label.buy', $po_id, $row, 'failure', array( 'state' => 'unknown' ) );
					return new WP_Error( 'mmi_po_label_unknown', self::label_blocker( $order ) );
				}
				$wpdb->delete( $table, array( 'id' => $row['id'] ) );
				MMI_PO_Log::add( $po_id, 'label_failed', $result->get_error_message(), array( 'carrier' => $carrier_code, 'service' => $service_code ) );
				self::audit_label( 'label.buy', $po_id, $row, 'failure' );
				return $result;
			}

			$file = MMI_PO_Document::store_file(
				MMI_PO_Document::stored_name( 'label-' . $order['po_number'] ),
				(string) base64_decode( $result['labelData'] )
			);
			if ( is_wp_error( $file ) ) {
				// The label is bought even if we could not save it; record it so it can be voided.
				MMI_Logger::error( 'Label bought but PDF not stored', array( 'po_id' => $po_id, 'shipment' => $result['shipmentId'] ?? '' ), 'integrations', 'MMI_PO_Shipping' );
			}

			$bought = array(
				'external_id'     => (string) ( $result['shipmentId'] ?? '' ),
				'tracking_number' => (string) ( $result['trackingNumber'] ?? '' ),
				'cost'            => round( (float) ( $result['shipmentCost'] ?? 0 ) + (float) ( $result['insuranceCost'] ?? 0 ), 2 ),
				'label_file'      => is_wp_error( $file ) ? '' : basename( $file ),
				'status'          => 'active',
			);
			$wpdb->update( $table, $bought, array( 'id' => $row['id'] ) );
			$row = array_merge( $row, $bought );

			MMI_PO_Log::add(
				$po_id,
				'label_bought',
				sprintf(
					'%s, tracking %s, %s',
					$service_name,
					$row['tracking_number'] !== '' ? $row['tracking_number'] : '—',
					MMI_PO_Orders::money( (float) $row['cost'] )
				),
				array( 'file' => $row['label_file'], 'shipment_id' => $row['external_id'] )
			);
			self::audit_label( 'label.buy', $po_id, $row, 'success' );
			return self::shape( $row );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** @return true|WP_Error */
	public static function void_label( int $shipment_id ) {
		global $wpdb;
		$table = MMI_PO_Install::table( 'shipments' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $shipment_id ), ARRAY_A );
		if ( ! $row || $row['status'] !== 'active' ) {
			return new WP_Error( 'mmi_po_label_missing', __( 'That label is not active.', 'mmi-po' ) );
		}
		$result = MMI_PO_ShipStation::void_label( $row['external_id'] );
		if ( is_wp_error( $result ) ) {
			MMI_PO_Log::add( (int) $row['po_id'], 'label_failed', 'Void failed: ' . $result->get_error_message() );
			self::audit_label( 'label.void', (int) $row['po_id'], $row, 'failure' );
			return $result;
		}
		$wpdb->update( $table, array( 'status' => 'voided', 'voided_at' => current_time( 'mysql' ) ), array( 'id' => $shipment_id ) );
		MMI_PO_Log::add( (int) $row['po_id'], 'label_voided', sprintf( '%s label %s voided', $row['service_name'], $row['tracking_number'] ) );
		self::audit_label( 'label.void', (int) $row['po_id'], $row, 'success' );
		return true;
	}

	/**
	 * Clears a 'pending' purchase after the user has checked ShipStation (and voided any label
	 * there). Kept as 'unresolved' in the history, not deleted.
	 *
	 * @return true|WP_Error
	 */
	public static function resolve_pending( int $shipment_id ) {
		global $wpdb;
		$table = MMI_PO_Install::table( 'shipments' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $shipment_id ), ARRAY_A );
		if ( ! $row || $row['status'] !== self::STATUS_PENDING ) {
			return new WP_Error( 'mmi_po_label_missing', __( 'That purchase is not waiting to be resolved.', 'mmi-po' ) );
		}
		$wpdb->update( $table, array( 'status' => 'unresolved', 'voided_at' => current_time( 'mysql' ) ), array( 'id' => $shipment_id ) );
		MMI_PO_Log::add( (int) $row['po_id'], 'label_voided', sprintf( 'Unclear %s purchase marked resolved after checking ShipStation', $row['service_name'] ) );
		self::audit_label( 'label.resolve', (int) $row['po_id'], $row, 'success' );
		return true;
	}

	public static function pending_label( int $po_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MMI_PO_Install::table( 'shipments' ) . ' WHERE po_id = %d AND status = %s ORDER BY id DESC LIMIT 1', $po_id, self::STATUS_PENDING ),
			ARRAY_A
		);
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * What ShipStation charges right now for this PO's label: the same addresses, package and
	 * package code createlabel will use (rates are free; cached 10 minutes).
	 *
	 * @return float|WP_Error
	 */
	private static function live_price( array $order, string $carrier_code, string $service_code ) {
		$quote                 = self::default_quote( $order );
		$quote['package_code'] = self::LABEL_PACKAGE;
		$rates                 = MMI_PO_ShipStation::carrier_rates( $carrier_code, $quote );
		if ( is_wp_error( $rates ) ) {
			return new WP_Error( 'mmi_po_label_price', __( 'Nothing was bought: the price could not be confirmed with ShipStation. ', 'mmi-po' ) . $rates->get_error_message() );
		}
		foreach ( $rates as $rate ) {
			if ( $rate['service_code'] === $service_code ) {
				return (float) $rate['cost'];
			}
		}
		return new WP_Error( 'mmi_po_label_price', __( 'Nothing was bought: ShipStation no longer offers that service for this package. Browse rates again.', 'mmi-po' ) );
	}

	private static function audit_label( string $action, int $po_id, array $row, string $outcome, array $extra = array() ): void {
		mmi_po_audit(
			$action,
			array(
				'object_type' => 'purchase_order',
				'object_id'   => $po_id,
				'outcome'     => $outcome,
				'details'     => array_merge(
					array(
						'carrier'  => $row['carrier_code'] ?? '',
						'service'  => $row['service_code'] ?? '',
						'cost'     => (float) ( $row['cost'] ?? 0 ),
						'tracking' => $row['tracking_number'] ?? '',
					),
					$extra
				),
			)
		);
	}

	public static function active_label( int $po_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MMI_PO_Install::table( 'shipments' ) . " WHERE po_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1", $po_id ),
			ARRAY_A
		);
		return $row ? self::shape( $row ) : null;
	}

	public static function labels( int $po_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . MMI_PO_Install::table( 'shipments' ) . ' WHERE po_id = %d ORDER BY id DESC', $po_id ),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'shape' ), $rows );
	}

	private static function last_package_for_supplier( int $supplier_id ): ?array {
		global $wpdb;
		$json = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT s.package FROM ' . MMI_PO_Install::table( 'shipments' ) . ' s JOIN ' . MMI_PO_Install::table( 'orders' ) . ' o ON o.id = s.po_id
				 WHERE o.supplier_id = %d ORDER BY s.id DESC LIMIT 1',
				$supplier_id
			)
		);
		$package = $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $package ) ) {
			return null;
		}
		$package['ship_date'] = current_time( 'Y-m-d' );
		return $package;
	}

	/** Sum of catalog weights × qty, only when every catalog line has a weight (else 0: ask). */
	private static function catalog_weight_oz( array $items ): float {
		$ids = array_filter( array_map( static function ( $i ) {
			return (int) $i['product_id'];
		}, $items ) );
		if ( ! $ids || count( $ids ) !== count( $items ) ) {
			return 0.0;
		}
		global $wpdb;
		$weights = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_weight' AND post_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')',
			OBJECT_K
		);
		$unit  = get_option( 'woocommerce_weight_unit', 'lbs' );
		$total = 0.0;
		foreach ( $items as $item ) {
			$w = isset( $weights[ (int) $item['product_id'] ] ) ? (float) $weights[ (int) $item['product_id'] ]->meta_value : 0;
			if ( $w <= 0 ) {
				return 0.0;
			}
			$total += wc_get_weight( $w, 'oz', $unit ) * (int) $item['qty'];
		}
		return round( $total, 2 );
	}

	private static function shape( array $row ): array {
		return array(
			'id'              => (int) $row['id'],
			'carrier_code'    => $row['carrier_code'],
			'service_name'    => $row['service_name'],
			'tracking_number' => $row['tracking_number'],
			'cost'            => (float) $row['cost'],
			'label_file'      => $row['label_file'],
			'label_url'       => $row['label_file'] !== '' && class_exists( 'MMI_PO_Admin' ) ? MMI_PO_Admin::stored_file_url( $row['label_file'] ) : '',
			'is_test'         => (bool) $row['is_test'],
			'status'          => $row['status'],
			'created_at'      => $row['created_at'],
		);
	}
}
