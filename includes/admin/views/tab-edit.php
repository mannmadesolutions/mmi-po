<?php
/**
 * PO editor. Markup only; assets/js/admin-po.js fills it from mmiPo.order and keeps totals live.
 *
 * @var callable $mmi_po_section_head
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="mmi-po-editor" id="mmi-po-editor">

	<div class="mmi-toolbar mmi-po-actionbar">
		<div class="mmi-po-identity">
			<strong id="mmi-po-number"><?php esc_html_e( 'New purchase order', 'mmi-po' ); ?></strong>
			<span class="mmi-badge warning" id="mmi-po-status"><?php esc_html_e( 'Draft', 'mmi-po' ); ?></span>
			<span class="mmi-po-dirty" id="mmi-po-dirty" hidden><?php esc_html_e( 'Unsaved changes', 'mmi-po' ); ?></span>
		</div>
		<div class="mmi-po-buttons">
			<button type="button" class="button button-primary" id="mmi-po-save"><span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save', 'mmi-po' ); ?></button>
			<button type="button" class="button" id="mmi-po-preview" disabled><span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Preview PDF', 'mmi-po' ); ?></button>
			<button type="button" class="button" id="mmi-po-download" disabled><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download PDF', 'mmi-po' ); ?></button>
			<button type="button" class="button" id="mmi-po-email" disabled><span class="dashicons dashicons-email-alt"></span> <?php esc_html_e( 'Email to supplier…', 'mmi-po' ); ?></button>
			<select id="mmi-po-status-select" aria-label="<?php esc_attr_e( 'Change status', 'mmi-po' ); ?>" disabled>
				<option value=""><?php esc_html_e( 'Change status…', 'mmi-po' ); ?></option>
				<?php foreach ( MMI_PO_Orders::STATUSES as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button" id="mmi-po-duplicate" disabled><span class="dashicons dashicons-admin-page"></span> <?php esc_html_e( 'Duplicate', 'mmi-po' ); ?></button>
			<button type="button" class="button button-link-delete" id="mmi-po-delete" hidden><?php esc_html_e( 'Delete draft', 'mmi-po' ); ?></button>
		</div>
	</div>
	<div class="mmi-po-message" id="mmi-po-message" role="status" hidden></div>

	<div class="mmi-process-section">
		<?php $mmi_po_section_head( 'building', __( 'Supplier & delivery', 'mmi-po' ), __( 'Who the order goes to and where it ships. The supplier\'s default instructions fill in when you pick one.', 'mmi-po' ) ); ?>
		<div class="mmi-section-content">
			<div class="mmi-po-grid">
				<div class="mmi-po-field">
					<label for="mmi-po-supplier"><?php esc_html_e( 'Supplier', 'mmi-po' ); ?></label>
					<select id="mmi-po-supplier">
						<option value="0"><?php esc_html_e( '— Choose a supplier —', 'mmi-po' ); ?></option>
					</select>
					<div class="mmi-po-supplier-card" id="mmi-po-supplier-card" hidden></div>
				</div>
				<div class="mmi-po-field">
					<?php
					$mmi_po_prefix = (string) MMI_PO_Settings::get( 'number_prefix' );
					$mmi_po_recent = MMI_PO_Orders::recent_wc_orders();
					?>
					<label for="mmi-po-number-input"><?php esc_html_e( 'PO number', 'mmi-po' ); ?></label>
					<input type="text" id="mmi-po-number-input" list="mmi-po-recent-orders" autocomplete="off"
						placeholder="<?php echo esc_attr( sprintf( /* translators: %s: next number */ __( 'Next: %s — or pick an order', 'mmi-po' ), $mmi_po_prefix . MMI_PO_Settings::next_number() ) ); ?>">
					<datalist id="mmi-po-recent-orders">
						<?php foreach ( $mmi_po_recent as $mmi_po_o ) : ?>
							<option value="<?php echo esc_attr( $mmi_po_o['po_next'] ?? $mmi_po_prefix . $mmi_po_o['number'] ); ?>"><?php echo esc_html( sprintf( '#%s · %s · %s · %s · %s%s', $mmi_po_o['number'], $mmi_po_o['date'], $mmi_po_o['name'], $mmi_po_o['total'], $mmi_po_o['status'], $mmi_po_o['po_uses'] ? sprintf( ' · %d PO already', $mmi_po_o['po_uses'] ) : '' ) ); ?></option>
						<?php endforeach; ?>
					</datalist>
					<p class="mmi-hint-text"><?php esc_html_e( 'Pick a recent WooCommerce order to number this PO after the sale it fills, or leave blank for the next number. Locked once the PO is emailed.', 'mmi-po' ); ?></p>
				</div>
				<div class="mmi-po-field">
					<label for="mmi-po-date"><?php esc_html_e( 'PO date', 'mmi-po' ); ?></label>
					<input type="date" id="mmi-po-date">
				</div>
				<div class="mmi-po-field">
					<label for="mmi-po-instructions"><?php esc_html_e( 'Instructions (printed)', 'mmi-po' ); ?></label>
					<textarea id="mmi-po-instructions" rows="4" placeholder="<?php esc_attr_e( 'e.g. SHIP COMPLETE', 'mmi-po' ); ?>"></textarea>
				</div>
				<div class="mmi-po-field mmi-po-field--wide">
					<span class="mmi-po-label"><?php esc_html_e( 'Deliver to', 'mmi-po' ); ?></span>
					<div class="mmi-po-choice" role="radiogroup">
						<label class="mmi-po-inline"><input type="radio" name="mmi-po-dest" value="store" id="mmi-po-dest-store"> <?php esc_html_e( 'Our address (inbound)', 'mmi-po' ); ?></label>
						<label class="mmi-po-inline"><input type="radio" name="mmi-po-dest" value="customer" id="mmi-po-dest-customer"> <?php esc_html_e( 'A customer (drop-ship)', 'mmi-po' ); ?></label>
					</div>
					<div class="mmi-toolbar mmi-po-dropship" id="mmi-po-dropship" hidden>
						<label for="mmi-po-wc-order"><?php esc_html_e( 'WooCommerce order #', 'mmi-po' ); ?></label>
						<input type="text" id="mmi-po-wc-order" inputmode="numeric">
						<button type="button" class="button" id="mmi-po-wc-load"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Load address & items', 'mmi-po' ); ?></button>
						<a id="mmi-po-wc-link" target="_blank" rel="noopener" hidden></a>
					</div>
				</div>
				<div class="mmi-po-field mmi-po-field--wide">
					<div class="mmi-po-address-grid" id="mmi-po-ship-to">
						<?php
						$mmi_po_addr_labels = array(
							'name'        => __( 'Name', 'mmi-po' ),
							'company'     => __( 'Company', 'mmi-po' ),
							'street1'     => __( 'Street', 'mmi-po' ),
							'street2'     => __( 'Street line 2', 'mmi-po' ),
							'city'        => __( 'City', 'mmi-po' ),
							'state'       => __( 'State', 'mmi-po' ),
							'postal_code' => __( 'ZIP', 'mmi-po' ),
							'country'     => __( 'Country', 'mmi-po' ),
							'phone'       => __( 'Phone', 'mmi-po' ),
						);
						foreach ( $mmi_po_addr_labels as $mmi_po_key => $mmi_po_label ) :
							?>
							<label class="mmi-po-addr-<?php echo esc_attr( $mmi_po_key ); ?>"><span><?php echo esc_html( $mmi_po_label ); ?></span><input type="text" data-addr="<?php echo esc_attr( $mmi_po_key ); ?>"></label>
						<?php endforeach; ?>
						<label class="mmi-po-inline mmi-po-addr-residential"><input type="checkbox" data-addr="residential"> <?php esc_html_e( 'Residential address', 'mmi-po' ); ?></label>
					</div>
				</div>
				<div class="mmi-po-field mmi-po-field--wide">
					<label for="mmi-po-notes"><?php esc_html_e( 'Internal notes (not printed)', 'mmi-po' ); ?></label>
					<textarea id="mmi-po-notes" rows="2"></textarea>
				</div>
			</div>
		</div>
	</div>

	<div class="mmi-process-section">
		<?php $mmi_po_section_head( 'cart', __( 'Line items', 'mmi-po' ), __( 'Search by product name, SKU, MPN or UPC. Item # uses the MPN when there is one; unit cost starts from the catalog cost (or the last PO price).', 'mmi-po' ) ); ?>
		<div class="mmi-section-content">
			<div class="mmi-po-search">
				<div class="mmi-toolbar">
					<span class="dashicons dashicons-search"></span>
					<input type="search" id="mmi-po-search" autocomplete="off" placeholder="<?php esc_attr_e( 'Search products to add…', 'mmi-po' ); ?>" aria-label="<?php esc_attr_e( 'Search products', 'mmi-po' ); ?>">
					<label class="mmi-po-inline" id="mmi-po-brand-only-wrap" hidden>
						<input type="checkbox" id="mmi-po-brand-only" checked>
						<span id="mmi-po-brand-only-label"><?php esc_html_e( 'Only this supplier\'s brands', 'mmi-po' ); ?></span>
					</label>
					<span class="mmi-loading" id="mmi-po-search-spinner" hidden></span>
				</div>
				<div class="mmi-po-results" id="mmi-po-results" hidden></div>
			</div>

			<div class="mmi-table-scroll-wrapper mmi-po-lines-wrap">
				<table class="widefat mmi-po-table mmi-po-lines" id="mmi-po-lines">
					<thead>
						<tr>
							<th class="mmi-po-col-handle"></th>
							<th class="mmi-po-col-item"><?php esc_html_e( 'Item #', 'mmi-po' ); ?></th>
							<th><?php esc_html_e( 'Description', 'mmi-po' ); ?></th>
							<th class="mmi-po-col-qty"><?php esc_html_e( 'Qty', 'mmi-po' ); ?></th>
							<th class="mmi-po-col-money"><?php esc_html_e( 'Unit cost', 'mmi-po' ); ?></th>
							<th class="mmi-po-col-money"><?php esc_html_e( 'Amount', 'mmi-po' ); ?></th>
							<th class="mmi-po-col-remove"></th>
						</tr>
					</thead>
					<tbody id="mmi-po-lines-body"></tbody>
				</table>
			</div>
			<p class="mmi-po-empty" id="mmi-po-lines-empty"><?php esc_html_e( 'No lines yet. Search above to add products, or add a blank line for anything not in the catalog.', 'mmi-po' ); ?></p>
			<button type="button" class="button" id="mmi-po-add-line"><span class="dashicons dashicons-plus"></span> <?php esc_html_e( 'Add blank line', 'mmi-po' ); ?></button>

			<table class="mmi-po-totals">
				<tr><th><?php esc_html_e( 'Sales subtotal', 'mmi-po' ); ?></th><td id="mmi-po-subtotal">0.00</td></tr>
				<tr><th><label for="mmi-po-tax"><?php esc_html_e( 'Tax', 'mmi-po' ); ?></label></th><td><input type="number" id="mmi-po-tax" min="0" step="0.01" value="0"></td></tr>
				<tr><th><label for="mmi-po-shipping"><?php esc_html_e( 'Shipping', 'mmi-po' ); ?></label></th><td><input type="number" id="mmi-po-shipping" min="0" step="0.01" value="0"></td></tr>
				<tr class="mmi-po-grand"><th><?php esc_html_e( 'Total', 'mmi-po' ); ?></th><td id="mmi-po-total">0.00</td></tr>
			</table>
		</div>
	</div>

	<div class="mmi-process-section" id="mmi-po-shipping-section">
		<?php
		$mmi_po_section_head(
			'car',
			__( 'Shipping', 'mmi-po' ),
			__( 'Either the supplier ships on their own label, or you buy a prepaid ShipStation label (supplier → you, or supplier → your customer) that is emailed with the PO.', 'mmi-po' ),
			sprintf( '<a class="button" href="https://ship.shipstation.com/" target="_blank" rel="noopener"><span class="dashicons dashicons-external"></span> %s</a>', esc_html__( 'Open ShipStation', 'mmi-po' ) )
		);
		?>
		<div class="mmi-section-content">
			<div class="mmi-po-choice" role="radiogroup">
				<label class="mmi-po-inline"><input type="radio" name="mmi-po-label-mode" value="supplier" id="mmi-po-label-supplier"> <?php esc_html_e( 'Supplier ships on their own label', 'mmi-po' ); ?></label>
				<label class="mmi-po-inline"><input type="radio" name="mmi-po-label-mode" value="ours" id="mmi-po-label-ours"> <?php esc_html_e( 'We provide a prepaid label', 'mmi-po' ); ?></label>
			</div>
			<p class="mmi-po-note" id="mmi-po-label-policy" hidden></p>

			<div id="mmi-po-label-panel" hidden>
				<div class="mmi-po-package">
					<label><span><?php esc_html_e( 'Weight', 'mmi-po' ); ?></span>
						<span class="mmi-po-weight"><input type="number" min="0" step="1" data-pkg="weight_lb" aria-label="<?php esc_attr_e( 'Pounds', 'mmi-po' ); ?>"> lb <input type="number" min="0" step="0.1" data-pkg="weight_oz_part" aria-label="<?php esc_attr_e( 'Ounces', 'mmi-po' ); ?>"> oz</span></label>
					<label><span><?php esc_html_e( 'Box L × W × H (in)', 'mmi-po' ); ?></span>
						<span class="mmi-po-dims"><input type="number" min="0" step="0.5" data-pkg="length" aria-label="<?php esc_attr_e( 'Length', 'mmi-po' ); ?>"> × <input type="number" min="0" step="0.5" data-pkg="width" aria-label="<?php esc_attr_e( 'Width', 'mmi-po' ); ?>"> × <input type="number" min="0" step="0.5" data-pkg="height" aria-label="<?php esc_attr_e( 'Height', 'mmi-po' ); ?>"></span></label>
					<label><span><?php esc_html_e( 'Confirmation', 'mmi-po' ); ?></span>
						<select data-pkg="confirmation">
							<?php foreach ( MMI_PO_Shipping::CONFIRMATIONS as $mmi_po_key => $mmi_po_label ) : ?>
								<option value="<?php echo esc_attr( $mmi_po_key ); ?>"><?php echo esc_html( $mmi_po_label ); ?></option>
							<?php endforeach; ?>
						</select></label>
					<label><span><?php esc_html_e( 'Ship date', 'mmi-po' ); ?></span><input type="date" data-pkg="ship_date"></label>
				</div>

				<div class="mmi-po-contents" id="mmi-po-contents">
					<div class="mmi-po-contents-head">
						<strong><?php esc_html_e( 'What\'s in the box', 'mmi-po' ); ?></strong>
						<span class="mmi-hint-text"><?php esc_html_e( 'Each product\'s WooCommerce weight and dimensions, or, where those are empty, the sizes in its supplier feed from the Data Pipeline. The suggested box is a rough guess: the largest item plus room for the rest and 1" of padding on every side.', 'mmi-po' ); ?></span>
					</div>
					<div class="mmi-table-scroll-wrapper">
						<table class="widefat striped mmi-po-table mmi-po-contents-table">
							<thead>
								<tr>
									<th class="mmi-po-col-item"><?php esc_html_e( 'Item #', 'mmi-po' ); ?></th>
									<th><?php esc_html_e( 'Description', 'mmi-po' ); ?></th>
									<th class="mmi-po-col-num"><?php esc_html_e( 'Qty', 'mmi-po' ); ?></th>
									<th class="mmi-po-col-dims"><?php esc_html_e( 'Each (L × W × H in)', 'mmi-po' ); ?></th>
									<th class="mmi-po-col-num"><?php esc_html_e( 'Each (lb)', 'mmi-po' ); ?></th>
									<th class="mmi-po-col-num"><?php esc_html_e( 'Line (lb)', 'mmi-po' ); ?></th>
									<th class="mmi-po-col-status"><?php esc_html_e( 'Size from', 'mmi-po' ); ?></th>
								</tr>
							</thead>
							<tbody id="mmi-po-contents-body"></tbody>
						</table>
					</div>
					<div class="mmi-toolbar mmi-po-contents-summary" id="mmi-po-contents-summary"></div>
				</div>

				<div class="mmi-po-label-current" id="mmi-po-label-current" hidden></div>

				<div class="mmi-toolbar" id="mmi-po-rates-bar">
					<button type="button" class="button button-primary" id="mmi-po-browse-rates"><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Browse rates & buy label', 'mmi-po' ); ?></button>
					<span class="mmi-badge" id="mmi-po-expiry-badge" hidden></span>
					<span class="mmi-po-note" id="mmi-po-label-blocker" hidden></span>
				</div>
				<ul class="mmi-po-label-history" id="mmi-po-label-history"></ul>
			</div>
		</div>
	</div>

	<div class="mmi-process-section" id="mmi-po-history-section" hidden>
		<?php $mmi_po_section_head( 'backup', __( 'History', 'mmi-po' ), __( 'Everything done to this PO. Emailed rows link to the exact PDF the supplier received.', 'mmi-po' ) ); ?>
		<div class="mmi-section-content">
			<ul class="mmi-po-history" id="mmi-po-history"></ul>
		</div>
	</div>
