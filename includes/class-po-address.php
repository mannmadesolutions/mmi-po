<?php
/**
 * Structured postal address: the one shape used for supplier ship-from, PO ship-to and the
 * store address. Labels need the fields; the printed PO uses format().
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_PO_Address {

	const FIELDS = array( 'name', 'company', 'street1', 'street2', 'city', 'state', 'postal_code', 'country', 'phone' );

	public static function clean( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( self::FIELDS as $key ) {
			$out[ $key ] = sanitize_text_field( (string) ( $raw[ $key ] ?? '' ) );
		}
		$out['state']       = strtoupper( $out['state'] );
		$out['country']     = strtoupper( $out['country'] !== '' ? $out['country'] : 'US' );
		$out['residential'] = ! empty( $raw['residential'] );
		return $out;
	}

	public static function is_empty( array $a ): bool {
		return trim( ( $a['street1'] ?? '' ) . ( $a['city'] ?? '' ) . ( $a['postal_code'] ?? '' ) ) === '';
	}

	/** What a label needs: a street, city and postal code, plus state for US addresses. */
	public static function missing_for_label( array $a ): array {
		$missing = array();
		foreach ( array( 'street1' => 'street', 'city' => 'city', 'postal_code' => 'ZIP / postal code' ) as $key => $label ) {
			if ( trim( (string) ( $a[ $key ] ?? '' ) ) === '' ) {
				$missing[] = $label;
			}
		}
		if ( ( $a['country'] ?? 'US' ) === 'US' && trim( (string) ( $a['state'] ?? '' ) ) === '' ) {
			$missing[] = 'state';
		}
		if ( trim( ( $a['name'] ?? '' ) . ( $a['company'] ?? '' ) ) === '' ) {
			$missing[] = 'name or company';
		}
		return $missing;
	}

	/** Multi-line printable text. $with_name adds the name/company lines. */
	public static function format( array $a, bool $with_name = true ): string {
		$lines = array();
		if ( $with_name ) {
			$lines[] = $a['name'] ?? '';
			if ( ( $a['company'] ?? '' ) !== '' && ( $a['company'] ?? '' ) !== ( $a['name'] ?? '' ) ) {
				$lines[] = $a['company'];
			}
		}
		$lines[] = $a['street1'] ?? '';
		$lines[] = $a['street2'] ?? '';
		$lines[] = trim( ( $a['city'] ?? '' ) . ( ( $a['city'] ?? '' ) !== '' && ( $a['state'] ?? '' ) !== '' ? ', ' : '' ) . ( $a['state'] ?? '' ) . ' ' . ( $a['postal_code'] ?? '' ) );
		if ( ( $a['country'] ?? 'US' ) !== 'US' ) {
			$lines[] = WC()->countries->countries[ $a['country'] ] ?? $a['country'];
		}
		return implode( "\n", array_filter( array_map( 'trim', $lines ), 'strlen' ) );
	}

	/**
	 * Best-effort split of an old free-text US address ("20608 Madrona Ave\nTorrance, CA 90503").
	 * Used once, to migrate suppliers created before structured addresses existed.
	 */
	public static function parse( string $text ): array {
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $text ) ), 'strlen' ) );
		$a     = self::clean( array() );
		if ( ! $lines ) {
			return $a;
		}
		$last = array_pop( $lines );
		if ( preg_match( '/^(.+?),\s*([A-Za-z]{2})\s+(\d{5}(?:-\d{4})?)$/', $last, $m ) ) {
			$a['city']        = $m[1];
			$a['state']       = strtoupper( $m[2] );
			$a['postal_code'] = $m[3];
		} else {
			$lines[] = $last;
		}
		$a['street1'] = $lines[0] ?? '';
		$a['street2'] = $lines[1] ?? '';
		return $a;
	}

	/** Shipping address of a WooCommerce order, for drop-ship POs. */
	public static function from_wc_order( WC_Order $order ): array {
		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		$use_billing = $order->get_shipping_address_1() === '';
		return self::clean( array(
			'name'        => $name !== '' ? $name : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'company'     => $use_billing ? $order->get_billing_company() : $order->get_shipping_company(),
			'street1'     => $use_billing ? $order->get_billing_address_1() : $order->get_shipping_address_1(),
			'street2'     => $use_billing ? $order->get_billing_address_2() : $order->get_shipping_address_2(),
			'city'        => $use_billing ? $order->get_billing_city() : $order->get_shipping_city(),
			'state'       => $use_billing ? $order->get_billing_state() : $order->get_shipping_state(),
			'postal_code' => $use_billing ? $order->get_billing_postcode() : $order->get_shipping_postcode(),
			'country'     => $use_billing ? $order->get_billing_country() : $order->get_shipping_country(),
			'phone'       => $order->get_shipping_phone() !== '' ? $order->get_shipping_phone() : $order->get_billing_phone(),
			'residential' => ( $use_billing ? $order->get_billing_company() : $order->get_shipping_company() ) === '',
		) );
	}
}
