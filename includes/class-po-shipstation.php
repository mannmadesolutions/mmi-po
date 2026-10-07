<?php
/**
 * ShipStation API v1 client (https://www.shipstation.com/docs/api/). Only what mmi-po needs:
 * list carriers, get rates, create and void labels.
 *
 * Money: listing carriers and getting rates are free. createlabel buys postage billed to the
 * ShipStation account, so it only runs from the Rate Browser's explicit, priced confirmation.
 * There is no test mode: ShipStation can answer testLabel with "Test labels are not supported",
 * so a bought label is voided for a refund instead.
 *
 * @package MannMade\PurchaseOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_PO_ShipStation {

	const SETTINGS_KEY  = 'mmi_po_shipstation';
	const BASE_URL      = 'https://ssapi.shipstation.com';
	const THROTTLE_KEY  = 'shipstation';
	const TIMEOUT       = 30;
	const CARRIER_CACHE = 'mmi_po_ss_carriers';
	const CARRIER_TTL   = 12 * HOUR_IN_SECONDS;
	const MAX_RETRIES   = 1;
	const QUOTE_TTL     = 10 * MINUTE_IN_SECONDS;
	const QUOTE_PREFIX  = 'mmi_po_ss_quote_';

	/** ShipStation API keys are issued for 12 months. */
	const KEY_LIFETIME = '+12 months';
	const EXPIRY_WARN_DAYS = 30;

	/**
	 * api_key and api_secret are stored encrypted by MMI_Settings (MMI_Credentials) and read
	 * back as plaintext. A secret still in mmi-po's own pre-1.4.5 format is moved over once.
	 */
	public static function settings(): array {
		$saved = MMI_Settings::get( self::SETTINGS_KEY, array() );
		if ( is_array( $saved ) && MMI_PO_Crypto::is_legacy( (string) ( $saved['api_secret'] ?? '' ) ) ) {
			$saved['api_secret'] = MMI_PO_Crypto::decrypt( (string) $saved['api_secret'] );
			MMI_Settings::set( self::SETTINGS_KEY, $saved );
		}
		return array_merge(
			array(
				'api_key'    => '',
				'api_secret' => '',
				'carriers'   => array(),
				'saved_on'   => '',
				'expires_on' => '',
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	/** Blank key/secret inputs keep the stored values, so the secret never round-trips to the browser. */
	public static function save_settings( array $raw ): void {
		$current = self::settings();
		$key     = trim( sanitize_text_field( (string) ( $raw['ss_api_key'] ?? '' ) ) );
		$secret  = trim( (string) ( $raw['ss_api_secret'] ?? '' ) );

		$next = array(
			'api_key'    => $key !== '' ? $key : $current['api_key'],
			'api_secret' => $secret !== '' ? $secret : $current['api_secret'],
			'carriers'   => array_values( array_filter( array_map( 'sanitize_key', (array) ( $raw['ss_carriers'] ?? array() ) ) ) ),
			'saved_on'   => $current['saved_on'],
			'expires_on' => $current['expires_on'],
		);

		// Expiry: an edited date wins; otherwise new credentials start a fresh 12-month term.
		$typed = sanitize_text_field( (string) ( $raw['ss_expires_on'] ?? '' ) );
		$typed = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $typed ) ? $typed : '';
		if ( $key !== '' || $secret !== '' ) {
			$today              = current_time( 'Y-m-d' );
			$next['saved_on']   = $today;
			$next['expires_on'] = $typed !== '' && $typed !== $current['expires_on'] ? $typed : gmdate( 'Y-m-d', strtotime( $today . ' ' . self::KEY_LIFETIME ) );
		} elseif ( $typed !== '' ) {
			$next['expires_on'] = $typed;
		}

		if ( ! empty( $raw['ss_forget'] ) ) {
			$next['api_key']    = '';
			$next['api_secret'] = '';
			$next['saved_on']   = '';
			$next['expires_on'] = '';
		}
		if ( $next['api_key'] !== $current['api_key'] || $next['api_secret'] !== $current['api_secret'] ) {
			delete_transient( self::CARRIER_CACHE );
		}
		MMI_Settings::set( self::SETTINGS_KEY, $next );
	}

	public static function is_configured(): bool {
		$s = self::settings();
		return $s['api_key'] !== '' && $s['api_secret'] !== '';
	}

	/**
	 * @return array{expires_on: string, days_left: ?int, level: string} level: none|ok|warning|expired
	 */
	public static function expiry(): array {
		$s = self::settings();
		if ( ! self::is_configured() || $s['expires_on'] === '' ) {
			return array( 'expires_on' => '', 'days_left' => null, 'level' => 'none' );
		}
		$days = (int) floor( ( strtotime( $s['expires_on'] ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
		return array(
			'expires_on' => $s['expires_on'],
			'days_left'  => $days,
			'level'      => $days < 0 ? 'expired' : ( $days <= self::EXPIRY_WARN_DAYS ? 'warning' : 'ok' ),
		);
	}

	/** One sentence for Settings, the editor and the dashboard alert. */
	public static function expiry_message(): string {
		$e = self::expiry();
		if ( $e['level'] === 'none' ) {
			return '';
		}
		$date = date_i18n( get_option( 'date_format' ), strtotime( $e['expires_on'] ) );
		if ( $e['level'] === 'expired' ) {
			return sprintf( /* translators: %s: date */ __( 'The ShipStation API key expired on %s. Generate a new one in ShipStation and paste it in Purchase Orders → Settings.', 'mmi-po' ), $date );
		}
		return sprintf( /* translators: 1: date, 2: days */ _n( 'The ShipStation API key expires on %1$s (%2$d day left).', 'The ShipStation API key expires on %1$s (%2$d days left).', $e['days_left'], 'mmi-po' ), $date, $e['days_left'] );
	}

	/** mmi_dashboard_alerts filter: warn 30 days out, critical once expired. */
	public static function dashboard_alerts( array $alerts ): array {
		$e = self::expiry();
		if ( in_array( $e['level'], array( 'warning', 'expired' ), true ) ) {
			$alerts[] = array(
				'severity'    => $e['level'] === 'expired' ? 'critical' : 'warning',
				'title'       => __( 'ShipStation API key', 'mmi-po' ),
				'description' => self::expiry_message(),
				'url'         => admin_url( 'admin.php?page=mmi-po&tab=settings' ),
				'page_slug'   => 'mmi-po',
			);
		}
		return $alerts;
	}

	/**
	 * Carriers connected to the ShipStation account, cached 12 h.
	 *
	 * @return array|WP_Error
	 */
	public static function carriers( bool $refresh = false ) {
		$cached = $refresh ? false : get_transient( self::CARRIER_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res = self::request( 'GET', '/carriers' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$carriers = array();
		foreach ( (array) $res as $c ) {
			// Accounts often give every carrier the same nickname, so the carrier's own name leads
			// and a distinct nickname is only appended.
			$name     = (string) ( $c['name'] ?? $c['code'] ?? '' );
			$nickname = trim( (string) ( $c['nickname'] ?? '' ) );
			$carriers[] = array(
				'code'     => (string) ( $c['code'] ?? '' ),
				'name'     => $nickname !== '' && stripos( $name, $nickname ) === false && stripos( $nickname, $name ) === false ? $name . ' (' . $nickname . ')' : $name,
				'balance'  => isset( $c['balance'] ) ? (float) $c['balance'] : null,
				'funded'   => ! empty( $c['requiresFundedAccount'] ),
				'primary'  => ! empty( $c['primary'] ),
			);
		}
		set_transient( self::CARRIER_CACHE, $carriers, self::CARRIER_TTL );
		return $carriers;
	}

	/** Carriers to quote: the ones ticked in Settings, or all when none are ticked. */
	public static function quote_carriers() {
		$carriers = self::carriers();
		if ( is_wp_error( $carriers ) ) {
			return $carriers;
		}
		$wanted = array_flip( self::settings()['carriers'] );
		return array_values( $wanted ? array_filter( $carriers, static function ( $c ) use ( $wanted ) {
			return isset( $wanted[ $c['code'] ] );
		} ) : $carriers );
	}

	/**
	 * One carrier's rates for a quote (ShipStation v1 rates one carrier per call). Identical
	 * quotes are cached for 10 minutes so re-opening the browser or switching filters is instant.
	 *
	 * @param array $q from_postal, from_city, from_state, to_country, to_postal, to_city, to_state,
	 *                 residential, weight_oz, length, width, height, confirmation, package_code.
	 * @return array|WP_Error List of rates.
	 */
	public static function carrier_rates( string $carrier_code, array $q ) {
		$body = array_filter( array(
			'carrierCode'    => $carrier_code,
			'packageCode'    => $q['package_code'] ?? '',
			'fromPostalCode' => $q['from_postal'],
			'fromCity'       => $q['from_city'] ?? '',
			'fromState'      => $q['from_state'] ?? '',
			'toCountry'      => $q['to_country'],
			'toPostalCode'   => $q['to_postal'],
			'toState'        => $q['to_state'] ?? '',
			'toCity'         => $q['to_city'] ?? '',
			'weight'         => self::weight( $q ),
			'dimensions'     => self::dimensions( $q ),
			'confirmation'   => $q['confirmation'] ?? 'none',
		), static function ( $v ) {
			return $v !== null && $v !== '';
		} );
		$body['residential'] = ! empty( $q['residential'] );

		$key    = self::QUOTE_PREFIX . md5( wp_json_encode( $body ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res = self::request( 'POST', '/shipments/getrates', $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$rates = array();
		foreach ( (array) $res as $r ) {
			$rates[] = array(
				'service_code' => (string) $r['serviceCode'],
				'service_name' => (string) $r['serviceName'],
				'cost'         => round( (float) $r['shipmentCost'] + (float) $r['otherCost'], 2 ),
				'other_cost'   => round( (float) $r['otherCost'], 2 ),
			);
		}
		usort( $rates, static function ( $a, $b ) {
			return $a['cost'] <=> $b['cost'];
		} );
		set_transient( $key, $rates, self::QUOTE_TTL );
		return $rates;
	}

	/**
	 * Buys a label (real postage).
	 *
	 * @return array|WP_Error createlabel response.
	 */
	public static function create_label( array $from, array $to, array $package, string $carrier_code, string $service_code ) {
		$body = array(
			'carrierCode'  => $carrier_code,
			'serviceCode'  => $service_code,
			'packageCode'  => 'package',
			'confirmation' => $package['confirmation'],
			'shipDate'     => $package['ship_date'],
			'weight'       => self::weight( $package ),
			'shipFrom'     => self::address( $from ),
			'shipTo'       => self::address( $to ),
		);
		$dims = self::dimensions( $package );
		if ( $dims ) {
			$body['dimensions'] = $dims;
		}
		$res = self::request( 'POST', '/shipments/createlabel', $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['labelData'] ) ) {
			return new WP_Error( 'mmi_po_ss_label', __( 'ShipStation did not return a label.', 'mmi-po' ) );
		}
		return $res;
	}

	/** @return true|WP_Error */
	public static function void_label( string $shipment_id ) {
		$res = self::request( 'POST', '/shipments/voidlabel', array( 'shipmentId' => (int) $shipment_id ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['approved'] ) ) {
			return new WP_Error( 'mmi_po_ss_void', (string) ( $res['message'] ?? __( 'ShipStation did not approve the void.', 'mmi-po' ) ) );
		}
		return true;
	}

	/* ── HTTP ─────────────────────────────────────────────────────────────── */

	/**
	 * Error data carries 'uncertain' => true when the request may have been processed anyway: no
	 * response, or a 5xx without ShipStation's own error message (a gateway error or crash). For
	 * createlabel that means postage may have been bought. ShipStation also answers ordinary
	 * refusals ("No applicable services…") with a 500 plus a message; those are definite.
	 *
	 * @return array|WP_Error Decoded JSON body.
	 */
	private static function request( string $method, string $path, ?array $body = null, int $attempt = 0 ) {
		$s      = self::settings();
		$secret = (string) $s['api_secret'];
		if ( $s['api_key'] === '' || $secret === '' ) {
			return new WP_Error( 'mmi_po_ss_creds', __( 'Add your ShipStation API key and secret in Purchase Orders → Settings.', 'mmi-po' ) );
		}

		MMI_API_Throttler::throttle( self::THROTTLE_KEY );
		$started  = microtime( true );
		$response = wp_remote_request(
			self::BASE_URL . $path,
			array(
				'method'  => $method,
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $s['api_key'] . ':' . $secret ),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => $body === null ? null : wp_json_encode( $body ),
			)
		);
		$ms = (int) ( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			MMI_Logger::error( 'ShipStation request failed', array( 'path' => $path, 'error' => $response->get_error_message(), 'ms' => $ms ), 'integrations', 'MMI_PO_ShipStation' );
			return new WP_Error( 'mmi_po_ss_http', __( 'Could not reach ShipStation: ', 'mmi-po' ) . $response->get_error_message(), array( 'uncertain' => true ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( $code === 429 ) {
			$retry_after = (int) wp_remote_retrieve_header( $response, 'x-rate-limit-reset' );
			MMI_API_Throttler::penalize( self::THROTTLE_KEY, $retry_after );
			MMI_Logger::warn( 'ShipStation rate limited', array( 'path' => $path, 'retry_after' => $retry_after ), 'throttler', 'MMI_PO_ShipStation' );
			if ( $attempt < self::MAX_RETRIES ) {
				return self::request( $method, $path, $body, $attempt + 1 );
			}
			return new WP_Error( 'mmi_po_ss_429', __( 'ShipStation is rate limiting requests. Try again in a minute.', 'mmi-po' ) );
		}
		MMI_API_Throttler::clear_penalty( self::THROTTLE_KEY );

		$data = json_decode( $raw, true );
		if ( $code === 401 ) {
			$expired = self::expiry()['level'] === 'expired' ? ' ' . self::expiry_message() : '';
			return new WP_Error( 'mmi_po_ss_401', __( 'ShipStation rejected the API key or secret (401). Check them in Settings, and that your ShipStation plan includes API access.', 'mmi-po' ) . $expired );
		}
		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) ? (string) ( $data['ExceptionMessage'] ?? $data['Message'] ?? $data['message'] ?? '' ) : '';
			MMI_Logger::error( 'ShipStation API error', array( 'path' => $path, 'status' => $code, 'message' => $message, 'ms' => $ms ), 'integrations', 'MMI_PO_ShipStation' );
			return new WP_Error( 'mmi_po_ss_' . $code, sprintf( 'ShipStation error %d%s', $code, $message !== '' ? ': ' . $message : '' ), array( 'uncertain' => $code >= 500 && $message === '' ) );
		}
		MMI_Logger::debug( 'ShipStation request', array( 'path' => $path, 'status' => $code, 'ms' => $ms ), 'integrations', 'MMI_PO_ShipStation' );
		return is_array( $data ) ? $data : array();
	}

	private static function weight( array $package ): array {
		return array( 'value' => round( (float) $package['weight_oz'], 2 ), 'units' => 'ounces' );
	}

	private static function dimensions( array $package ): ?array {
		if ( (float) $package['length'] <= 0 || (float) $package['width'] <= 0 || (float) $package['height'] <= 0 ) {
			return null;
		}
		return array(
			'units'  => 'inches',
			'length' => (float) $package['length'],
			'width'  => (float) $package['width'],
			'height' => (float) $package['height'],
		);
	}

	private static function address( array $a ): array {
		return array(
			'name'        => $a['name'] !== '' ? $a['name'] : $a['company'],
			'company'     => $a['company'],
			'street1'     => $a['street1'],
			'street2'     => $a['street2'],
			'city'        => $a['city'],
			'state'       => $a['state'],
			'postalCode'  => $a['postal_code'],
			'country'     => $a['country'],
			'phone'       => $a['phone'],
			'residential' => (bool) $a['residential'],
		);
	}
}
