<?php
/**
 * Encrypts stored API secrets (libsodium secretbox, key derived from this site's WordPress salts)
 * so a database dump alone does not reveal them. Rotating the salts invalidates stored secrets;
 * they then have to be re-entered in Settings.
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_PO_Crypto {

	const PREFIX = 'mmipo1:';

	public static function encrypt( string $plain ): string {
		if ( $plain === '' ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
	}

	public static function decrypt( string $stored ): string {
		if ( strpos( $stored, self::PREFIX ) !== 0 ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( $raw === false || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key()
		);
		return $plain === false ? '' : $plain;
	}

	private static function key(): string {
		return hash( 'sha256', wp_salt( 'secure_auth' ) . '|mmi-po', true );
	}
}
