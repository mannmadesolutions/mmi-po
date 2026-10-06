<?php
/**
 * Supplier directory. The table and editor are rendered by admin-po.js from mmiPo.suppliers.
 *
 * @var callable $mmi_po_section_head
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="mmi-process-section">
	<?php
	$mmi_po_section_head(
		'building',
		__( 'Suppliers', 'mmi-po' ),
		__( 'Who you buy from. Linking brands lets the PO editor suggest the supplier and limit search to what they carry.', 'mmi-po' ),
		sprintf( '<button type="button" class="button button-primary" id="mmi-po-supplier-add"><span class="dashicons dashicons-plus-alt2"></span> %s</button>', esc_html__( 'Add supplier', 'mmi-po' ) )
	);
	?>
	<div class="mmi-section-content">
		<p class="mmi-po-overlap" id="mmi-po-brand-overlap" hidden></p>
		<div class="mmi-table-scroll-wrapper">
			<table class="widefat striped mmi-po-table" id="mmi-po-suppliers" data-mmi-resizable>
				<thead>
					<tr>
						<th class="sortable" data-sort-key="name"><?php esc_html_e( 'Supplier', 'mmi-po' ); ?></th>
						<th class="sortable" data-sort-key="email"><?php esc_html_e( 'Email', 'mmi-po' ); ?></th>
						<th class="sortable" data-sort-key="address"><?php esc_html_e( 'Address', 'mmi-po' ); ?></th>
						<th class="sortable" data-sort-key="brands"><?php esc_html_e( 'Brands', 'mmi-po' ); ?></th>
						<th class="sortable mmi-po-col-status" data-sort-key="labels"><?php esc_html_e( 'Labels', 'mmi-po' ); ?></th>
						<th class="sortable mmi-po-col-status" data-sort-key="status"><?php esc_html_e( 'Status', 'mmi-po' ); ?></th>
						<th class="mmi-po-col-actions"></th>
					</tr>
				</thead>
				<tbody id="mmi-po-suppliers-body"></tbody>
			</table>
		</div>
	</div>
</div>

<div class="mmi-modal-backdrop" id="mmi-po-supplier-modal" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mmi-po-supplier-title">
	<div class="mmi-modal mmi-modal--large">
		<div class="mmi-modal-header">
			<h3 id="mmi-po-supplier-title"><?php esc_html_e( 'Supplier', 'mmi-po' ); ?></h3>
			<button type="button" class="mmi-modal-close" data-close aria-label="<?php esc_attr_e( 'Close', 'mmi-po' ); ?>">&times;</button>
		</div>
		<form class="mmi-modal-body" id="mmi-po-supplier-form">
			<input type="hidden" name="id" value="0">
			<div class="mmi-po-grid">
				<div class="mmi-po-field"><label for="mmi-po-s-name"><?php esc_html_e( 'Name', 'mmi-po' ); ?></label><input type="text" id="mmi-po-s-name" name="name" required></div>
				<div class="mmi-po-field"><label for="mmi-po-s-contact"><?php esc_html_e( 'Contact name', 'mmi-po' ); ?></label><input type="text" id="mmi-po-s-contact" name="contact_name"></div>
				<div class="mmi-po-field"><label for="mmi-po-s-email"><?php esc_html_e( 'PO email(s)', 'mmi-po' ); ?></label><input type="text" id="mmi-po-s-email" name="email" placeholder="orders@supplier.com"></div>
				<div class="mmi-po-field"><label for="mmi-po-s-cc"><?php esc_html_e( 'Always Cc', 'mmi-po' ); ?></label><input type="text" id="mmi-po-s-cc" name="cc"></div>
				<div class="mmi-po-field"><label for="mmi-po-s-phone"><?php esc_html_e( 'Phone', 'mmi-po' ); ?></label><input type="text" id="mmi-po-s-phone" name="phone"></div>
				<div class="mmi-po-field"><label for="mmi-po-s-account"><?php esc_html_e( 'Our account #', 'mmi-po' ); ?></label><input type="text" id="mmi-po-s-account" name="account_number"></div>
				<div class="mmi-po-field mmi-po-field--wide">
					<span class="mmi-po-label"><?php esc_html_e( 'Address (printed under "Purchase From", and the ship-from for prepaid labels)', 'mmi-po' ); ?></span>
					<div class="mmi-po-address-grid">
						<label class="mmi-po-addr-street1"><span><?php esc_html_e( 'Street', 'mmi-po' ); ?></span><input type="text" name="address_fields[street1]"></label>
						<label class="mmi-po-addr-street2"><span><?php esc_html_e( 'Street line 2', 'mmi-po' ); ?></span><input type="text" name="address_fields[street2]"></label>
						<label class="mmi-po-addr-city"><span><?php esc_html_e( 'City', 'mmi-po' ); ?></span><input type="text" name="address_fields[city]"></label>
						<label class="mmi-po-addr-state"><span><?php esc_html_e( 'State', 'mmi-po' ); ?></span><input type="text" name="address_fields[state]"></label>
						<label class="mmi-po-addr-postal_code"><span><?php esc_html_e( 'ZIP', 'mmi-po' ); ?></span><input type="text" name="address_fields[postal_code]"></label>
						<label class="mmi-po-addr-country"><span><?php esc_html_e( 'Country', 'mmi-po' ); ?></span><input type="text" name="address_fields[country]" value="US"></label>
					</div>
				</div>
				<div class="mmi-po-field">
					<label for="mmi-po-s-policy"><?php esc_html_e( 'Shipping labels', 'mmi-po' ); ?></label>
					<select id="mmi-po-s-policy" name="label_policy">
						<?php foreach ( MMI_PO_Suppliers::LABEL_POLICIES as $mmi_po_key => $mmi_po_label ) : ?>
							<option value="<?php echo esc_attr( $mmi_po_key ); ?>"><?php echo esc_html( $mmi_po_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="mmi-po-field"><label for="mmi-po-s-instructions"><?php esc_html_e( 'Default instructions', 'mmi-po' ); ?></label><textarea id="mmi-po-s-instructions" name="instructions" rows="4" placeholder="<?php esc_attr_e( 'e.g. SHIP COMPLETE', 'mmi-po' ); ?>"></textarea></div>
				<div class="mmi-po-field mmi-po-field--wide">
					<span class="mmi-po-label" id="mmi-po-s-brands-label"><?php esc_html_e( 'Brands this supplier carries', 'mmi-po' ); ?></span>
					<div class="mmi-multiselect mmi-multiselect--block" id="mmi-po-s-brand-ms">
						<button type="button" class="mmi-ms-trigger" aria-expanded="false" aria-haspopup="true" aria-labelledby="mmi-po-s-brands-label mmi-po-s-brand-summary">
							<span class="mmi-ms-label" id="mmi-po-s-brand-summary"><?php esc_html_e( 'No brands', 'mmi-po' ); ?></span>
							<span class="mmi-ms-badge" id="mmi-po-s-brand-count" hidden></span>
							<span class="mmi-ms-caret" aria-hidden="true">▼</span>
						</button>
						<div class="mmi-ms-menu" hidden>
							<input type="search" class="mmi-ms-search" id="mmi-po-s-brand-filter" placeholder="<?php esc_attr_e( 'Filter brands…', 'mmi-po' ); ?>" aria-label="<?php esc_attr_e( 'Filter brands', 'mmi-po' ); ?>">
							<div id="mmi-po-s-brands"></div>
						</div>
					</div>
				</div>
				<div class="mmi-po-field mmi-po-field--wide">
					<label class="mmi-po-inline"><input type="checkbox" name="active" value="1" checked> <?php esc_html_e( 'Active (shown in the PO editor)', 'mmi-po' ); ?></label>
				</div>
			</div>
			<div class="mmi-modal-error" id="mmi-po-supplier-error" hidden></div>
		</form>
		<div class="mmi-modal-footer">
			<button type="button" class="button" data-close><?php esc_html_e( 'Cancel', 'mmi-po' ); ?></button>
			<button type="button" class="button button-primary" id="mmi-po-supplier-save"><span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save supplier', 'mmi-po' ); ?></button>
		</div>
	</div>
</div>
