<?php
/**
 * Printable purchase order, laid out after the Numbers template it replaces.
 * Rendered to PDF by Dompdf; styles come from assets/css/po-document.css.
 *
 * @var array  $order    Order row with 'items'.
 * @var array  $supplier Supplier as printed (snapshot once sent).
 * @var array  $settings MMI_PO_Settings::all().
 * @var string $logo     Data URI or ''.
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mmi_po_money = static function ( $amount ) {
	return esc_html( MMI_PO_Orders::money( (float) $amount ) );
};
$mmi_po_lines = static function ( $text ) {
	return nl2br( esc_html( trim( (string) $text ) ) );
};
$mmi_po_date  = date_i18n( 'F j, Y', strtotime( $order['po_date'] ) );
$mmi_po_ship  = trim( (string) $order['ship_to'] ) !== '' ? $order['ship_to'] : MMI_PO_Address::format( $settings['store_address'] );
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title><?php echo esc_html( $order['po_number'] ); ?></title>
<style><?php echo file_get_contents( MMI_PO_PATH . 'assets/css/po-document.css' ); // phpcs:ignore -- plugin-owned stylesheet. ?></style>
</head>
<body>

<table class="po-head">
	<tr>
		<td class="po-head-logo">
			<?php if ( $logo ) : ?>
				<img src="<?php echo esc_attr( $logo ); ?>" alt="<?php echo esc_attr( $settings['buyer_name'] ); ?>">
			<?php else : ?>
				<div class="po-buyer-name"><?php echo esc_html( $settings['buyer_name'] ); ?></div>
			<?php endif; ?>
		</td>
		<td class="po-head-title">
			<div class="po-title">PURCHASE ORDER</div>
			<table class="po-meta">
				<tr><th>Date</th><td><?php echo esc_html( $mmi_po_date ); ?></td></tr>
				<tr><th>PO#</th><td class="po-number"><?php echo esc_html( $order['po_number'] ); ?></td></tr>
				<?php if ( ! empty( $supplier['account_number'] ) ) : ?>
					<tr><th>Account #</th><td><?php echo esc_html( $supplier['account_number'] ); ?></td></tr>
				<?php endif; ?>
			</table>
		</td>
	</tr>
</table>

<table class="po-parties">
	<tr>
		<th class="po-party-label">Purchase From</th>
		<th class="po-party-label">Deliver To</th>
	</tr>
	<tr>
		<td class="po-party">
			<div class="po-party-name"><?php echo esc_html( $supplier['name'] ); ?></div>
			<?php if ( ! empty( $supplier['contact_name'] ) ) : ?>
				<div>Attn: <?php echo esc_html( $supplier['contact_name'] ); ?></div>
			<?php endif; ?>
			<div><?php echo $mmi_po_lines( $supplier['address'] ?? '' ); // phpcs:ignore -- escaped in closure. ?></div>
			<?php if ( ! empty( $supplier['phone'] ) ) : ?>
				<div><?php echo esc_html( $supplier['phone'] ); ?></div>
			<?php endif; ?>
		</td>
		<td class="po-party">
			<div><?php echo $mmi_po_lines( $mmi_po_ship ); // phpcs:ignore -- escaped in closure. ?></div>
		</td>
	</tr>
</table>

<?php if ( $label ) : ?>
	<div class="po-instructions">PREPAID LABEL ENCLOSED — <?php echo esc_html( $label['service_name'] ); ?><?php echo $label['tracking_number'] !== '' ? esc_html( ', TRACKING ' . $label['tracking_number'] ) : ''; ?><?php echo $label['is_test'] ? ' (TEST LABEL — DO NOT USE)' : ''; ?></div>
<?php elseif ( ( $order['label_mode'] ?? '' ) !== 'ours' && ! empty( $order['wc_order_id'] ) ) : ?>
	<div class="po-instructions">DROP SHIP — PLEASE EMAIL TRACKING</div>
<?php endif; ?>
<?php if ( trim( (string) $order['instructions'] ) !== '' ) : ?>
	<div class="po-instructions"><?php echo $mmi_po_lines( $order['instructions'] ); // phpcs:ignore -- escaped in closure. ?></div>
<?php endif; ?>

<table class="po-items">
	<thead>
		<tr>
			<th class="po-col-item">Item #</th>
			<th class="po-col-desc">Description</th>
			<th class="po-col-qty">Qty</th>
			<th class="po-col-money">Unit Cost</th>
			<th class="po-col-money">Amount</th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $order['items'] as $item ) : ?>
			<tr>
				<td class="po-col-item"><?php echo esc_html( $item['item_number'] ); ?></td>
				<td class="po-col-desc"><?php echo $mmi_po_lines( $item['description'] ); // phpcs:ignore -- escaped in closure. ?></td>
				<td class="po-col-qty"><?php echo esc_html( (string) (int) $item['qty'] ); ?></td>
				<td class="po-col-money"><?php echo $mmi_po_money( $item['unit_cost'] ); // phpcs:ignore -- escaped in closure. ?></td>
				<td class="po-col-money"><?php echo $mmi_po_money( $item['line_total'] ); // phpcs:ignore -- escaped in closure. ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<table class="po-totals">
	<tr><th>Sales Subtotal</th><td><?php echo $mmi_po_money( $order['subtotal'] ); // phpcs:ignore ?></td></tr>
	<tr><th>Tax</th><td><?php echo $mmi_po_money( $order['tax'] ); // phpcs:ignore ?></td></tr>
	<?php if ( (float) $order['shipping'] > 0 ) : ?>
		<tr><th>Shipping</th><td><?php echo $mmi_po_money( $order['shipping'] ); // phpcs:ignore ?></td></tr>
	<?php endif; ?>
	<tr class="po-total"><th>Total</th><td><?php echo $mmi_po_money( $order['total'] ); // phpcs:ignore ?></td></tr>
</table>

<div class="po-footer">
	<?php if ( trim( (string) $settings['footer_note'] ) !== '' ) : ?>
		<p><?php echo $mmi_po_lines( $settings['footer_note'] ); // phpcs:ignore -- escaped in closure. ?></p>
	<?php endif; ?>
	<p class="po-buyer">
		<?php
		echo esc_html( implode( ' · ', array_filter( array(
			$settings['buyer_name'],
			implode( ', ', array_filter( array_map( 'trim', preg_split( '/\R/', (string) $settings['buyer_address'] ) ), 'strlen' ) ),
			$settings['buyer_phone'],
			$settings['buyer_email'],
		) ) ) );
		?>
	</p>
</div>

</body>
</html>
