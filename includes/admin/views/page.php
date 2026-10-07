<?php
/**
 * Purchase Orders page shell: .wrap.mmi-page → .mmi-header → .wp-header-end → tabs → sections.
 *
 * @var string $tab Current tab key.
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prints a page section's header band.
 *
 * @param string $icon  Dashicon suffix.
 * @param string $title Translated title.
 * @param string $desc    Translated one-line description.
 * @param string $actions Optional pre-escaped buttons, right-aligned in the header band.
 */
$mmi_po_section_head = static function ( $icon, $title, $desc, $actions = '' ) {
	?>
	<div class="mmi-section-header">
		<div>
			<h3 class="mmi-process-section-header"><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span> <?php echo esc_html( $title ); ?></h3>
			<p class="mmi-process-section-description"><?php echo esc_html( $desc ); ?></p>
		</div>
		<?php if ( $actions !== '' ) : ?>
			<div class="mmi-po-section-actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped by the caller. ?></div>
		<?php endif; ?>
	</div>
	<?php
};

$mmi_po_editing = $tab === 'edit' && ! empty( $_GET['po'] ); // phpcs:ignore WordPress.Security.NonceVerification -- navigation only.
?>
<div class="wrap mmi-page mmi-po-page">
	<div class="mmi-header">
		<h1><span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Purchase Orders', 'mmi-po' ); ?></h1>
		<p class="mmi-header-description"><?php esc_html_e( 'Search the catalog, build a purchase order for a supplier, then preview, download or email the PDF. Every PO, export and email is kept on record.', 'mmi-po' ); ?></p>
	</div>
	<div class="wp-header-end"></div>

	<nav class="nav-tab-wrapper">
		<?php foreach ( MMI_PO_Admin::visible_tabs() as $key => $def ) : ?>
			<?php
			$label = $def['label'];
			if ( $key === 'edit' && $mmi_po_editing ) {
				$label = 'Edit PO';
			}
			?>
			<a href="<?php echo esc_url( MMI_PO_Admin::url( $key === 'orders' ? array() : array( 'tab' => $key ) ) ); ?>" class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $def['icon'] ); ?>"></span> <?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="mmi-po-body">
		<?php include MMI_PO_PATH . 'includes/admin/views/tab-' . $tab . '.php'; ?>
	</div>
</div>
