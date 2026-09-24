<?php
/**
 * Credential-blind connection result classification.
 *
 * @package OpenCodeConnector
 * @since 0.1.5
 */

declare(strict_types=1);

namespace OpenCodeConnector\Availability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classifies safe backend outcomes without retaining response credentials.
 */
final class ConnectionDiagnostics {

	/**
	 * Classify one backend response or transport exception.
	 *
	 * Only the error type is inspected; response bodies are never returned.
	 *
	 * @param int                       $status    HTTP status code, or zero for a transport failure.
	 * @param array<string, mixed>|null $data      Response data used only to identify CreditsError.
	 * @param \Throwable|null           $exception Transport exception, if any.
	 * @return array<string, mixed>
	 */
	public function classify( int $status, ?array $data = null, ?\Throwable $exception = null ): array {
		if ( null !== $exception || 0 === $status ) {
			return $this->result( 'network_error', false, false, false, 0, 'network_failure' );
		}
		if ( $status >= 200 && $status < 300 ) {
			return $this->result( 'available', true, true, true, $status, 'ok' );
		}
		if ( 401 === $status ) {
			$error_type = is_array( $data ) ? (string) ( $data['error']['type'] ?? '' ) : '';
			if ( 'CreditsError' === $error_type ) {
				return $this->result( 'credits_exhausted', true, true, true, $status, 'credits_error' );
			}
			return $this->result( 'invalid_auth', false, true, false, $status, 'invalid_auth' );
		}
		if ( 429 === $status ) {
			return $this->result( 'rate_limited', true, true, true, $status, 'rate_limited' );
		}
		if ( $status >= 500 && $status < 600 ) {
			return $this->result( 'server_error', true, true, false, $status, 'server_error' );
		}
		return $this->result( 'http_error', true, true, false, $status, 'http_error' );
	}

	/**
	 * Build a stable, non-sensitive result.
	 *
	 * @param string $state      Safe state name.
	 * @param bool   $configured Whether authorization was accepted.
	 * @param bool   $verified   Whether the backend was reached and identified.
	 * @param bool   $usable     Whether the connection is currently usable.
	 * @param int    $status     HTTP status or zero.
	 * @param string $code       Safe result code.
	 * @return array<string, mixed>
	 */
	private function result( string $state, bool $configured, bool $verified, bool $usable, int $status, string $code ): array {
		return array(
			'state'      => $state,
			'configured' => $configured,
			'verified'   => $verified,
			'usable'     => $usable,
			'status'     => $status,
			'code'       => $code,
		);
	}
}
