<?php
/**
 * Settings: buyer identity and logo, PO numbering, email template.
 *
 * @var callable $mmi_po_section_head
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mmi_po_s = MMI_PO_Settings::all();
?>
<?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification -- display only. ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'mmi-po' ); ?></p></div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="mmi_po_save_settings">
	<?php wp_nonce_field( MMI_PO_Admin::NONCE_ACTION ); ?>

	<div class="mmi-process-section">
		<?php $mmi_po_section_head( 'id', __( 'Your business on the PO', 'mmi-po' ), __( 'The logo, name and contact line printed on every purchase order.', 'mmi-po' ) ); ?>
		<div class="mmi-section-content">
			<div class="mmi-po-grid">
				<div class="mmi-po-field">
					<label><?php esc_html_e( 'Logo', 'mmi-po' ); ?></label>
					<div class="mmi-po-logo-preview"><img id="mmi-po-logo-img" src="<?php echo esc_url( MMI_PO_Settings::logo_url() ); ?>" alt=""></div>
					<input type="hidden" name="logo_id" id="mmi-po-logo-id" value="<?php echo esc_attr( (string) $mmi_po_s['logo_id'] ); ?>">
					<button type="button" class="button" id="mmi-po-logo-pick"><?php esc_html_e( 'Choose logo…', 'mmi-po' ); ?></button>
					<button type="button" class="button-link" id="mmi-po-logo-reset" data-default="<?php echo esc_url( MMI_PO_URL . 'assets/img/default-logo.png' ); ?>"><?php esc_html_e( 'Use bundled logo', 'mmi-po' ); ?></button>
					<p class="mmi-hint-text"><?php esc_html_e( 'The bundled logo was cropped from a screenshot of the old Numbers template. A clean PNG from your brand files will print sharper.', 'mmi-po' ); ?></p>
				</div>
				<div class="mmi-po-field">
					<label for="mmi-po-buyer-name"><?php esc_html_e( 'Business name', 'mmi-po' ); ?></label>
					<input type="text" id="mmi-po-buyer-name" name="buyer_name" value="<?php echo esc_attr( $mmi_po_s['buyer_name'] ); ?>">
					<label for="mmi-po-buyer-address"><?php esc_html_e( 'Business address', 'mmi-po' ); ?></label>
					<textarea id="mmi-po-buyer-address" name="buyer_address" rows="3"><?php echo esc_textarea( $mmi_po_s['buyer_address'] ); ?></textarea>
				</div>
				<div class="mmi-po-field">
					<label for="mmi-po-buyer-phone"><?php esc_html_e( 'Phone', 'mmi-po' ); ?></label>
					<input type="text" id="mmi-po-buyer-phone" name="buyer_phone" value="<?php echo esc_attr( $mmi_po_s['buyer_phone'] ); ?>">
					<label for="mmi-po-buyer-email"><?php esc_html_e( 'Purchasing email (printed)', 'mmi-po' ); ?></label>
					<input type="email" id="mmi-po-buyer-email" name="buyer_email" value="<?php echo esc_attr( $mmi_po_s['buyer_email'] ); ?>">
				</div>
				<div class="mmi-po-field mmi-po-field--wide">
					<span class="mmi-po-label"><?php esc_html_e( 'Default "Deliver To" (your receiving address, also the ship-to on inbound labels)', 'mmi-po' ); ?></span>
					<div class="mmi-po-address-grid">
						<?php
						$mmi_po_addr = $mmi_po_s['store_address'];
						foreach ( array( 'name' => __( 'Attn / name', 'mmi-po' ), 'company' => __( 'Company', 'mmi-po' ), 'street1' => __( 'Street', 'mmi-po' ), 'street2' => __( 'Street line 2', 'mmi-po' ), 'city' => __( 'City', 'mmi-po' ), 'state' => __( 'State', 'mmi-po' ), 'postal_code' => __( 'ZIP', 'mmi-po' ), 'country' => __( 'Country', 'mmi-po' ), 'phone' => __( 'Phone', 'mmi-po' ) ) as $mmi_po_key => $mmi_po_label ) :
							?>
							<label class="mmi-po-addr-<?php echo esc_attr( $mmi_po_key ); ?>"><span><?php echo esc_html( $mmi_po_label ); ?></span><input type="text" name="store_address[<?php echo esc_attr( $mmi_po_key ); ?>]" value="<?php echo esc_attr( (string) ( $mmi_po_addr[ $mmi_po_key ] ?? '' ) ); ?>"></label>
						<?php endforeach; ?>
						<label class="mmi-po-inline mmi-po-addr-residential"><input type="checkbox" name="store_address[residential]" value="1" <?php checked( ! empty( $mmi_po_addr['residential'] ) ); ?>> <?php esc_html_e( 'Residential address', 'mmi-po' ); ?></label>
					</div>
				</div>
				<div class="mmi-po-field mmi-po-field--wide">
					<label for="mmi-po-footer"><?php esc_html_e( 'Footer note (terms, tax-exempt/resale number, etc.)', 'mmi-po' ); ?></label>
					<textarea id="mmi-po-footer" name="footer_note" rows="2"><?php echo esc_textarea( $mmi_po_s['footer_note'] ); ?></textarea>
				</div>
			</div>
		</div>
	</div>

	<div class="mmi-process-section">
		<?php $mmi_po_section_head( 'editor-ol', __( 'PO numbers & defaults', 'mmi-po' ), __( 'Numbers are assigned when a PO is first saved and never reused.', 'mmi-po' ) ); ?>
		<div class="mmi-section-content">
			<div class="mmi-po-grid">
				<div class="mmi-po-field">
					<label for="mmi-po-prefix"><?php esc_html_e( 'Prefix', 'mmi-po' ); ?></label>
					<input type="text" id="mmi-po-prefix" name="number_prefix" value="<?php echo esc_attr( $mmi_po_s['number_prefix'] ); ?>">
				</div>
				<div class="mmi-po-field">
					<label for="mmi-po-next"><?php esc_html_e( 'Next number', 'mmi-po' ); ?></label>
					<input type="number" id="mmi-po-next" name="next_number" min="1" list="mmi-po-next-orders" value="<?php echo esc_attr( (string) MMI_PO_Settings::next_number() ); ?>">
					<datalist id="mmi-po-next-orders">
						<?php foreach ( MMI_PO_Orders::recent_wc_orders() as $mmi_po_o ) : ?>
							<?php if ( ctype_digit( $mmi_po_o['number'] ) ) : ?>
								<option value="<?php echo esc_attr( $mmi_po_o['number'] ); ?>"><?php echo esc_html( sprintf( 'Order #%s · %s · %s · %s', $mmi_po_o['number'], $mmi_po_o['date'], $mmi_po_o['name'], $mmi_po_o['total'] ) ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</datalist>
					<p class="mmi-hint-text"><?php esc_html_e( 'Continue your old Numbers-template sequence, or pick a recent WooCommerce order number to line PO numbers up with orders. Each PO can also take its own order number in the editor.', 'mmi-po' ); ?></p>
				</div>
				<div class="mmi-po-field mmi-po-field--wide">
					<label for="mmi-po-default-instr"><?php esc_html_e( 'Default instructions when a supplier has none', 'mmi-po' ); ?></label>
					<textarea id="mmi-po-default-instr" name="default_instructions" rows="2"><?php echo esc_textarea( $mmi_po_s['default_instructions'] ); ?></textarea>
				</div>
			</div>
		</div>
	</div>

	<div class="mmi-process-section">
		<?php $mmi_po_section_head( 'email-alt', __( 'Email', 'mmi-po' ), __( 'Prefilled into the Send dialog, where every field can still be edited before sending. Placeholders: {po_number} {po_date} {buyer_name} {supplier_name} {supplier_contact} {item_count} {total}', 'mmi-po' ) ); ?>
		<div class="mmi-section-content">
			<div class="mmi-po-grid">
				<div class="mmi-po-field mmi-po-field--wide">
					<label for="mmi-po-subject"><?php esc_html_e( 'Subject', 'mmi-po' ); ?></label>
					<input type="text" id="mmi-po-subject" name="email_subject" value="<?php echo esc_attr( $mmi_po_s['email_subject'] ); ?>">
				</div>
				<div class="mmi-po-field mmi-po-field--wide">
					<label for="mmi-po-body"><?php esc_html_e( 'Message', 'mmi-po' ); ?></label>
					<textarea id="mmi-po-body" name="email_body" rows="8"><?php echo esc_textarea( $mmi_po_s['email_body'] ); ?></textarea>
				</div>
				<div class="mmi-po-field">
					<label for="mmi-po-reply"><?php esc_html_e( 'Reply-To', 'mmi-po' ); ?></label>
					<input type="email" id="mmi-po-reply" name="reply_to" value="<?php echo esc_attr( $mmi_po_s['reply_to'] ); ?>">
					<p class="mmi-hint-text"><?php esc_html_e( 'The From address is set by the site\'s mail plugin (WP Mail SMTP); supplier replies go to this address.', 'mmi-po' ); ?></p>
				</div>
				<div class="mmi-po-field">
					<label for="mmi-po-cc"><?php esc_html_e( 'Always Cc', 'mmi-po' ); ?></label>
					<input type="text" id="mmi-po-cc" name="default_cc" value="<?php echo esc_attr( $mmi_po_s['default_cc'] ); ?>">
					<label class="mmi-po-inline"><input type="checkbox" name="bcc_self" value="1" <?php checked( ! empty( $mmi_po_s['bcc_self'] ) ); ?>> <?php esc_html_e( 'Bcc me on every PO I send', 'mmi-po' ); ?></label>
				</div>
			</div>
		</div>
	</div>

	<?php $mmi_po_ss = MMI_PO_ShipStation::settings(); ?>
	<div class="mmi-process-section">
		<?php
		$mmi_po_section_head(
			'car',
			__( 'ShipStation', 'mmi-po' ),
			__( 'API credentials from ShipStation → Settings → Account → API Settings. Rates are free; buying a label charges postage to your ShipStation account.', 'mmi-po' ),
			sprintf( '<button type="button" class="button" id="mmi-po-ss-test"><span class="dashicons dashicons-yes-alt"></span> %s</button>', esc_html__( 'Test connection', 'mmi-po' ) )
		);
		?>
		<div class="mmi-section-content">
			<div class="mmi-po-grid">
				<div class="mmi-po-field">
					<label for="mmi-po-ss-key"><?php esc_html_e( 'API key', 'mmi-po' ); ?></label>
					<input type="text" id="mmi-po-ss-key" name="ss_api_key" autocomplete="off" placeholder="<?php echo esc_attr( $mmi_po_ss['api_key'] !== '' ? __( 'Saved — leave blank to keep', 'mmi-po' ) : '' ); ?>">
				</div>
				<div class="mmi-po-field">
					<label for="mmi-po-ss-secret"><?php esc_html_e( 'API secret', 'mmi-po' ); ?></label>
					<input type="password" id="mmi-po-ss-secret" name="ss_api_secret" autocomplete="new-password" placeholder="<?php echo esc_attr( $mmi_po_ss['api_secret'] !== '' ? __( 'Saved (encrypted) — leave blank to keep', 'mmi-po' ) : '' ); ?>">
					<?php if ( MMI_PO_ShipStation::is_configured() ) : ?>
						<label class="mmi-po-inline"><input type="checkbox" name="ss_forget" value="1"> <?php esc_html_e( 'Remove saved credentials', 'mmi-po' ); ?></label>
					<?php endif; ?>
				</div>
				<div class="mmi-po-field">
					<?php $mmi_po_exp = MMI_PO_ShipStation::expiry(); ?>
					<label for="mmi-po-ss-expires"><?php esc_html_e( 'API key expires on', 'mmi-po' ); ?></label>
					<input type="date" id="mmi-po-ss-expires" name="ss_expires_on" value="<?php echo esc_attr( $mmi_po_ss['expires_on'] ); ?>">
					<?php if ( $mmi_po_exp['level'] !== 'none' ) : ?>
						<span class="mmi-badge <?php echo esc_attr( array( 'ok' => 'success', 'warning' => 'warning', 'expired' => 'error' )[ $mmi_po_exp['level'] ] ); ?>"><?php echo esc_html( $mmi_po_exp['level'] === 'expired' ? __( 'Expired', 'mmi-po' ) : sprintf( /* translators: %d: days */ _n( '%d day left', '%d days left', $mmi_po_exp['days_left'], 'mmi-po' ), $mmi_po_exp['days_left'] ) ); ?></span>
					<?php endif; ?>
					<p class="mmi-hint-text">
						<?php
						echo esc_html( $mmi_po_ss['saved_on'] !== ''
							? sprintf( /* translators: %s: date */ __( 'ShipStation API keys last 12 months. Key saved %s; the date is set 12 months out automatically whenever new credentials are saved. Correct it here if ShipStation shows a different date.', 'mmi-po' ), date_i18n( get_option( 'date_format' ), strtotime( $mmi_po_ss['saved_on'] ) ) )
							: __( 'ShipStation API keys last 12 months. The expiry is set 12 months out when you save new credentials; you will get a MannMade dashboard alert 30 days before.', 'mmi-po' ) );
						?>
					</p>
				</div>
				<div class="mmi-po-field mmi-po-field--wide">
					<span class="mmi-po-label"><?php esc_html_e( 'Carriers to quote (none ticked = all)', 'mmi-po' ); ?></span>
					<div class="mmi-po-carriers" id="mmi-po-ss-carriers" data-selected="<?php echo esc_attr( wp_json_encode( $mmi_po_ss['carriers'] ) ); ?>">
						<?php foreach ( $mmi_po_ss['carriers'] as $mmi_po_code ) : ?>
							<label class="mmi-po-inline"><input type="checkbox" name="ss_carriers[]" value="<?php echo esc_attr( $mmi_po_code ); ?>" checked> <?php echo esc_html( $mmi_po_code ); ?></label>
						<?php endforeach; ?>
					</div>
					<p class="mmi-hint-text" id="mmi-po-ss-status"><?php esc_html_e( 'Use "Test connection" to load the carriers connected to your ShipStation account.', 'mmi-po' ); ?></p>
				</div>
			</div>
		</div>
	</div>

	<p><button type="submit" class="button button-primary"><span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save settings', 'mmi-po' ); ?></button></p>
</form>
