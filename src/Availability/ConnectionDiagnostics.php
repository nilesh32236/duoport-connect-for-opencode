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
 * positional argument bag, and so the state vocabulary has one home: the three
 * buckets below are the only definition of keyed, could-not-be-checked, and
 * definitively negative, and every caller reads them from here.
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
	 * Every member fails open: the caller falls back to last-known-good instead
	 * of flipping a valid key to not-connected.
	 *
	 * `unknown` is in this bucket, and that is the load-bearing part. It is what
	 * an unrecognised response classifies to — a 402, a 403, a status this
	 * plugin has no rule for. Such a response reached the gateway, so it is
	 * evidence that the request was made, never evidence about the key. Leaving
	 * `unknown` out meant isConfigured() returned false for those, so an
	 * upstream status change disconnected working keys. It reports
	 * `configured = true` precisely so callers keep last-known-good instead of
	 * trusting a status they cannot interpret.
	 *
	 * A 401 the gateway attributes to the requested model
	 * (`probe_model_unavailable`) is here for the same reason: the gateway
	 * reached and read the key and refused the MODEL.
	 *
	 * @var list<string>
	 */
	public const COULD_NOT_BE_CHECKED_STATES = array( 'uncheckable', 'network_error', 'server_error', 'unknown', 'probe_model_unavailable' );

	/**
	 * States that are a definitive negative for the credential.
	 *
	 * Only these clear the last-known-good flag; an unexpected upstream status
	 * must never destroy good state.
	 *
	 * @var list<string>
	 */
	public const DEFINITIVE_NEGATIVE_STATES = array( 'not_configured', 'invalid_key' );

	/**
	 * HTTP statuses the probe treats as recoverable probe-model drift.
	 *
	 * OpenCode retires and renames models, and a renamed model answers
	 * `model_not_found` at 404 or 400 at least as often as it answers 401, so
	 * both are recovered from by retrying the probe with a second model.
	 *
	 * This is the single definition of that set. It is read by
	 * OpenCodeProviderAvailability::isProbeModelDrift(), which decides the retry,
	 * and by probe(), which decides how long such a verdict is cached. An earlier
	 * revision carried a second copy inside unrelated deny-filter work: two
	 * copies of one fact is what let three separate defects through review in a
	 * single session, so there is exactly one list and both consumers read it.
	 *
	 * @var list<int>
	 */
	public const PROBE_MODEL_DRIFT_STATUSES = array( 400, 404 );

	/**
	 * 401 error types the gateway attributes to the requested model.
	 *
	 * Verified against the live gateway: a bad key answers 401 AuthError, while a
	 * model the gateway will not serve answers 401 ModelError with the same
	 * status. Only the model-side types are allowlisted, so an unlisted type
	 * (including a body-less 401) stays a definitive credential rejection rather
	 * than degrading to an unverifiable verdict that would keep a revoked key
	 * displayed as connected. Compared normalized because gateway type spelling
	 * and casing are not contractual: `ModelError`, `model_error` and
	 * `modelerror` are the same fact.
	 *
	 * @var list<string>
	 */
	private const MODEL_SIDE_ERROR_TYPES = array(
		'modelerror',
		'modelnotfound',
		'modelnotfounderror',
		'modelunavailable',
		'invalidmodel',
		'unsupportedmodel',
	);

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
	 * A 401 the gateway attributes to the requested model reports
	 * `probe_model_unavailable`, a distinct could-not-be-checked verdict, because
	 * the gateway reached and read the key and refused the MODEL. It is the
	 * signal the probe retries on; see
	 * OpenCodeProviderAvailability::isProbeModelDrift().
	 *
	 * A 401 that is NOT a recognised model-side refusal is a rejected
	 * credential whatever the body says: the allowlist is on the model-side
	 * types, so an upstream rename cannot turn a definitive signal into an
	 * unverifiable one.
	 *
	 * Every other unrecognised response is `unknown`, which is
	 * could-not-be-checked and configured. It is deliberately not a negative:
	 * the response reached the gateway, so it is not evidence about the key.
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
		// Read the type once, normalized, so every comparison in this class
		// agrees on spelling and casing.
		$error_type = $this->errorType( $data );
		if ( 401 === $status ) {
			if ( 'creditserror' === $error_type ) {
				return $this->noCredits( $status );
			}
			if ( in_array( $error_type, self::MODEL_SIDE_ERROR_TYPES, true ) ) {
				return $this->probeModelUnavailable( $status );
			}
			return $this->invalidKey( $status );
		}
		if ( 429 === $status ) {
			if ( 'freeusagelimiterror' === $error_type ) {
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
	 * `probe_model_unavailable` and `unknown` deliberately fall through to
	 * `could-not-be-checked`: neither is evidence about the credential.
	 *
	 * @param array<string, mixed> $diagnosis Detailed result from classify().
	 * @return string
	 */
	public function verify_state( array $diagnosis ): string {
		$state = isset( $diagnosis['state'] ) && is_string( $diagnosis['state'] ) ? $diagnosis['state'] : '';
		if ( in_array( $state, self::KEYED_STATES, true ) ) {
			return 'valid';
		}
		if ( in_array( $state, self::DEFINITIVE_NEGATIVE_STATES, true ) ) {
			return 'invalid_key';
		}
		return 'could-not-be-checked';
	}

	/**
	 * Build a result for a 401 the gateway attributes to the requested model.
	 *
	 * The gateway reached, read the key, and refused the *model* (401
	 * ModelError, verified live). The key is not proven bad, so callers keep
	 * last-known-good state and may retry with a different probe model.
	 *
	 * @param int $status HTTP status.
	 * @return array<string, mixed>
	 */
	public function probeModelUnavailable( int $status ): array {
		return $this->result( 'probe_model_unavailable', true, true, false, $status, 'probe_model_unavailable' );
	}

	/**
	 * Read the credential-blind backend error type.
	 *
	 * Read the credential-blind backend error type.
	 *
	 * @param array<string, mixed>|null $data Response data.
	 * @return string Empty string when the type is absent.
	 */
	private function errorType( ?array $data ): string {
		if ( ! is_array( $data ) ) {
			return '';
		}
		$raw = (string) ( $data['error']['type'] ?? '' );
		// Separators and casing are stripped so a gateway may spell the same fact
		// `ModelError`, `model_error`, or `model-error`. Both allowlists in this
		// class are written normalized, so every comparison agrees on spelling.
		return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $raw ) );
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
	 * The backend was reached and the key was read, so this is configured and
	 * verified, but it is not a usable connection. Callers must not treat it
	 * as a positive result.
	 *
	 * @param int $status HTTP status (defaults to 0 when no response exists).
	 * @return array<string, mixed>
	 */
	public function unknown( int $status = 0 ): array {
		return $this->result( 'unknown', true, true, false, $status, 'unknown' );
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
