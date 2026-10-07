<?php
/**
 * PO document: one HTML template (templates/po-document.php) rendered to PDF by the bundled
 * Dompdf (LGPL, runs locally — no external rendering service). Copies of sent PDFs are kept in
 * the private uploads folder so the record shows exactly what each supplier received.
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;

class MMI_PO_Document {

	const PRIVATE_DIR = 'purchase-orders';
	const PAPER       = 'letter';

	public static function html( array $order ): string {
		$supplier = MMI_PO_Orders::supplier_for( $order );
		$settings = MMI_PO_Settings::all();
		$logo     = self::logo_data_uri();
		$label    = ( $order['label_mode'] ?? '' ) === 'ours' ? MMI_PO_Shipping::active_label( (int) $order['id'] ) : null;

		ob_start();
		include MMI_PO_PATH . 'templates/po-document.php';
		return (string) ob_get_clean();
	}

	/** @return string|WP_Error Raw PDF bytes. */
	public static function pdf( array $order ) {
		require_once MMI_PO_PATH . 'vendor/autoload.php';

		try {
			$options = new Options();
			$options->set( 'isRemoteEnabled', false );
			$options->set( 'isPhpEnabled', false );
			$options->set( 'isJavascriptEnabled', false );
			// The logo is inlined as a data URI, so Dompdf needs no local file access beyond assets/.
			$options->set( 'chroot', MMI_PO_PATH . 'assets' );
			$options->set( 'fontCache', self::dir( 'font-cache' ) );
			$options->set( 'tempDir', get_temp_dir() );
			$options->set( 'defaultFont', 'DejaVu Serif' );

			$dompdf = new Dompdf( $options );
			$dompdf->loadHtml( self::html( $order ), 'UTF-8' );
			$dompdf->setPaper( self::PAPER, 'portrait' );
			$dompdf->render();
			return (string) $dompdf->output();
		} catch ( \Throwable $e ) {
			MMI_Logger::error( 'PO PDF render failed', array( 'po_id' => (int) $order['id'], 'error' => $e->getMessage() ), 'general', 'MMI_PO_Document' );
			return new WP_Error( 'mmi_po_pdf', __( 'The PDF could not be generated.', 'mmi-po' ) );
		}
	}

	public static function filename( array $order ): string {
		$supplier = MMI_PO_Orders::supplier_for( $order );
		return sanitize_file_name( $order['po_number'] . ( $supplier['name'] ? '-' . $supplier['name'] : '' ) . '.pdf' );
	}

	/**
	 * Writes a dated copy of the PDF to the private folder (used for every emailed version).
	 *
	 * @return string|WP_Error Absolute path.
	 */
	public static function store( array $order, string $bytes ) {
		return self::store_file( self::stored_name( $order['po_number'] ), $bytes );
	}

	/**
	 * Archive file name: readable prefix plus a 128-bit random token, so a file cannot be found
	 * by guessing PO numbers and dates even where the private folder's name leaks.
	 */
	public static function stored_name( string $prefix ): string {
		return sanitize_file_name( $prefix . '-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 16 ) ) . '.pdf' );
	}

	/**
	 * Writes any archived file (sent PO copies, shipping labels) via temp file + rename.
	 *
	 * @return string|WP_Error Absolute path.
	 */
	public static function store_file( string $name, string $bytes ) {
		$dir = self::dir( 'files' );
		$tmp = $dir . $name . '.tmp';
		if ( $dir === '' || $bytes === '' || file_put_contents( $tmp, $bytes ) === false || ! rename( $tmp, $dir . $name ) ) {
			MMI_Logger::error( 'Could not store PO file', array( 'file' => $name, 'dir' => $dir ), 'general', 'MMI_PO_Document' );
			return new WP_Error( 'mmi_po_store', __( 'The file could not be saved to disk.', 'mmi-po' ) );
		}
		return $dir . $name;
	}

	/** Resolves a stored file name to its path, confined to the private folder. */
	public static function stored_path( string $name ): string {
		$dir  = self::dir( 'files' );
		$real = realpath( $dir . sanitize_file_name( $name ) );
		$root = realpath( $dir );
		if ( ! $real || ! $root || strpos( $real, $root . DIRECTORY_SEPARATOR ) !== 0 || ! is_file( $real ) ) {
			return '';
		}
		return $real;
	}

	private static function dir( string $sub ): string {
		$base = mmi_shared_lib_private_subdir( self::PRIVATE_DIR );
		if ( $base === '' ) {
			return '';
		}
		$dir = $base . $sub . '/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( function_exists( 'mmi_shared_lib_guard_dir' ) ) {
			mmi_shared_lib_guard_dir( $dir );
		}
		return $dir;
	}

	/** Inline data URI so Dompdf never needs file or network access outside its chroot. */
	private static function logo_data_uri(): string {
		$path = MMI_PO_Settings::logo_path();
		$type = wp_check_filetype( $path );
		if ( ! is_readable( $path ) || empty( $type['type'] ) || strpos( $type['type'], 'image/' ) !== 0 ) {
			return '';
		}
		return 'data:' . $type['type'] . ';base64,' . base64_encode( (string) file_get_contents( $path ) );
	}
}
