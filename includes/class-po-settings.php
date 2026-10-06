<?php
/**
 * Plugin settings: one MMI_Settings row merged over defaults, plus the PO number counter.
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_PO_Settings {

	const KEY         = 'mmi_po_settings';
	const COUNTER_KEY = 'mmi_po_next_number';

	/** Text fields (single line) and textarea fields, by key. Drives sanitizing and the form. */
	const TEXT_FIELDS     = array( 'buyer_name', 'buyer_phone', 'buyer_email', 'number_prefix', 'email_subject', 'reply_to', 'default_cc' );
	const TEXTAREA_FIELDS = array( 'buyer_address', 'default_instructions', 'email_body', 'footer_note' );

	public static function defaults(): array {
		$store = array_filter( array(
			get_option( 'woocommerce_store_address' ),
			get_option( 'woocommerce_store_address_2' ),
			trim( get_option( 'woocommerce_store_city' ) . ', ' . self::store_state() . ' ' . get_option( 'woocommerce_store_postcode' ), ', ' ),
		) );

		return array(
			'buyer_name'           => 'MusicMann Studios',
			'buyer_address'        => implode( "\n", $store ),
			'buyer_phone'          => '',
			'buyer_email'          => get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ),
			'store_address'        => array(
				'name'        => '',
				'company'     => 'MusicMann Studios',
				'street1'     => (string) get_option( 'woocommerce_store_address' ),
				'street2'     => (string) get_option( 'woocommerce_store_address_2' ),
				'city'        => (string) get_option( 'woocommerce_store_city' ),
				'state'       => self::store_state(),
				'postal_code' => (string) get_option( 'woocommerce_store_postcode' ),
				'country'     => self::store_country(),
				'phone'       => '',
				'residential' => false,
			),
			'logo_id'              => 0,
			'number_prefix'        => 'PO-',
			'default_instructions' => '',
			'footer_note'          => '',
			'email_subject'        => 'Purchase Order {po_number} from {buyer_name}',
			'email_body'           => "Hello {supplier_contact},\n\nPlease find attached purchase order {po_number} ({item_count} line items, {total}).\n\nPlease confirm receipt, pricing and expected ship date by replying to this email.\n\nThank you,\n{buyer_name}",
			'reply_to'             => get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ),
			'default_cc'           => '',
			'bcc_self'             => 1,
		);
	}

	public static function all(): array {
		$saved = MMI_Settings::get( self::KEY, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( string $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/** Sanitizes a raw form payload and saves it. Unknown keys are dropped. */
	public static function save( array $raw ): void {
		$clean = array();
		foreach ( self::TEXT_FIELDS as $key ) {
			$clean[ $key ] = sanitize_text_field( (string) ( $raw[ $key ] ?? '' ) );
		}
		foreach ( self::TEXTAREA_FIELDS as $key ) {
			$clean[ $key ] = sanitize_textarea_field( (string) ( $raw[ $key ] ?? '' ) );
		}
		$clean['buyer_email'] = sanitize_email( $clean['buyer_email'] );
		$clean['reply_to']    = sanitize_email( $clean['reply_to'] );
		$clean['default_cc']  = implode( ', ', self::parse_emails( $clean['default_cc'] ) );
		$clean['logo_id']       = absint( $raw['logo_id'] ?? 0 );
		$clean['store_address'] = MMI_PO_Address::clean( (array) ( $raw['store_address'] ?? array() ) );
		$clean['bcc_self']    = empty( $raw['bcc_self'] ) ? 0 : 1;

		MMI_Settings::set( self::KEY, $clean );

		if ( isset( $raw['next_number'] ) && absint( $raw['next_number'] ) > 0 ) {
			MMI_Settings::set( self::COUNTER_KEY, absint( $raw['next_number'] ) );
		}
	}

	public static function next_number(): int {
		return max( 1, (int) MMI_Settings::get( self::COUNTER_KEY, 1001 ) );
	}

	public static function advance_number( int $used ): void {
		if ( $used >= self::next_number() ) {
			MMI_Settings::set( self::COUNTER_KEY, $used + 1 );
		}
	}

	/** Splits a comma/semicolon/space separated list into valid, unique addresses. */
	public static function parse_emails( string $list ): array {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY ) as $candidate ) {
			$email = sanitize_email( $candidate );
			if ( $email && is_email( $email ) ) {
				$out[ strtolower( $email ) ] = $email;
			}
		}
		return array_values( $out );
	}

	/** Entries in a typed list that are not valid addresses, so a typo is reported rather than dropped. */
	public static function invalid_emails( string $list ): array {
		return array_values( array_filter( preg_split( '/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY ), static function ( $candidate ) {
			return ! is_email( sanitize_email( $candidate ) );
		} ) );
	}

	/** Logo file path for the PDF: the chosen media item, else the bundled default. */
	public static function logo_path(): string {
		$id = (int) self::get( 'logo_id' );
		if ( $id ) {
			$file = get_attached_file( $id );
			if ( $file && is_readable( $file ) ) {
				return $file;
			}
		}
		return MMI_PO_PATH . 'assets/img/default-logo.png';
	}

	public static function logo_url(): string {
		$id = (int) self::get( 'logo_id' );
		$url = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		return $url ? $url : MMI_PO_URL . 'assets/img/default-logo.png';
	}

	private static function store_country(): string {
		$parts = explode( ':', (string) get_option( 'woocommerce_default_country', 'US' ) );
		return $parts[0] !== '' ? $parts[0] : 'US';
	}

	private static function store_state(): string {
		$country = (string) get_option( 'woocommerce_default_country', '' );
		$parts   = explode( ':', $country );
		return $parts[1] ?? '';
	}
}
