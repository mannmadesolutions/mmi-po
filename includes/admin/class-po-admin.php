<?php
/**
 * Admin page, AJAX endpoints and file downloads for Purchase Orders.
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_PO_Admin {

	const PAGE_SLUG    = 'mmi-po';
	const CAPABILITY   = 'manage_woocommerce';
	const NONCE_ACTION = 'mmi_po_admin';
	const PER_PAGE     = 50;
	const MIN_SEARCH   = 2;

	const TABS = array(
		'orders'    => array( 'label' => 'Purchase Orders', 'icon' => 'clipboard' ),
		'edit'      => array( 'label' => 'New PO', 'icon' => 'plus-alt2' ),
		'suppliers' => array( 'label' => 'Suppliers', 'icon' => 'building' ),
		'activity'  => array( 'label' => 'Activity', 'icon' => 'backup' ),
		'settings'  => array( 'label' => 'Settings', 'icon' => 'admin-settings' ),
	);

	const AJAX_ACTIONS = array( 'search_products', 'save', 'set_status', 'duplicate', 'delete', 'email_draft', 'send', 'save_supplier', 'load_wc_order', 'rate_carriers', 'rates', 'buy_label', 'void_label', 'ss_carriers' );

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ) );
		add_filter( 'mmi_dashboard_alerts', array( 'MMI_PO_ShipStation', 'dashboard_alerts' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_mmi_po_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_mmi_po_pdf', array( __CLASS__, 'serve_pdf' ) );
		add_action( 'admin_post_mmi_po_file', array( __CLASS__, 'serve_stored_file' ) );
		foreach ( self::AJAX_ACTIONS as $action ) {
			add_action( 'wp_ajax_mmi_po_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	public static function register_submenu(): void {
		add_submenu_page( 'mmi-dashboard', __( 'Purchase Orders', 'mmi-po' ), __( 'Purchase Orders', 'mmi-po' ), self::CAPABILITY, self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Raw (unescaped) URLs: they are handed to JavaScript as well as printed. wp_nonce_url()
	 * HTML-escapes its result, which turned every & into &amp; in the editor's Preview/Download
	 * links. Templates escape these with esc_url() at output.
	 */
	public static function pdf_url( int $po_id, bool $download ): string {
		return add_query_arg(
			array(
				'action'   => 'mmi_po_pdf',
				'po'       => $po_id,
				'download' => $download ? 1 : 0,
				'_wpnonce' => wp_create_nonce( self::NONCE_ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public static function stored_file_url( string $file ): string {
		return add_query_arg(
			array(
				'action'   => 'mmi_po_file',
				'file'     => rawurlencode( $file ),
				'_wpnonce' => wp_create_nonce( self::NONCE_ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/** Substring match: the hook suffix derives from the parent menu's title, not its slug. */
	public static function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}
		$tab = self::current_tab();

		wp_enqueue_style( 'mmi-po-admin', MMI_PO_URL . 'assets/css/admin-po.css', array( 'mmi-suite-common' ), MMI_PO_VERSION );
		if ( $tab === 'settings' ) {
			wp_enqueue_media();
			$config['shipstationConfigured'] = MMI_PO_ShipStation::is_configured();
		}
		wp_enqueue_script( 'mmi-po-admin', MMI_PO_URL . 'assets/js/admin-po.js', array( 'jquery', 'jquery-ui-sortable', 'mmi-modal', 'mmi-escape-html', 'mmi-table-manager' ), MMI_PO_VERSION, true );

		$config = array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
			'tab'       => $tab,
			'minSearch' => self::MIN_SEARCH,
			'currency'  => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'listUrl'   => self::url(),
			'editUrl'   => self::url( array( 'tab' => 'edit', 'po' => '%id%' ) ),
		);

		if ( $tab === 'edit' ) {
			$po_id              = isset( $_GET['po'] ) ? absint( $_GET['po'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- read-only view.
			$order              = $po_id ? MMI_PO_Orders::get( $po_id ) : null;
			$config['order']    = $order ? self::order_for_js( $order ) : null;
			$config['suppliers'] = self::suppliers_for_js();
			$config['defaults'] = array(
				'ship_to_fields' => MMI_PO_Settings::get( 'store_address' ),
				'instructions'   => MMI_PO_Settings::get( 'default_instructions' ),
				'po_date'        => current_time( 'Y-m-d' ),
				'package'        => self::package_for_js( MMI_PO_Shipping::clean_package( array() ) ),
			);
			$config['shipstation'] = array(
				'configured' => MMI_PO_ShipStation::is_configured(),
				'expiry'     => MMI_PO_ShipStation::expiry()['level'],
				'expiryText' => MMI_PO_ShipStation::expiry_message(),
				'appUrl'     => 'https://ship.shipstation.com/',
			);
			$config['wcOrderUrl'] = admin_url( 'post.php?post=%id%&action=edit' );
			$config['pdfUrl']      = $order ? self::pdf_url( $po_id, false ) : '';
			$config['downloadUrl'] = $order ? self::pdf_url( $po_id, true ) : '';
		}
		if ( $tab === 'suppliers' ) {
			$config['suppliers'] = self::suppliers_for_js();
			$config['brands']    = MMI_PO_Suppliers::all_brands();
		}

		wp_localize_script( 'mmi-po-admin', 'mmiPo', $config );
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$tab = self::current_tab();
		include MMI_PO_PATH . 'includes/admin/views/page.php';
	}

	public static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'orders'; // phpcs:ignore WordPress.Security.NonceVerification -- navigation only.
		return isset( self::TABS[ $tab ] ) ? $tab : 'orders';
	}

	/* ── AJAX ─────────────────────────────────────────────────────────────── */

	public static function ajax_search_products(): void {
		self::guard();
		$term = sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) );
		if ( mb_strlen( $term ) < self::MIN_SEARCH ) {
			wp_send_json_success( array( 'results' => array() ) );
		}
		$brand_ids = array();
		$supplier  = MMI_PO_Suppliers::get( absint( $_POST['supplier_id'] ?? 0 ) );
		if ( $supplier && ! empty( $_POST['brand_only'] ) ) {
			$brand_ids = $supplier['brand_ids'];
		}
		wp_send_json_success( array( 'results' => MMI_PO_Products::search( $term, $brand_ids ) ) );
	}

	public static function ajax_save(): void {
		self::guard();
		$payload = json_decode( wp_unslash( $_POST['payload'] ?? '' ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field sanitized in MMI_PO_Orders::save().
		if ( ! is_array( $payload ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to save.', 'mmi-po' ) ), 400 );
		}
		$id = MMI_PO_Orders::save( $payload, absint( $payload['id'] ?? 0 ) );
		self::respond_order( $id );
	}

	public static function ajax_set_status(): void {
		self::guard();
		$id     = absint( $_POST['id'] ?? 0 );
		$result = MMI_PO_Orders::set_status( $id, sanitize_key( wp_unslash( $_POST['status'] ?? '' ) ) );
		self::respond_order( is_wp_error( $result ) ? $result : $id );
	}

	public static function ajax_duplicate(): void {
		self::guard();
		self::respond_order( MMI_PO_Orders::duplicate( absint( $_POST['id'] ?? 0 ) ) );
	}

	public static function ajax_delete(): void {
		self::guard();
		$result = MMI_PO_Orders::delete( absint( $_POST['id'] ?? 0 ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'redirect' => self::url() ) );
	}

	public static function ajax_email_draft(): void {
		self::guard();
		$order = MMI_PO_Orders::get( absint( $_POST['id'] ?? 0 ) );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'That purchase order no longer exists.', 'mmi-po' ) ), 404 );
		}
		wp_send_json_success( MMI_PO_Mailer::draft( $order ) );
	}

	public static function ajax_send(): void {
		self::guard();
		$id     = absint( $_POST['id'] ?? 0 );
		$result = MMI_PO_Mailer::send(
			$id,
			sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['cc'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) ),
			sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) ),
			! empty( $_POST['attach_label'] )
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		if ( ! empty( $_POST['remember'] ) ) {
			self::remember_supplier_email( $id, sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ) );
		}
		self::respond_order( $id );
	}

	public static function ajax_save_supplier(): void {
		self::guard();
		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field sanitized in MMI_PO_Suppliers::save().
		$id  = MMI_PO_Suppliers::save( is_array( $raw ) ? $raw : array(), absint( $raw['id'] ?? 0 ) );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'id' => $id, 'suppliers' => self::suppliers_for_js() ) );
	}

	/** Drop-ship: pull a WooCommerce order's shipping address and its lines (as PO lines). */
	public static function ajax_load_wc_order(): void {
		self::guard();
		$wc_order = wc_get_order( absint( preg_replace( '/\D/', '', (string) wp_unslash( $_POST['order'] ?? '' ) ) ) );
		if ( ! $wc_order || $wc_order->get_type() !== 'shop_order' ) {
			wp_send_json_error( array( 'message' => __( 'No WooCommerce order with that number.', 'mmi-po' ) ), 404 );
		}
		$qty = array();
		foreach ( $wc_order->get_items() as $item ) {
			$pid         = (int) ( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
			$qty[ $pid ] = ( $qty[ $pid ] ?? 0 ) + (int) $item->get_quantity();
		}
		$lines = array();
		foreach ( MMI_PO_Products::by_ids( array_keys( $qty ) ) as $product ) {
			$product['qty'] = $qty[ $product['product_id'] ] ?? 1;
			$lines[]        = $product;
		}
		wp_send_json_success( array(
			'id'      => $wc_order->get_id(),
			'number'  => $wc_order->get_order_number(),
			'address' => MMI_PO_Address::from_wc_order( $wc_order ),
			'lines'   => $lines,
		) );
	}

	/** Rate Browser: the carriers to quote (ticked in Settings, else all). */
	public static function ajax_rate_carriers(): void {
		self::guard();
		$carriers = MMI_PO_ShipStation::quote_carriers();
		if ( is_wp_error( $carriers ) ) {
			wp_send_json_error( array( 'message' => $carriers->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'carriers' => $carriers ) );
	}

	/**
	 * Rate Browser: one carrier's rates. The browser calls this once per carrier, one at a time,
	 * so counts fill in progressively and no more than one ShipStation request runs per user.
	 */
	public static function ajax_rates(): void {
		self::guard();
		$quote = MMI_PO_Shipping::clean_quote( (array) wp_unslash( $_POST['quote'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in clean_quote().
		if ( $quote['from_postal'] === '' || $quote['to_postal'] === '' || $quote['weight_oz'] <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Enter both postal codes and a weight.', 'mmi-po' ) ), 400 );
		}
		$rates = MMI_PO_ShipStation::carrier_rates( sanitize_key( wp_unslash( $_POST['carrier_code'] ?? '' ) ), $quote );
		if ( is_wp_error( $rates ) ) {
			wp_send_json_success( array( 'rates' => array(), 'error' => $rates->get_error_message() ) );
		}
		wp_send_json_success( array( 'rates' => $rates, 'error' => '' ) );
	}

	/** Buys postage (unless ShipStation test mode is on). Only reachable from the confirm dialog. */
	public static function ajax_buy_label(): void {
		self::guard();
		$id     = absint( $_POST['id'] ?? 0 );
		$result = MMI_PO_Shipping::buy_label(
			$id,
			sanitize_key( wp_unslash( $_POST['carrier_code'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['service_code'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['service_name'] ?? '' ) )
		);
		self::respond_order( is_wp_error( $result ) ? $result : $id );
	}

	public static function ajax_void_label(): void {
		self::guard();
		$result = MMI_PO_Shipping::void_label( absint( $_POST['shipment_id'] ?? 0 ) );
		self::respond_order( is_wp_error( $result ) ? $result : absint( $_POST['id'] ?? 0 ) );
	}

	/** Settings → Test connection: lists the account's carriers (also refreshes the cache). */
	public static function ajax_ss_carriers(): void {
		self::guard();
		$carriers = MMI_PO_ShipStation::carriers( true );
		if ( is_wp_error( $carriers ) ) {
			wp_send_json_error( array( 'message' => $carriers->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'carriers' => $carriers, 'selected' => MMI_PO_ShipStation::settings()['carriers'] ) );
	}

	/* ── admin-post handlers ──────────────────────────────────────────────── */

	public static function save_settings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mmi-po' ) );
		}
		check_admin_referer( self::NONCE_ACTION );
		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field in MMI_PO_Settings::save().
		MMI_PO_Settings::save( is_array( $raw ) ? $raw : array() );
		MMI_PO_ShipStation::save_settings( is_array( $raw ) ? $raw : array() );
		wp_safe_redirect( self::url( array( 'tab' => 'settings', 'saved' => 1 ) ) );
		exit;
	}

	/** Renders a PO's PDF on the fly: inline for Preview, attachment for Download. */
	public static function serve_pdf(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mmi-po' ) );
		}
		check_admin_referer( self::NONCE_ACTION );
		$order = MMI_PO_Orders::get( absint( $_GET['po'] ?? 0 ) );
		if ( ! $order ) {
			wp_die( esc_html__( 'That purchase order no longer exists.', 'mmi-po' ) );
		}
		$bytes = MMI_PO_Document::pdf( $order );
		if ( is_wp_error( $bytes ) ) {
			wp_die( esc_html( $bytes->get_error_message() ) );
		}
		$download = ! empty( $_GET['download'] );
		if ( $download ) {
			MMI_PO_Log::add( (int) $order['id'], 'pdf_exported', 'PDF downloaded' );
		}
		self::send_pdf( $bytes, MMI_PO_Document::filename( $order ), $download );
	}

	/** Serves an archived copy of a PDF exactly as it was emailed. */
	public static function serve_stored_file(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mmi-po' ) );
		}
		check_admin_referer( self::NONCE_ACTION );
		$name = sanitize_file_name( wp_unslash( $_GET['file'] ?? '' ) );
		$path = MMI_PO_Document::stored_path( $name );
		if ( $path === '' ) {
			wp_die( esc_html__( 'That file is no longer available.', 'mmi-po' ) );
		}
		self::send_pdf( (string) file_get_contents( $path ), $name, false );
	}

	/* ── Helpers ─────────────────────────────────────────────────────────── */

	private static function send_pdf( string $bytes, string $filename, bool $download ): void {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . ( $download ? 'attachment' : 'inline' ) . '; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF.
		exit;
	}

	private static function guard(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mmi-po' ) ), 403 );
		}
	}

	/** @param int|WP_Error $id */
	private static function respond_order( $id ): void {
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ), 400 );
		}
		$order = MMI_PO_Orders::get( (int) $id );
		wp_send_json_success(
			array(
				'order'       => self::order_for_js( $order ),
				'editUrl'     => self::url( array( 'tab' => 'edit', 'po' => (int) $id ) ),
				'pdfUrl'      => self::pdf_url( (int) $id, false ),
				'downloadUrl' => self::pdf_url( (int) $id, true ),
			)
		);
	}

	private static function remember_supplier_email( int $po_id, string $to ): void {
		$order    = MMI_PO_Orders::get( $po_id );
		$supplier = $order ? MMI_PO_Suppliers::get( (int) $order['supplier_id'] ) : null;
		if ( ! $supplier ) {
			return;
		}
		$supplier['email']     = $to;
		$supplier['active']    = $supplier['active'] ? 1 : 0;
		MMI_PO_Suppliers::save( $supplier, (int) $supplier['id'] );
	}

	private static function order_for_js( array $order ): array {
		$sizes = MMI_PO_Products::sizes( wp_list_pluck( $order['items'], 'product_id' ) );
		$history = MMI_PO_Log::query( (int) $order['id'], 1, 20 )['rows'];
		foreach ( $history as &$row ) {
			$row['file_url'] = ! empty( $row['meta']['file'] ) ? self::stored_file_url( $row['meta']['file'] ) : '';
		}
		return array(
			'id'           => (int) $order['id'],
			'po_number'    => $order['po_number'],
			'status'       => $order['status'],
			'status_label' => MMI_PO_Orders::STATUSES[ $order['status'] ] ?? $order['status'],
			'supplier_id'  => (int) $order['supplier_id'],
			'po_date'      => $order['po_date'],
			'ship_to_fields' => is_array( $order['ship_to_fields'] ) ? MMI_PO_Address::clean( $order['ship_to_fields'] ) : MMI_PO_Settings::get( 'store_address' ),
			'wc_order_id'    => (int) $order['wc_order_id'],
			'label_mode'     => $order['label_mode'],
			'package'        => self::package_for_js( MMI_PO_Shipping::package_for_editor( $order ) ),
			'labels'         => MMI_PO_Shipping::labels( (int) $order['id'] ),
			'quote'          => MMI_PO_Shipping::default_quote( $order ),
			'label_blocker'  => MMI_PO_Shipping::label_blocker( $order ),
			'instructions' => (string) $order['instructions'],
			'notes'        => (string) $order['notes'],
			'tax'          => (float) $order['tax'],
			'shipping'     => (float) $order['shipping'],
			'total'        => (float) $order['total'],
			'sent_at'      => $order['sent_at'],
			'sent_to'      => $order['sent_to'],
			'items'        => array_map( static function ( $i ) use ( $sizes ) {
				$size = $sizes[ (int) $i['product_id'] ] ?? array( 'dims' => null, 'weight_lb' => null, 'size_source' => '' );
				return array(
					'size_source' => $size['size_source'],
					'dims'        => $size['dims'],
					'weight_lb'   => $size['weight_lb'],
					'product_id'  => (int) $i['product_id'],
					'item_number' => $i['item_number'],
					'sku'         => $i['sku'],
					'description' => (string) $i['description'],
					'qty'         => (int) $i['qty'],
					'unit_cost'   => (float) $i['unit_cost'],
				);
			}, $order['items'] ),
			'history'      => $history,
		);
	}

	/** Editor shows weight as lb + oz. */
	private static function package_for_js( array $p ): array {
		$oz = (float) ( $p['weight_oz'] ?? 0 );
		return array(
			'weight_lb'      => (int) floor( $oz / MMI_PO_Shipping::OZ_PER_LB ),
			'weight_oz_part' => round( fmod( $oz, MMI_PO_Shipping::OZ_PER_LB ), 1 ),
			'length'         => (float) ( $p['length'] ?? 0 ),
			'width'          => (float) ( $p['width'] ?? 0 ),
			'height'         => (float) ( $p['height'] ?? 0 ),
			'confirmation'   => $p['confirmation'] ?? 'none',
			'ship_date'      => max( (string) ( $p['ship_date'] ?? '' ), current_time( 'Y-m-d' ) ),
		);
	}

	private static function suppliers_for_js(): array {
		$suppliers = MMI_PO_Suppliers::all();
		$brand_ids = array();
		foreach ( $suppliers as $s ) {
			$brand_ids = array_merge( $brand_ids, $s['brand_ids'] );
		}
		$names = MMI_PO_Suppliers::brand_names( array_unique( $brand_ids ) );
		$out   = array();
		foreach ( $suppliers as $s ) {
			// Same order as brand_ids, so index i of each describes the same brand.
			$s['brands'] = array_map( static function ( $id ) use ( $names ) {
				return $names[ $id ] ?? '#' . $id;
			}, $s['brand_ids'] );
			$out[]       = $s;
		}
		return $out;
	}
}
