<?php
/**
 * Operations record: every create, edit, export, email and status change on a PO.
 * This is the business audit trail shown in the Activity tab. Diagnostics still go to MMI_Logger.
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_PO_Log {

	const LABELS = array(
		'created'        => 'Created',
		'updated'        => 'Saved',
		'duplicated'     => 'Duplicated',
		'pdf_exported'   => 'PDF exported',
		'emailed'        => 'Emailed',
		'email_failed'   => 'Email failed',
		'status_changed' => 'Status changed',
		'deleted'        => 'Deleted',
		'label_bought'   => 'Label bought',
		'label_voided'   => 'Label voided',
		'label_failed'   => 'Label failed',
		'settings'       => 'Settings',
	);

	public static function add( int $po_id, string $action, string $message, array $meta = array() ): void {
		global $wpdb;
		$wpdb->insert(
			MMI_PO_Install::table( 'log' ),
			array(
				'po_id'      => $po_id,
				'action'     => $action,
				'message'    => $message,
				'meta'       => $meta ? wp_json_encode( $meta ) : null,
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * @return array{rows: array, total: int}
	 */
	public static function query( int $po_id = 0, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$log    = MMI_PO_Install::table( 'log' );
		$orders = MMI_PO_Install::table( 'orders' );
		$where  = $po_id ? $wpdb->prepare( 'WHERE l.po_id = %d', $po_id ) : '';
		$offset = max( 0, ( $page - 1 ) * $per_page );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, o.po_number FROM {$log} l LEFT JOIN {$orders} o ON o.id = l.po_id {$where} ORDER BY l.id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log} l {$where}" );

		$user_ids = array_unique( array_map( 'intval', wp_list_pluck( $rows, 'user_id' ) ) );
		$names    = array();
		if ( $user_ids ) {
			foreach ( get_users( array( 'include' => $user_ids, 'fields' => array( 'ID', 'display_name' ) ) ) as $user ) {
				$names[ (int) $user->ID ] = $user->display_name;
			}
		}
		foreach ( $rows as &$row ) {
			$row['user_name'] = $names[ (int) $row['user_id'] ] ?? '';
			$row['label']     = self::LABELS[ $row['action'] ] ?? $row['action'];
			$row['meta']      = $row['meta'] ? json_decode( $row['meta'], true ) : array();
		}

		return array( 'rows' => $rows, 'total' => $total );
	}
}