</div>

<div class="mmi-modal-backdrop" id="mmi-po-email-modal" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mmi-po-email-title">
	<div class="mmi-modal mmi-modal--large">
		<div class="mmi-modal-header">
			<h3 id="mmi-po-email-title"><?php esc_html_e( 'Email purchase order', 'mmi-po' ); ?></h3>
			<button type="button" class="mmi-modal-close" data-close aria-label="<?php esc_attr_e( 'Close', 'mmi-po' ); ?>">&times;</button>
		</div>
		<div class="mmi-modal-body">
			<p class="mmi-hint-text"><?php esc_html_e( 'This sends the PO to the supplier through the site\'s normal mail setup, with the PDF attached. A copy of the exact PDF sent is kept in the PO\'s history.', 'mmi-po' ); ?></p>
			<div class="mmi-po-field">
				<label for="mmi-po-email-to"><?php esc_html_e( 'To', 'mmi-po' ); ?></label>
				<input type="text" id="mmi-po-email-to" placeholder="orders@supplier.com">
				<label class="mmi-po-inline" id="mmi-po-remember-wrap" hidden><input type="checkbox" id="mmi-po-email-remember" checked> <?php esc_html_e( 'Save as this supplier\'s email', 'mmi-po' ); ?></label>
			</div>
			<div class="mmi-po-field">
				<label for="mmi-po-email-cc"><?php esc_html_e( 'Cc', 'mmi-po' ); ?></label>
				<input type="text" id="mmi-po-email-cc">
			</div>
			<div class="mmi-po-field">
				<label for="mmi-po-email-subject"><?php esc_html_e( 'Subject', 'mmi-po' ); ?></label>
				<input type="text" id="mmi-po-email-subject">
			</div>
			<div class="mmi-po-field">
				<label for="mmi-po-email-body"><?php esc_html_e( 'Message', 'mmi-po' ); ?></label>
				<textarea id="mmi-po-email-body" rows="9"></textarea>
			</div>
			<label class="mmi-po-inline" id="mmi-po-attach-label-wrap" hidden><input type="checkbox" id="mmi-po-attach-label" checked> <span id="mmi-po-attach-label-text"></span></label>
			<div class="mmi-modal-error" id="mmi-po-email-error" hidden></div>
		</div>
		<div class="mmi-modal-footer">
			<button type="button" class="button" data-close><?php esc_html_e( 'Cancel', 'mmi-po' ); ?></button>
			<button type="button" class="button button-primary" id="mmi-po-email-send"><span class="dashicons dashicons-email-alt"></span> <?php esc_html_e( 'Send', 'mmi-po' ); ?></button>
		</div>
	</div>
