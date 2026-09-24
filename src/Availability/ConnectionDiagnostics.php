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
			return $this->result( 'verified', true, true, true, $status, 'ok' );
		}
		if ( 401 === $status ) {
			$error_type = is_array( $data ) ? (string) ( $data['error']['type'] ?? '' ) : '';
			if ( 'CreditsError' === $error_type ) {
				return $this->result( 'no_credits', true, true, false, $status, 'credits_error' );
			}
			return $this->result( 'invalid_key', false, true, false, $status, 'invalid_key' );
		}
		if ( 429 === $status ) {
			return $this->result( 'rate_limited', true, true, false, $status, 'rate_limited' );
		}
		if ( $status >= 500 && $status < 600 ) {
			return $this->result( 'server_error', true, true, false, $status, 'server_error' );
		}
		return $this->result( 'unknown', true, true, false, $status, 'unknown' );
	}

	/**
	 * Build a not-configured result.
	 *
	 * @return array<string, mixed>
	 */
	public function notConfigured(): array {
		return $this->result( 'not_configured', false, false, false, 0, 'not_configured' );
	}

	/**
	 * Build a verified result for legacy boolean cache compatibility.
	 *
	 * @return array<string, mixed>
	 */
	public function verified(): array {
		return $this->result( 'verified', true, true, true, 200, 'ok' );
	}

	/**
	 * Build an unknown result for a concurrent probe.
	 *
	 * @return array<string, mixed>
	 */
	public function unknown(): array {
		return $this->result( 'unknown', false, false, false, 0, 'unknown' );
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
