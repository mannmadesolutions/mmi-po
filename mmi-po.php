<?php
/**
 * Plugin Name: MMI Purchase Orders
 * Plugin URI: https://mannmade.us/extensions/mmi-po
 * Description: Build supplier purchase orders from the WooCommerce catalog, keep a record of every PO and what was sent, export branded PDFs and email them to suppliers.
 * Version: 1.4.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Author: MannMade Solutions
 * Author URI: https://mannmade.solutions
 * Text Domain: mmi-po
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MMI_PO_VERSION', '1.4.4' );
define( 'MMI_PO_FILE', __FILE__ );
define( 'MMI_PO_PATH', plugin_dir_path( __FILE__ ) );
define( 'MMI_PO_URL', plugin_dir_url( __FILE__ ) );

/* ── MMI Shared Library (ADR-0006) — must load before any MMI_* class use ── */
require_once MMI_PO_PATH . 'includes/mmi-shared/bootstrap.php';

/* ── Capability + audit helpers (one filterable capability, AGENTS.md) ── */
if ( ! function_exists( 'mmi_po_capability' ) ) {
	/**
	 * 'operate' = day-to-day PO work (shop managers); 'manage' = settings and ShipStation credentials.
	 */
	function mmi_po_capability( string $context = 'operate' ): string {
		$default = $context === 'manage' ? 'manage_options' : 'manage_woocommerce';
		return (string) apply_filters( 'mmi_po_required_capability', $default, $context );
	}
}
if ( ! function_exists( 'mmi_po_user_can' ) ) {
	function mmi_po_user_can( string $context = 'operate' ): bool {
		return current_user_can( mmi_po_capability( $context ) );
	}
}
if ( ! function_exists( 'mmi_po_audit' ) ) {
	function mmi_po_audit( string $action, array $args = array() ): void {
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			MMI_Audit_Log::record( 'mmi-po', $action, $args );
		}
	}
}

require_once MMI_PO_PATH . 'includes/class-po-install.php';

register_activation_hook( __FILE__, array( 'MMI_PO_Install', 'install' ) );

/* ── Boot: WooCommerce is the one hard dependency (products, brands, currency) ── */
add_action( 'plugins_loaded', static function () {

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'MMI Purchase Orders requires WooCommerce to be active.', 'mmi-po' ) . '</p></div>';
		} );
		return;
	}

	require_once MMI_PO_PATH . 'includes/class-po-address.php';
	require_once MMI_PO_PATH . 'includes/class-po-crypto.php';
	require_once MMI_PO_PATH . 'includes/class-po-settings.php';
	require_once MMI_PO_PATH . 'includes/class-po-log.php';
	require_once MMI_PO_PATH . 'includes/class-po-suppliers.php';
	require_once MMI_PO_PATH . 'includes/class-po-orders.php';
	require_once MMI_PO_PATH . 'includes/class-po-feed-sizes.php';
	require_once MMI_PO_PATH . 'includes/class-po-products.php';
	require_once MMI_PO_PATH . 'includes/class-po-document.php';
	require_once MMI_PO_PATH . 'includes/class-po-mailer.php';
	require_once MMI_PO_PATH . 'includes/class-po-shipstation.php';
	require_once MMI_PO_PATH . 'includes/class-po-shipping.php';

	// Self-heals the schema after a file-only update (no activation hook runs then).
	MMI_PO_Install::maybe_upgrade();

	if ( is_admin() ) {
		require_once MMI_PO_PATH . 'includes/admin/class-po-admin.php';
		MMI_PO_Admin::init();
	}
} );
