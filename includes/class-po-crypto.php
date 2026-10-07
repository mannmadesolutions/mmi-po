<?php
/**
 * Reads ShipStation secrets stored in mmi-po's own format before 1.4.5 (libsodium secretbox,
 * key derived from this site's salts). New secrets are encrypted by the shared library's
 * MMI_Credentials through MMI_Settings; MMI_PO_ShipStation::settings() moves an old one over
 * the first time it is read.
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_PO_Crypto {

	const PREFIX = 'mmipo1:';

	public static function is_legacy( string $stored ): bool {
		return strpos( $stored, self::PREFIX ) === 0;
	}

	public static function decrypt( string $stored ): string {
		if ( ! self::is_legacy( $stored ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( $raw === false || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			hash( 'sha256', wp_salt( 'secure_auth' ) . '|mmi-po', true )
		);
		return $plain === false ? '' : $plain;
	}
}
