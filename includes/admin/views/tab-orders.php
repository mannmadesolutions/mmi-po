<?php
/**
 * Purchase order list: status tiles, filters, table.
 *
 * @var callable $mmi_po_section_head
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification -- read-only list filters.
$mmi_po_filters = array(
	'status'      => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
	'supplier_id' => isset( $_GET['supplier'] ) ? absint( $_GET['supplier'] ) : 0,
	'search'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
	'page'        => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
	'per_page'    => MMI_PO_Admin::PER_PAGE,
);
// phpcs:enable
$mmi_po_result    = MMI_PO_Orders::query( $mmi_po_filters );
$mmi_po_suppliers = MMI_PO_Suppliers::all();
$mmi_po_pages     = (int) ceil( $mmi_po_result['total'] / MMI_PO_Admin::PER_PAGE );
?>
<div class="mmi-stats-grid">
	<?php foreach ( MMI_PO_Orders::STATUSES as $key => $label ) : ?>
		<a class="mmi-stat-box <?php echo esc_attr( MMI_PO_Orders::STATUS_BADGES[ $key ] ); ?> inline mmi-po-stat" href="<?php echo esc_url( MMI_PO_Admin::url( array( 'status' => $key ) ) ); ?>">
			<div class="mmi-stat-label"><?php echo esc_html( $label ); ?></div>
			<div class="mmi-stat-value"><?php echo esc_html( number_format_i18n( $mmi_po_result['counts'][ $key ] ) ); ?></div>
		</a>
	<?php endforeach; ?>
</div>

<div class="mmi-process-section">
	<?php
	$mmi_po_section_head(
		'clipboard',
		__( 'Purchase orders', 'mmi-po' ),
		__( 'Newest first. Search matches PO numbers, suppliers, item numbers, SKUs and descriptions.', 'mmi-po' ),
		sprintf( '<a class="button button-primary" href="%s"><span class="dashicons dashicons-plus-alt2"></span> %s</a>', esc_url( MMI_PO_Admin::url( array( 'tab' => 'edit' ) ) ), esc_html__( 'New PO', 'mmi-po' ) )
	);
	?>
	<div class="mmi-section-content">
		<form class="mmi-toolbar" method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( MMI_PO_Admin::PAGE_SLUG ); ?>">
			<input type="search" name="s" value="<?php echo esc_attr( $mmi_po_filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search POs…', 'mmi-po' ); ?>" aria-label="<?php esc_attr_e( 'Search POs', 'mmi-po' ); ?>">
			<select name="status" aria-label="<?php esc_attr_e( 'Status', 'mmi-po' ); ?>">
				<option value=""><?php esc_html_e( 'Any status', 'mmi-po' ); ?></option>
				<?php foreach ( MMI_PO_Orders::STATUSES as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $mmi_po_filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="supplier" aria-label="<?php esc_attr_e( 'Supplier', 'mmi-po' ); ?>">
				<option value="0"><?php esc_html_e( 'Any supplier', 'mmi-po' ); ?></option>
				<?php foreach ( $mmi_po_suppliers as $supplier ) : ?>
					<option value="<?php echo esc_attr( $supplier['id'] ); ?>" <?php selected( $mmi_po_filters['supplier_id'], $supplier['id'] ); ?>><?php echo esc_html( $supplier['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><span class="dashicons dashicons-filter"></span> <?php esc_html_e( 'Filter', 'mmi-po' ); ?></button>
			<?php if ( $mmi_po_filters['status'] || $mmi_po_filters['supplier_id'] || $mmi_po_filters['search'] ) : ?>
				<a class="button-link" href="<?php echo esc_url( MMI_PO_Admin::url() ); ?>"><?php esc_html_e( 'Clear', 'mmi-po' ); ?></a>
			<?php endif; ?>
		</form>

		<?php if ( ! $mmi_po_result['rows'] ) : ?>
			<p class="mmi-po-empty">
				<?php
				echo $mmi_po_result['total'] === 0 && ! array_filter( array( $mmi_po_filters['status'], $mmi_po_filters['supplier_id'], $mmi_po_filters['search'] ) )
					? esc_html__( 'No purchase orders yet. Use "New PO" to create the first one.', 'mmi-po' )
					: esc_html__( 'No purchase orders match these filters.', 'mmi-po' );
				?>
			</p>
		<?php else : ?>
			<div class="mmi-table-scroll-wrapper">
				<table class="widefat striped mmi-po-table" id="mmi-po-orders" data-mmi-resizable>
					<thead>
						<tr>
							<th class="sortable mmi-po-col-number" data-sort-key="number"><?php esc_html_e( 'PO #', 'mmi-po' ); ?></th>
							<th class="sortable mmi-po-col-date" data-sort-key="date"><?php esc_html_e( 'Date', 'mmi-po' ); ?></th>
							<th class="sortable" data-sort-key="supplier"><?php esc_html_e( 'Supplier', 'mmi-po' ); ?></th>
							<th class="sortable mmi-po-col-num" data-sort-key="lines"><?php esc_html_e( 'Lines', 'mmi-po' ); ?></th>
							<th class="sortable mmi-po-col-money" data-sort-key="total"><?php esc_html_e( 'Total', 'mmi-po' ); ?></th>
							<th class="sortable mmi-po-col-status" data-sort-key="status"><?php esc_html_e( 'Status', 'mmi-po' ); ?></th>
							<th class="sortable mmi-po-col-date" data-sort-key="sent"><?php esc_html_e( 'Emailed', 'mmi-po' ); ?></th>
							<th class="mmi-po-col-actions"><?php esc_html_e( 'Actions', 'mmi-po' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $mmi_po_result['rows'] as $row ) : ?>
							<tr>
								<td data-sort-key="number" data-sort-value="<?php echo esc_attr( $row['id'] ); ?>"><a class="mmi-po-number" href="<?php echo esc_url( MMI_PO_Admin::url( array( 'tab' => 'edit', 'po' => $row['id'] ) ) ); ?>"><?php echo esc_html( $row['po_number'] ); ?></a></td>
								<td data-sort-key="date" data-sort-value="<?php echo esc_attr( $row['po_date'] ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row['po_date'] ) ) ); ?></td>
								<td data-sort-key="supplier"><?php echo esc_html( $row['supplier_name'] ? $row['supplier_name'] : '—' ); ?></td>
								<td data-sort-key="lines" class="mmi-po-col-num"><?php echo esc_html( $row['item_count'] ); ?></td>
								<td data-sort-key="total" data-sort-value="<?php echo esc_attr( $row['total'] ); ?>" class="mmi-po-col-money"><?php echo esc_html( MMI_PO_Orders::money( (float) $row['total'] ) ); ?></td>
								<td data-sort-key="status"><span class="mmi-badge <?php echo esc_attr( MMI_PO_Orders::STATUS_BADGES[ $row['status'] ] ?? 'info' ); ?>"><?php echo esc_html( MMI_PO_Orders::STATUSES[ $row['status'] ] ?? $row['status'] ); ?></span></td>
								<td data-sort-key="sent" data-sort-value="<?php echo esc_attr( (string) $row['sent_at'] ); ?>" title="<?php echo esc_attr( (string) $row['sent_to'] ); ?>"><?php echo $row['sent_at'] ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row['sent_at'] ) ) ) : '—'; ?></td>
								<td class="mmi-po-row-actions">
									<a class="button button-small" href="<?php echo esc_url( MMI_PO_Admin::url( array( 'tab' => 'edit', 'po' => $row['id'] ) ) ); ?>"><?php esc_html_e( 'Open', 'mmi-po' ); ?></a>
									<a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( MMI_PO_Admin::pdf_url( (int) $row['id'], false ) ); ?>"><?php esc_html_e( 'PDF', 'mmi-po' ); ?></a>
									<button type="button" class="button button-small mmi-po-duplicate" data-id="<?php echo esc_attr( $row['id'] ); ?>"><?php esc_html_e( 'Duplicate', 'mmi-po' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $mmi_po_pages > 1 ) : ?>
				<div class="mmi-po-pager">
					<?php
					echo wp_kses_post( paginate_links( array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $mmi_po_filters['page'],
						'total'     => $mmi_po_pages,
						'prev_text' => '‹',
						'next_text' => '›',
					) ) );
					?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
