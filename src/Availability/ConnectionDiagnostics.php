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
			return $this->networkError();
		}
		if ( $status >= 200 && $status < 300 ) {
			return $this->verified( $status );
		}
		if ( 401 === $status ) {
			$error_type = is_array( $data ) ? (string) ( $data['error']['type'] ?? '' ) : '';
			if ( 'CreditsError' === $error_type ) {
				return $this->noCredits( $status );
			}
			return $this->invalidKey( $status );
		}
		if ( 429 === $status ) {
			return $this->rateLimited( $status );
		}
		if ( $status >= 500 && $status < 600 ) {
			return $this->serverError( $status );
		}
		return $this->unknown( $status );
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
	 * @param int $status HTTP status (defaults to 200 for legacy callers).
	 * @return array<string, mixed>
	 */
	public function verified( int $status = 200 ): array {
		return $this->result( 'verified', true, true, true, $status, 'ok' );
	}

	/**
	 * Build an unknown result for a concurrent probe.
	 *
	 * @param int $status HTTP status (defaults to 0 when no response exists).
	 * @return array<string, mixed>
	 */
	public function unknown( int $status = 0 ): array {
		$configured = 0 !== $status;
		return $this->result( 'unknown', $configured, $configured, false, $status, 'unknown' );
	}

	/**
	 * Build a network-error result.
	 *
	 * @return array<string, mixed>
	 */
	public function networkError(): array {
		return $this->result( 'network_error', false, false, false, 0, 'network_failure' );
	}

	/**
	 * Build a no-credits result (valid key, empty balance).
	 *
	 * @param int $status HTTP status.
	 * @return array<string, mixed>
	 */
	public function noCredits( int $status ): array {
		return $this->result( 'no_credits', true, true, false, $status, 'credits_error' );
	}

	/**
	 * Build an invalid-key result.
	 *
	 * @param int $status HTTP status.
	 * @return array<string, mixed>
	 */
	public function invalidKey( int $status ): array {
		return $this->result( 'invalid_key', false, true, false, $status, 'invalid_key' );
	}

	/**
	 * Build a rate-limited result.
	 *
	 * @param int $status HTTP status.
	 * @return array<string, mixed>
	 */
	public function rateLimited( int $status ): array {
		return $this->result( 'rate_limited', true, true, false, $status, 'rate_limited' );
	}

	/**
	 * Build a server-error result.
	 *
	 * @param int $status HTTP status.
	 * @return array<string, mixed>
	 */
	public function serverError( int $status ): array {
		return $this->result( 'server_error', true, true, false, $status, 'server_error' );
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
