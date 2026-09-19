<?php
/**
 * Credential-blind Test Connection AJAX handler.
 *
 * Reports per-catalog connection verdicts (connected vs bad key vs network
 * failure) without ever reading or writing any `connectors_ai_*` option
 * value. Verdicts reuse the availability probe and its 5-minute cache.
 *
 * @package OpenCodeConnector
 * @since 0.1.5
 */

declare(strict_types=1);

namespace OpenCodeConnector\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test Connection endpoint.
 *
 * @package OpenCodeConnector
 * @since 0.1.5
 */
final class ConnectionTest {
	/**
	 * AJAX action name.
	 *
	 * @since 0.1.5
	 */
	const AJAX_ACTION = 'opencode_connector_test';

	/**
	 * Nonce action name.
	 *
	 * @since 0.1.5
	 */
	const NONCE_ACTION = 'opencode_connector_test';

	/**
	 * Register the AJAX handler (admin only).
	 *
	 * Credential-blind: registers hooks only, reads no option values.
	 *
	 * @since 0.1.5
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		if ( function_exists( 'has_action' ) && has_action( 'wp_ajax_' . self::AJAX_ACTION ) ) {
			return;
		}
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Handle the AJAX Test Connection request.
	 *
	 * Verifies capabilities + nonce, probes one catalog (or both when
	 * `catalog=all`), and returns a JSON verdict per catalog. Never throws;
	 * failures degrade to a network-error verdict (fail-open, never fatal).
	 *
	 * @since 0.1.5
	 *
	 * @return void
	 */
	public static function handle(): void {
		try {
			if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
				self::send_error( 'forbidden', 403 );
				return;
			}
			$nonce_ok = false;
			if ( function_exists( 'check_ajax_referer' ) ) {
				$nonce_ok = (bool) check_ajax_referer( self::NONCE_ACTION, 'nonce', false );
			} elseif ( function_exists( 'wp_verify_nonce' ) && isset( $_REQUEST['nonce'] ) ) {
				$nonce_raw = $_REQUEST['nonce']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed on the next line.
				if ( function_exists( 'wp_unslash' ) && is_string( $nonce_raw ) ) {
					$nonce_raw = wp_unslash( $nonce_raw ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				}
				$nonce_ok = is_string( $nonce_raw ) && (bool) wp_verify_nonce( sanitize_key( $nonce_raw ), self::NONCE_ACTION ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			} else {
				$nonce_ok = false;
			}
			if ( ! $nonce_ok ) {
				self::send_error( 'bad_nonce', 403 );
				return;
			}

			$raw_catalog = isset( $_REQUEST['catalog'] ) ? $_REQUEST['catalog'] : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
			if ( function_exists( 'wp_unslash' ) && is_string( $raw_catalog ) ) {
				$raw_catalog = wp_unslash( $raw_catalog ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
			$catalog = is_string( $raw_catalog ) ? strtolower( trim( $raw_catalog ) ) : 'all';
			if ( function_exists( 'sanitize_key' ) ) {
				$catalog = sanitize_key( $catalog );
			}
			if ( 'go' !== $catalog && 'zen' !== $catalog && 'all' !== $catalog ) {
				self::send_error( 'bad_request', 400 );
				return;
			}

			$catalogs = ( 'all' === $catalog ) ? array( 'go', 'zen' ) : array( $catalog );
			$results  = array();
			foreach ( $catalogs as $one ) {
				$verdict         = self::verdict_for( $one );
				$results[ $one ] = array(
					'verdict' => $verdict,
					'message' => self::message_for( $verdict, $one ),
				);
			}
			self::send_success( $results );
		} catch ( \Throwable $e ) {
			self::send_error( 'network_error', 200 );
		}
	}

	/**
	 * Credential-blind verdict for one catalog.
	 *
	 * Reuses the availability probe (with its 5-minute verdict cache) and
	 * never reads any `connectors_ai_*` option value. Fail-open: unknown
	 * catalogs and probe failures yield `network_error`, never fatal.
	 *
	 * @since 0.1.5
	 *
	 * @param string $catalog Catalog slug (go|zen).
	 * @return string Verdict string.
	 */
	public static function verdict_for( string $catalog ): string {
		try {
			$catalog = ( 'go' === $catalog ) ? 'go' : ( ( 'zen' === $catalog ) ? 'zen' : '' );
			if ( '' === $catalog ) {
				return \OpenCodeConnector\Availability\OpenCodeProviderAvailability::VERDICT_NETWORK_ERROR;
			}
			if ( ! class_exists( \OpenCodeConnector\Availability\OpenCodeProviderAvailability::class ) ) {
				return \OpenCodeConnector\Availability\OpenCodeProviderAvailability::VERDICT_NETWORK_ERROR;
			}
			$probe = new \OpenCodeConnector\Availability\OpenCodeProviderAvailability( $catalog );
			if ( ! method_exists( $probe, 'check_connection' ) ) {
				return \OpenCodeConnector\Availability\OpenCodeProviderAvailability::VERDICT_NETWORK_ERROR;
			}
			$verdict = $probe->check_connection();
			return is_string( $verdict ) && '' !== $verdict ? $verdict : \OpenCodeConnector\Availability\OpenCodeProviderAvailability::VERDICT_NETWORK_ERROR;
		} catch ( \Throwable $e ) {
			if ( class_exists( \OpenCodeConnector\Availability\OpenCodeProviderAvailability::class ) ) {
				return \OpenCodeConnector\Availability\OpenCodeProviderAvailability::VERDICT_NETWORK_ERROR;
			}
			return 'network_error';
		}
	}

	/**
	 * User-facing message for a verdict.
	 *
	 * @since 0.1.5
	 *
	 * @param string $verdict Verdict string.
	 * @param string $catalog Catalog slug.
	 * @return string Translated message.
	 */
	public static function message_for( string $verdict, string $catalog ): string {
		$label = ( 'go' === $catalog ) ? 'Go' : 'Zen';
		switch ( $verdict ) {
			case 'connected':
				/* translators: %s: catalog label (Go/Zen) */
				$text = function_exists( '__' ) ? __( '%s: connected.', 'duoport-connect-for-opencode' ) : '%s: connected.';
				break;
			case 'invalid_key':
				/* translators: %s: catalog label (Go/Zen) */
				$text = function_exists( '__' ) ? __( '%s: the API key was rejected. Check the key in Settings → Connectors.', 'duoport-connect-for-opencode' ) : '%s: the API key was rejected. Check the key in Settings → Connectors.';
				break;
			case 'not_configured':
				/* translators: %s: catalog label (Go/Zen) */
				$text = function_exists( '__' ) ? __( '%s: no API key configured. Add one in Settings → Connectors.', 'duoport-connect-for-opencode' ) : '%s: no API key configured. Add one in Settings → Connectors.';
				break;
			default:
				/* translators: %s: catalog label (Go/Zen) */
				$text = function_exists( '__' ) ? __( '%s: network error — could not reach the API. Try again shortly.', 'duoport-connect-for-opencode' ) : '%s: network error — could not reach the API. Try again shortly.';
				break;
		}
		if ( function_exists( 'sprintf' ) ) {
			return sprintf( $text, $label );
		}
		return $label;
	}

	/**
	 * Send a JSON success payload (with legacy fallback).
	 *
	 * @since 0.1.5
	 *
	 * @param mixed $data Payload.
	 * @return void
	 */
	private static function send_success( $data ): void {
		if ( function_exists( 'wp_send_json_success' ) ) {
			wp_send_json_success( $data );
			return;
		}
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		if ( function_exists( 'wp_json_encode' ) ) {
			echo wp_json_encode(
				array(
					'success' => true,
					'data'    => $data,
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode,WordPress.Security.EscapeOutput.OutputNotEscaped -- Legacy fallback when wp_json_encode() is unavailable.
			echo json_encode(
				array(
					'success' => true,
					'data'    => $data,
				)
			);
		}
	}

	/**
	 * Send a JSON error payload (with legacy fallback).
	 *
	 * @since 0.1.5
	 *
	 * @param string $code Error code.
	 * @param int    $http_status HTTP status.
	 * @return void
	 */
	private static function send_error( string $code, int $http_status = 200 ): void {
		if ( function_exists( 'wp_send_json_error' ) ) {
			if ( function_exists( 'status_header' ) ) {
				status_header( $http_status );
			}
			wp_send_json_error( array( 'code' => $code ) );
			return;
		}
		if ( ! headers_sent() && function_exists( 'status_header' ) ) {
			status_header( $http_status );
		}
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		$payload = array(
			'success' => false,
			'data'    => array( 'code' => $code ),
		);
		if ( function_exists( 'wp_json_encode' ) ) {
			echo wp_json_encode( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo json_encode( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.WP.AlternativeFunctions.json_encode_json_encode
		}
	}
}
