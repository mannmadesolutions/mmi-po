<?php
/**
 * Emails a PO to its supplier with the PDF attached. Uses the site's own wp_mail() transport —
 * no email provider of its own. Sending only ever happens from an
 * explicit click in the Send dialog; nothing in this plugin emails a supplier automatically.
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_PO_Mailer {

	const MAX_RECIPIENTS = 10;

	/** Per-user send cap: far above real PO volume, low enough that a misused account can't mass-mail. */
	const MAX_SENDS_PER_HOUR = 30;
	const SEND_COUNT_PREFIX  = 'mmi_po_sends_';

	/** Prefilled Send dialog for a PO. */
	public static function draft( array $order ): array {
		$supplier = MMI_PO_Orders::supplier_for( $order );
		$settings = MMI_PO_Settings::all();
		$tokens   = self::tokens( $order, $supplier, $settings );

		$cc = array_merge(
			MMI_PO_Settings::parse_emails( (string) ( $supplier['cc'] ?? '' ) ),
			MMI_PO_Settings::parse_emails( (string) $settings['default_cc'] )
		);

		return array(
			'to'      => implode( ', ', MMI_PO_Settings::parse_emails( (string) ( $supplier['email'] ?? '' ) ) ),
			'cc'      => implode( ', ', array_unique( $cc ) ),
			'subject' => strtr( (string) $settings['email_subject'], $tokens ),
			'body'    => strtr( (string) $settings['email_body'], $tokens ),
			'label'   => ( $order['label_mode'] ?? '' ) === 'ours' ? MMI_PO_Shipping::active_label( (int) $order['id'] ) : null,
		);
	}

	/**
	 * @return array|WP_Error {to: string[], file: string} on success.
	 */
	public static function send( int $po_id, string $to, string $cc, string $subject, string $body, bool $attach_label = true ) {
		$order = MMI_PO_Orders::get( $po_id );
		if ( ! $order ) {
			return new WP_Error( 'mmi_po_missing', __( 'That purchase order no longer exists.', 'mmi-po' ) );
		}
		if ( empty( $order['items'] ) ) {
			return new WP_Error( 'mmi_po_empty', __( 'Add at least one line before sending.', 'mmi-po' ) );
		}

		$invalid = MMI_PO_Settings::invalid_emails( $to . ' ' . $cc );
		if ( $invalid ) {
			return new WP_Error( 'mmi_po_invalid', sprintf( /* translators: %s: addresses */ __( 'Not a valid email address: %s', 'mmi-po' ), implode( ', ', $invalid ) ) );
		}
		$to_list = MMI_PO_Settings::parse_emails( $to );
		$cc_list = MMI_PO_Settings::parse_emails( $cc );
		if ( ! $to_list ) {
			return new WP_Error( 'mmi_po_to', __( 'Enter at least one valid supplier email address.', 'mmi-po' ) );
		}
		if ( count( $to_list ) + count( $cc_list ) > self::MAX_RECIPIENTS ) {
			return new WP_Error( 'mmi_po_many', sprintf( /* translators: %d: limit */ __( 'At most %d recipients per email.', 'mmi-po' ), self::MAX_RECIPIENTS ) );
		}
		$subject = trim( $subject );
		if ( $subject === '' ) {
			return new WP_Error( 'mmi_po_subject', __( 'Enter a subject.', 'mmi-po' ) );
		}
		$count_key = self::SEND_COUNT_PREFIX . get_current_user_id();
		$sends     = (int) get_transient( $count_key );
		if ( $sends >= self::MAX_SENDS_PER_HOUR ) {
			mmi_po_audit( 'po.email', array( 'object_type' => 'purchase_order', 'object_id' => $po_id, 'outcome' => 'denied', 'details' => array( 'reason' => 'rate_limit' ) ) );
			return new WP_Error( 'mmi_po_rate', sprintf( /* translators: %d: limit */ __( 'You have sent %d purchase order emails in the last hour. Wait a while before sending more.', 'mmi-po' ), self::MAX_SENDS_PER_HOUR ) );
		}

		$bytes = MMI_PO_Document::pdf( $order );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}
		$stored = MMI_PO_Document::store( $order, $bytes );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		// wp_mail() names the attachment after the file on disk; send a copy with the friendly name.
		$attach = trailingslashit( get_temp_dir() ) . wp_unique_filename( get_temp_dir(), MMI_PO_Document::filename( $order ) );
		copy( $stored, $attach );

		$attachments = array( $attach );
		$label       = $attach_label && $order['label_mode'] === 'ours' ? MMI_PO_Shipping::active_label( $po_id ) : null;
		$label_path  = $label ? MMI_PO_Document::stored_path( $label['label_file'] ) : '';
		if ( $label_path !== '' ) {
			$label_copy = trailingslashit( get_temp_dir() ) . wp_unique_filename( get_temp_dir(), sanitize_file_name( 'Shipping-Label-' . $order['po_number'] . '.pdf' ) );
			copy( $label_path, $label_copy );
			$attachments[] = $label_copy;
		}

		$settings = MMI_PO_Settings::all();
		$headers  = array();
		if ( is_email( $settings['reply_to'] ) ) {
			// wp_mail() splits address headers on commas, so the display name must not carry one.
			$name      = trim( preg_replace( '/[,;<>"\x5c\r\n]+/', ' ', (string) $settings['buyer_name'] ) );
			$headers[] = 'Reply-To: ' . ( $name !== '' ? '"' . $name . '" ' : '' ) . '<' . $settings['reply_to'] . '>';
		}
		foreach ( $cc_list as $address ) {
			$headers[] = 'Cc: ' . $address;
		}
		$user = wp_get_current_user();
		if ( ! empty( $settings['bcc_self'] ) && $user && is_email( $user->user_email ) ) {
			$headers[] = 'Bcc: ' . $user->user_email;
		}

		$failure = '';
		$capture = static function ( $error ) use ( &$failure ) {
			$failure = $error instanceof WP_Error ? $error->get_error_message() : 'unknown';
		};
		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail( $to_list, $subject, $body, $headers, $attachments );
		remove_action( 'wp_mail_failed', $capture );
		set_transient( $count_key, $sends + 1, HOUR_IN_SECONDS );
		array_map( 'wp_delete_file', $attachments );

		$file = basename( $stored );
		$meta = array( 'to' => $to_list, 'cc' => $cc_list, 'subject' => $subject, 'file' => $file, 'label' => $label_path !== '' ? $label['tracking_number'] : '' );

		$audit = array( 'object_type' => 'purchase_order', 'object_id' => $po_id, 'details' => array( 'to' => $to_list, 'cc' => $cc_list, 'label' => $label_path !== '' ) );
		if ( ! $sent ) {
			mmi_po_audit( 'po.email', array_merge( $audit, array( 'outcome' => 'failure' ) ) );
			MMI_PO_Log::add( $po_id, 'email_failed', $failure !== '' ? $failure : 'wp_mail() returned false', $meta );
			MMI_Logger::error( 'PO email failed', array( 'po_id' => $po_id, 'error' => $failure ), 'integrations', 'MMI_PO_Mailer' );
			return new WP_Error( 'mmi_po_mail', sprintf( /* translators: %s: error */ __( 'The email was not sent: %s', 'mmi-po' ), $failure !== '' ? $failure : __( 'the mail transport reported a failure.', 'mmi-po' ) ) );
		}

		mmi_po_audit( 'po.email', $audit );
		MMI_PO_Orders::mark_sent( $po_id, array_merge( $to_list, $cc_list ) );
		MMI_PO_Log::add( $po_id, 'emailed', 'Sent to ' . implode( ', ', $to_list ) . ( $label_path !== '' ? ' with shipping label' : '' ), $meta );
		return array( 'to' => $to_list, 'file' => $file );
	}

	private static function tokens( array $order, array $supplier, array $settings ): array {
		$contact = trim( (string) ( $supplier['contact_name'] ?? '' ) );
		return array(
			'{po_number}'        => $order['po_number'],
			'{po_date}'          => date_i18n( 'F j, Y', strtotime( $order['po_date'] ) ),
			'{buyer_name}'       => $settings['buyer_name'],
			'{supplier_name}'    => (string) ( $supplier['name'] ?? '' ),
			'{supplier_contact}' => $contact !== '' ? $contact : (string) ( $supplier['name'] ?? '' ),
			'{item_count}'       => (string) count( $order['items'] ),
			'{total}'            => MMI_PO_Orders::money( (float) $order['total'] ),
		);
	}
}
