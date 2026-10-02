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
 *
 * Each verdict has a named factory so callers cannot build a result from a
 * positional argument bag, and so the state vocabulary has one home.
 */
final class ConnectionDiagnostics {

	/**
	 * States that prove the key was accepted by the gateway.
	 *
	 * `free_tier_limit` is a Zen free-tier quota stop, which is still a valid
	 * key, so it counts as configured even though nothing is usable.
	 *
	 * @var list<string>
	 */
	public const KEYED_STATES = array( 'verified', 'no_credits', 'rate_limited', 'free_tier_limit' );

	/**
	 * States that say nothing about the credential.
	 *
	 * Every member falls back to last-known-good instead of reporting a working
	 * key as not connected.
	 *
	 * `unknown` is the load-bearing member, and its absence is the bug this
	 * constant documents. It is what every unrecognised response classifies to:
	 * a 400, a 402, a 403, any status the gateway introduces that this plugin
	 * has no rule for. Such a response reached the gateway, so it is evidence
	 * that the request was made and never evidence about the key — which is
	 * why membership here is what makes the caller fall back to last-known-good
	 * instead of reporting a working key as not configured. It was missing from
	 * the fallback list, so a 400/404 — the shape OpenCode returns after it renames
	 * or retires a model — missed the bucket and was reported as not
	 * configured, on the default branch, to sites whose keys were fine.
	 *
	 * @var list<string>
	 */
	public const COULD_NOT_BE_CHECKED_STATES = array( 'uncheckable', 'network_error', 'server_error', 'unknown' );

	/**
	 * States that are a definitive negative for the credential.
	 *
	 * Only these clear the last-known-good flag.
	 *
	 * @var list<string>
	 */
	public const DEFINITIVE_NEGATIVE_STATES = array( 'not_configured', 'invalid_key' );

	/**
	 * Classify one backend response or transport exception.
	 *
	 * Only the error type is inspected; response bodies are never returned.
	 *
	 * Fail-open contract: quota exhaustion (401 CreditsError), rate limiting
	 * (429), and Zen free-tier quota stops (429 FreeUsageLimitError) report a
	 * configured key; 5xx and transport failures report a distinct
	 * could-not-be-checked verdict so callers can preserve last-known-good
	 * state instead of flipping to not-connected.
	 *
	 * @param int                       $status    HTTP status code, or zero for a transport failure.
	 * @param array<string, mixed>|null $data      Response data used only to identify the error type.
	 * @param \Throwable|null           $exception Transport exception, if any.
	 * @return array<string, mixed>
	 */
	public function classify( int $status, ?array $data = null, ?\Throwable $exception = null ): array {
		if ( null !== $exception || 0 === $status ) {
			return $this->uncheckable( 0 );
		}
		if ( $status >= 200 && $status < 300 ) {
			return $this->verified( $status );
		}
		if ( 401 === $status ) {
			if ( 'CreditsError' === $this->errorType( $data ) ) {
				return $this->noCredits( $status );
			}
			return $this->invalidKey( $status );
		}
		if ( 429 === $status ) {
			if ( 'FreeUsageLimitError' === $this->errorType( $data ) ) {
				return $this->freeTierLimit( $status );
			}
			return $this->rateLimited( $status );
		}
		if ( $status >= 500 && $status < 600 ) {
			return $this->uncheckable( $status );
		}
		return $this->unknown( $status );
	}

	/**
	 * Map a detailed diagnosis to an explicit verification state.
	 *
	 * Returns one of `valid`, `invalid_key`, or `could-not-be-checked` so the
	 * settings page can report genuine credential verification, distinct from
	 * the lightweight availability probe.
	 *
	 * @param array<string, mixed> $diagnosis Detailed result from classify().
	 * @return string
	 */
	public function verify_state( array $diagnosis ): string {
		$state = isset( $diagnosis['state'] ) && is_string( $diagnosis['state'] ) ? $diagnosis['state'] : '';
		// Read the published buckets rather than a second copy of each list.
		// The probe defect this class documents was one missing entry in one of
		// two hand-maintained lists, so any list spelled out again here is the
		// same hazard wearing a different hat.
		if ( in_array( $state, self::KEYED_STATES, true ) ) {
			return 'valid';
		}
		if ( in_array( $state, self::DEFINITIVE_NEGATIVE_STATES, true ) ) {
			return 'invalid_key';
		}
		return 'could-not-be-checked';
	}

	/**
	 * Read the credential-blind backend error type.
	 *
	 * @param array<string, mixed>|null $data Response data.
	 * @return string Empty string when the type is absent.
	 */
	private function errorType( ?array $data ): string {
		return is_array( $data ) ? (string) ( $data['error']['type'] ?? '' ) : '';
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
	 * Build a verified result.
	 *
	 * Persists the probed 2xx status verbatim so 201/204 responses keep their
	 * status in the cached result. The default 200 is only for the legacy
	 * boolean-cache path, which has no status to report.
	 *
	 * @param int $status HTTP status (defaults to 200 for legacy callers).
	 * @return array<string, mixed>
	 */
	public function verified( int $status = 200 ): array {
		return $this->result( 'verified', true, true, true, $status, 'ok' );
	}

	/**
	 * Build an unknown result for a response that matched no known rule.
	 *
	 * The backend was reached and identified — that is what separates this from
	 * `uncheckable()`, where it was never reached — but the response says
	 * nothing about the credential, so `configured` is false: this result must
	 * not assert a credential is set up when the probe could not determine it.
	 *
	 * `isConfigured()` is the surface that applies the fail-open: it resolves an
	 * unrecognised response through last-known-good, which is false on a cold
	 * cache. A consumer keying on `['configured']` must not get an
	 * unconditional fail-open that the boolean projection deliberately withholds.
	 * Callers must not treat this as a positive result.
	 *
	 * @param int $status HTTP status (defaults to 0 when no response exists).
	 * @return array<string, mixed>
	 */
	public function unknown( int $status = 0 ): array {
		return $this->result( 'unknown', false, true, false, $status, 'unknown' );
	}

	/**
	 * Build a could-not-be-checked result for server, transport, and
	 * concurrent-probe failures.
	 *
	 * Quota states have their own verdicts and never reach this path: 429 maps
	 * to rateLimited() or freeTierLimit(), and 401 with a credits error maps
	 * to noCredits(). Callers preserve last-known-good configured state on
	 * this verdict instead of flipping to not-connected.
	 *
	 * @param int $status HTTP status, or zero for a transport failure.
	 * @return array<string, mixed>
	 */
	public function uncheckable( int $status = 0 ): array {
		return $this->result( 'uncheckable', false, false, false, $status, 'could_not_be_checked' );
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
	 * Build a free-tier usage-limit result.
	 *
	 * A Zen free-tier quota stop still proves the key is valid, so this is
	 * configured and verified but not currently usable.
	 *
	 * @param int $status HTTP status.
	 * @return array<string, mixed>
	 */
	public function freeTierLimit( int $status ): array {
		return $this->result( 'free_tier_limit', true, true, false, $status, 'free_usage_limit' );
	}

	/**
	 * Build a server-error result.
	 *
	 * The classifier no longer produces this state; it is retained so a
	 * legacy cached value can still be interpreted fail-open by callers.
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
