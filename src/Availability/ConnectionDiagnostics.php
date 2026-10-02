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
 * positional argument bag, and so the state vocabulary has one home: the
 * state buckets below are the only definition of keyed, could-not-be-checked,
 * and definitively negative, and every caller reads them from here.
 */
final class ConnectionDiagnostics {

	/**
	 * States that prove the key was accepted by the gateway.
	 *
	 * `free_tier_limit` is a Zen free-tier quota stop, which is still a valid
	 * key, so it counts as configured even though nothing is usable.
	 *
	 * @since 0.1.8
	 *
	 * @var list<string>
	 */
	public const KEYED_STATES = array( 'verified', 'no_credits', 'rate_limited', 'free_tier_limit' );

	/**
	 * States that say nothing about the credential.
	 *
	 * 5xx, transport failures, a response that matched no known rule
	 * (`unknown`), and a 401 the gateway attributes to the requested model
	 * (`probe_model_unavailable`) all preserve last-known-good state instead
	 * of flipping a valid key to not-connected.
	 *
	 * @since 0.1.8
	 *
	 * @var list<string>
	 */
	public const COULD_NOT_BE_CHECKED_STATES = array( 'uncheckable', 'network_error', 'server_error', 'unknown', 'probe_model_unavailable' );

	/**
	 * States that are a definitive negative for the credential.
	 *
	 * Only these clear the 30-day last-known-good flag; an unexpected upstream
	 * status must never destroy good state.
	 *
	 * @since 0.1.8
	 *
	 * @var list<string>
	 */
	public const DEFINITIVE_NEGATIVE_STATES = array( 'not_configured', 'invalid_key' );

	/**
	 * 401 error types the gateway attributes to the requested model.
	 *
	 * Verified against the live gateway: a bad key answers 401 AuthError,
	 * while a model the gateway will not serve answers 401 ModelError with the
	 * same status. Only the model-side types are allowlisted, so an unlisted
	 * type (including a body-less 401) stays a definitive credential rejection
	 * instead of silently degrading to an unverifiable verdict that would keep
	 * a revoked key displayed as connected for the whole last-known-good
	 * window. Compared normalized because gateway type spelling and casing are
	 * not contractual: `ModelError`, `model_error` and `modelerror` are the
	 * same fact.
	 *
	 * @since 0.1.8
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
	 * 401 error type for a valid key with an empty balance.
	 *
	 * @since 0.1.8
	 */
	private const CREDITS_ERROR_TYPE = 'creditserror';

	/**
	 * 429 error type for a Zen free-tier quota stop.
	 *
	 * @since 0.1.8
	 */
	private const FREE_TIER_ERROR_TYPE = 'freeusagelimiterror';