</div>

<?php
$mmi_po_countries = WC()->countries->get_countries();
?>
<div class="mmi-modal-backdrop" id="mmi-po-rb" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mmi-po-rb-title">
	<div class="mmi-modal mmi-modal--xlarge mmi-po-rb">
		<div class="mmi-modal-header">
			<h3 id="mmi-po-rb-title"><?php esc_html_e( 'Rate Browser', 'mmi-po' ); ?></h3>
			<button type="button" class="mmi-modal-close" data-close aria-label="<?php esc_attr_e( 'Close', 'mmi-po' ); ?>">&times;</button>
		</div>
		<div class="mmi-po-rb-body">
			<form class="mmi-po-rb-config" id="mmi-po-rb-form" autocomplete="off">
				<div class="mmi-po-rb-config-head"><?php esc_html_e( 'Configure Rates', 'mmi-po' ); ?></div>
				<div class="mmi-po-rb-scroll">
					<div class="mmi-po-rb-field">
						<label for="mmi-po-rb-from"><?php esc_html_e( 'Ship From Postal Code', 'mmi-po' ); ?> <span class="mmi-po-rb-hint" id="mmi-po-rb-from-name"></span></label>
						<input type="text" id="mmi-po-rb-from" data-q="from_postal">
					</div>

					<h4><?php esc_html_e( 'Ship To', 'mmi-po' ); ?></h4>
					<div class="mmi-po-rb-field">
						<label for="mmi-po-rb-country"><?php esc_html_e( 'Country', 'mmi-po' ); ?></label>
						<select id="mmi-po-rb-country" data-q="to_country">
							<?php foreach ( $mmi_po_countries as $mmi_po_code => $mmi_po_name ) : ?>
								<option value="<?php echo esc_attr( $mmi_po_code ); ?>"><?php echo esc_html( html_entity_decode( $mmi_po_name, ENT_QUOTES, 'UTF-8' ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="mmi-po-rb-field">
						<label for="mmi-po-rb-to"><?php esc_html_e( 'Postal Code', 'mmi-po' ); ?> <span class="mmi-po-rb-hint" id="mmi-po-rb-to-name"></span></label>
						<input type="text" id="mmi-po-rb-to" data-q="to_postal">
					</div>
					<label class="mmi-po-inline"><input type="checkbox" data-q="residential"> <?php esc_html_e( 'Residential Address', 'mmi-po' ); ?></label>

					<h4><?php esc_html_e( 'Shipment Information', 'mmi-po' ); ?></h4>
					<div class="mmi-po-rb-field">
						<span class="mmi-po-rb-label"><?php esc_html_e( 'Weight', 'mmi-po' ); ?></span>
						<div class="mmi-po-rb-row">
							<input type="number" min="0" step="1" data-qw="lb" aria-label="<?php esc_attr_e( 'Pounds', 'mmi-po' ); ?>"><span>(lb)</span>
							<input type="number" min="0" step="0.1" data-qw="oz" aria-label="<?php esc_attr_e( 'Ounces', 'mmi-po' ); ?>"><span>(oz)</span>
						</div>
					</div>
					<div class="mmi-po-rb-field">
						<label for="mmi-po-rb-package"><?php esc_html_e( 'Package', 'mmi-po' ); ?></label>
						<select id="mmi-po-rb-package" data-q="package_code">
							<option value=""><?php esc_html_e( 'All Package Types', 'mmi-po' ); ?></option>
							<option value="package"><?php esc_html_e( 'Package', 'mmi-po' ); ?></option>
						</select>
					</div>
					<div class="mmi-po-rb-field">
						<span class="mmi-po-rb-label"><?php esc_html_e( 'Size', 'mmi-po' ); ?></span>
						<div class="mmi-po-rb-row">
							<input type="number" min="0" step="0.5" data-q="length" aria-label="<?php esc_attr_e( 'Length', 'mmi-po' ); ?>"><span>L</span>
							<input type="number" min="0" step="0.5" data-q="width" aria-label="<?php esc_attr_e( 'Width', 'mmi-po' ); ?>"><span>W</span>
							<input type="number" min="0" step="0.5" data-q="height" aria-label="<?php esc_attr_e( 'Height', 'mmi-po' ); ?>"><span>H (in)</span>
						</div>
					</div>
					<div class="mmi-po-rb-field">
						<label for="mmi-po-rb-confirmation"><?php esc_html_e( 'Confirmation', 'mmi-po' ); ?></label>
						<select id="mmi-po-rb-confirmation" data-q="confirmation">
							<?php foreach ( MMI_PO_Shipping::CONFIRMATIONS as $mmi_po_key => $mmi_po_label ) : ?>
								<option value="<?php echo esc_attr( $mmi_po_key ); ?>"><?php echo esc_html( $mmi_po_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="mmi-po-rb-field">
						<label for="mmi-po-rb-class"><?php esc_html_e( 'Service Class', 'mmi-po' ); ?></label>
						<select id="mmi-po-rb-class">
							<option value=""><?php esc_html_e( 'Show All', 'mmi-po' ); ?></option>
							<option value="ground"><?php esc_html_e( 'Ground / Economy', 'mmi-po' ); ?></option>
							<option value="3day"><?php esc_html_e( '3-Day', 'mmi-po' ); ?></option>
							<option value="2day"><?php esc_html_e( '2-Day', 'mmi-po' ); ?></option>
							<option value="overnight"><?php esc_html_e( 'Overnight', 'mmi-po' ); ?></option>
						</select>
					</div>
				</div>
				<div class="mmi-po-rb-config-foot">
					<button type="submit" class="button button-primary" id="mmi-po-rb-browse"><?php esc_html_e( 'Browse Rates', 'mmi-po' ); ?></button>
				</div>
			</form>

			<section class="mmi-po-rb-results">
				<div class="mmi-po-rb-results-head">
					<h3><?php esc_html_e( 'Rates', 'mmi-po' ); ?></h3>
					<span class="mmi-po-rb-available" id="mmi-po-rb-available"></span>
					<label class="mmi-po-rb-viewby"><?php esc_html_e( 'View By:', 'mmi-po' ); ?>
						<select id="mmi-po-rb-viewby">
							<option value="carriers"><?php esc_html_e( 'Carriers', 'mmi-po' ); ?></option>
							<option value="price"><?php esc_html_e( 'Price', 'mmi-po' ); ?></option>
						</select>
					</label>
				</div>
				<div class="mmi-po-rb-columns" id="mmi-po-rb-columns">
					<nav class="mmi-po-rb-carriers" id="mmi-po-rb-carriers" aria-label="<?php esc_attr_e( 'Carriers', 'mmi-po' ); ?>"></nav>
					<div class="mmi-po-rb-rates">
						<div class="mmi-po-rb-rates-head"><strong id="mmi-po-rb-rates-title"></strong><span><?php esc_html_e( 'Estimated Rates', 'mmi-po' ); ?></span></div>
						<div class="mmi-po-rb-rate-list" id="mmi-po-rb-rate-list" role="listbox" aria-label="<?php esc_attr_e( 'Rates', 'mmi-po' ); ?>"></div>
					</div>
				</div>
			</section>
		</div>
		<div class="mmi-modal-footer mmi-po-rb-foot">
			<div class="mmi-po-rb-selection" id="mmi-po-rb-selection"></div>
			<div class="mmi-modal-error" id="mmi-po-rb-error" hidden></div>
			<button type="button" class="button" data-close><?php esc_html_e( 'Close', 'mmi-po' ); ?></button>
			<button type="button" class="button button-primary" id="mmi-po-rb-buy" disabled><?php esc_html_e( 'Configure Label', 'mmi-po' ); ?> <span class="dashicons dashicons-arrow-right-alt"></span></button>
			<button type="button" class="button button-primary" id="mmi-po-rb-confirm" hidden><span class="dashicons dashicons-cart"></span> <span id="mmi-po-rb-confirm-text"></span></button>
		</div>
	</div>
</div>
