<?php
/**
 * Operations record across every PO.
 *
 * @var callable $mmi_po_section_head
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mmi_po_page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification -- read-only.
$mmi_po_log   = MMI_PO_Log::query( 0, $mmi_po_page, MMI_PO_Admin::PER_PAGE );
$mmi_po_pages = (int) ceil( $mmi_po_log['total'] / MMI_PO_Admin::PER_PAGE );
?>
<div class="mmi-process-section">
	<?php $mmi_po_section_head( 'backup', __( 'Activity', 'mmi-po' ), __( 'Every create, save, export, email and status change, newest first. Emailed rows link to the exact PDF that was sent.', 'mmi-po' ) ); ?>
	<div class="mmi-section-content">
		<?php if ( ! $mmi_po_log['rows'] ) : ?>
			<p class="mmi-po-empty"><?php esc_html_e( 'Nothing recorded yet.', 'mmi-po' ); ?></p>
		<?php else : ?>
			<div class="mmi-table-scroll-wrapper">
				<table class="widefat striped mmi-po-table" id="mmi-po-activity" data-mmi-resizable>
					<thead>
						<tr>
							<th class="sortable mmi-po-col-date" data-sort-key="when"><?php esc_html_e( 'When', 'mmi-po' ); ?></th>
							<th class="sortable mmi-po-col-number" data-sort-key="po"><?php esc_html_e( 'PO #', 'mmi-po' ); ?></th>
							<th class="sortable mmi-po-col-status" data-sort-key="action"><?php esc_html_e( 'Action', 'mmi-po' ); ?></th>
							<th class="sortable" data-sort-key="details"><?php esc_html_e( 'Details', 'mmi-po' ); ?></th>
							<th class="sortable" data-sort-key="user"><?php esc_html_e( 'By', 'mmi-po' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $mmi_po_log['rows'] as $row ) : ?>
							<?php
							$number = $row['po_number'] ? $row['po_number'] : ( $row['meta']['po_number'] ?? '' );
							$file   = $row['meta']['file'] ?? '';
							?>
							<tr>
								<td data-sort-key="when" data-sort-value="<?php echo esc_attr( $row['created_at'] ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row['created_at'] ) ) ); ?></td>
								<td data-sort-key="po">
									<?php if ( $row['po_number'] ) : ?>
										<a href="<?php echo esc_url( MMI_PO_Admin::url( array( 'tab' => 'edit', 'po' => $row['po_id'] ) ) ); ?>"><?php echo esc_html( $number ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $number ? $number : '—' ); ?>
									<?php endif; ?>
								</td>
								<td data-sort-key="action"><?php echo esc_html( $row['label'] ); ?></td>
								<td data-sort-key="details">
									<?php echo esc_html( (string) $row['message'] ); ?>
									<?php if ( $file ) : ?>
										<a class="mmi-po-file-link" target="_blank" rel="noopener" href="<?php echo esc_url( MMI_PO_Admin::stored_file_url( $file ) ); ?>"><span class="dashicons dashicons-media-document"></span> <?php esc_html_e( 'PDF sent', 'mmi-po' ); ?></a>
									<?php endif; ?>
								</td>
								<td data-sort-key="user"><?php echo esc_html( $row['user_name'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $mmi_po_pages > 1 ) : ?>
				<div class="mmi-po-pager">
					<?php
					echo wp_kses_post( paginate_links( array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $mmi_po_page,
						'total'   => $mmi_po_pages,
					) ) );
					?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