	/**
	 * Classify one backend response or transport exception.
	 *
	 * Only the error type is inspected; response bodies are never returned.
	 *
	 * Fail-open contract (Design A, the default): quota exhaustion (401
	 * CreditsError), rate limiting (429), and Zen free-tier quota stops (429
	 * FreeUsageLimitError) report a configured key; a 401 the gateway
	 * attributes to the requested model (401 ModelError) reports a distinct
	 * probe-model-unavailable verdict, and 5xx and transport failures report a
	 * could-not-be-checked verdict. All of those preserve last-known-good state
	 * instead of flipping to not-connected.
	 *
	 * Design B is available behind the `duoport_probe_deny_unrecognized`
	 * filter and is OFF by default. When enabled it governs ONE bucket: the
	 * `unknown` fallthrough, where a response that reached the gateway proved
	 * nothing about the credential (402 and 403 included). That bucket is
	 * downgraded to a definitive `invalid_key`, which clears last-known-good.
	 *
	 * Design B deliberately does NOT govern the model-side 401 bucket, and that
	 * exclusion is load-bearing rather than cosmetic. `probe_model_unavailable`
	 * is the sole member of OpenCodeProviderAvailability::SETTLED_STATES, so it
	 * is what `isProbeModelDrift()` matches to retry with a second reviewed
	 * paid model. Denying it would remove the drift-recovery signal, and
	 * `invalid_key` is not a settled state, so a site that opted in could
	 * never recover from a retired probe model: last-known-good would be
	 * destroyed with no path back. That trades a bounded false "Connected" for
	 * an unbounded false "Not connected", so the model-side 401 stays exactly
	 * as it is. tests/Unit/ConnectionDiagnosticsTest.php guards this.
	 *
	 * Design B also does not govern 5xx or transport failures: those return
	 * `uncheckable` above, before the filter is consulted. It makes no claim
	 * about them in either direction.
	 *
	 * A 401 that is not a recognised model-side refusal is a rejected
	 * credential, whatever the body says: a gateway that renames its error
	 * type must not turn a definitive signal into an unverifiable one.
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
			if ( self::CREDITS_ERROR_TYPE === $error_type ) {
				return $this->noCredits( $status );
			}
			if ( in_array( $error_type, self::MODEL_SIDE_ERROR_TYPES, true ) ) {
				// Never routed through denyUnrecognized(): this verdict is the
				// drift-recovery signal, not an authorization decision.
				return $this->probeModelUnavailable( $status );
			}
			return $this->invalidKey( $status );
		}
		if ( 429 === $status ) {
			if ( self::FREE_TIER_ERROR_TYPE === $error_type ) {
				return $this->freeTierLimit( $status );
			}
			return $this->rateLimited( $status );
		}
		if ( $status >= 500 && $status < 600 ) {
			return $this->uncheckable( $status );
		}
		if ( $this->denyUnrecognized( $status, $error_type, 'unknown' ) ) {
			return $this->invalidKey( $status );
		}
		return $this->unknown( $status );
	}

	/**
	 * Whether an unrecognised 4xx should be denied rather than left unknown.
	 *
	 * Design A (this ships, and is the default): the `unknown` fallthrough
	 * reports a could-not-be-checked verdict that is still `configured`, so
	 * `isConfigured()` falls back to last-known-good and a transient upstream
	 * change cannot disconnect a working key.
	 *
	 * Design B (opt-in): that same response becomes a definitive negative
	 * (`invalid_key`, `configured = false`), clearing last-known-good.
	 *
	 * Scope is deliberately limited to the `unknown` bucket. It does not reach
	 * a model-side 401, which is the drift-recovery signal, nor 5xx and
	 * transport failures, which return `uncheckable` before this is called.
	 * See the classify() docblock for why the model-side exclusion is
	 * load-bearing.
	 *
	 * The filter defaults to false, so shipping this code is NOT the security
	 * decision: the posture a site runs is unchanged until a human opts in.
	 *
	 * @param int    $status     HTTP status code.
	 * @param string $error_type Normalised upstream error type, possibly empty.
	 * @param string $state      Fail-open state that would otherwise be returned.
	 * @return bool
	 */
	private function denyUnrecognized( int $status, string $error_type, string $state ): bool {
		if ( ! function_exists( 'apply_filters' ) ) {
			return false;
		}
		return (bool) apply_filters(
			'duoport_probe_deny_unrecognized',
			false,
			$status,
			$error_type,
			$state
		);
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
	 * Read the credential-blind backend error type, normalized.
	 *
	 * Separators and casing are stripped so a gateway may spell the same fact
	 * `ModelError`, `model_error`, or `model-error`.
	 *
	 * @param array<string, mixed>|null $data Response data.
	 * @return string Empty string when the type is absent.
	 */
	private function errorType( ?array $data ): string {
		if ( ! is_array( $data ) ) {
			return '';
		}
		$raw = (string) ( $data['error']['type'] ?? '' );
		return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $raw ) );
	}

	/**
	 * Build a result for a 401 the gateway attributes to the requested model.
	 *
	 * The gateway reached, read the key, and refused the *model* (401
	 * ModelError, verified live). The key is not proven bad, so callers keep
	 * last-known-good state and may retry with a different probe model.
	 *
	 * @since 0.1.8
	 *
	 * @param int $status HTTP status.
	 * @return array<string, mixed>
	 */
	public function probeModelUnavailable( int $status ): array {
		return $this->result( 'probe_model_unavailable', true, true, false, $status, 'probe_model_unavailable' );
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
	 * The classifier no longer produces this state: transport failures arrive
	 * as an exception and are classified as `uncheckable`. It is retained so a
	 * legacy cached value can still be interpreted fail-open by callers.
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
