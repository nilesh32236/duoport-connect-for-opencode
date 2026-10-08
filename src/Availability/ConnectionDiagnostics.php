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
	 * The state every response the classifier has no rule for falls to.
	 *
	 * Named so the probe can single this state out without spelling the string
	 * twice. It is a PERSISTENT member of `COULD_NOT_BE_CHECKED_STATES`, and it
	 * used to be the only one — which is why it carried no companion list of its
	 * own: a set containing one member is a second hand-maintained list in a
	 * class whose whole point is that the state lists have exactly one home.
	 * `PROBE_MODEL_UNAVAILABLE_STATE` joined it, so that home now has two
	 * members and the companion list below exists for that reason, not as
	 * tidying.
	 */
	public const UNKNOWN_STATE = 'unknown';

	/**
	 * A failure the gateway attributes to the probe model itself.
	 *
	 * `PROBE_MODEL` is a hard-coded constant and the probe sends only that model,
	 * so when the model is retired upstream the probe fails on every site at
	 * once. Without a distinct state for that, the outcome is read through the
	 * rules meant for the CREDENTIAL — and a 401, which this plugin has to treat
	 * as a proven bad key so a revoked key goes immediately, deletes the
	 * last-known-good flag. That flag is the fallback `isConfigured()` reads, so
	 * one retired model disconnects every key that was valid when it was
	 * entered: the mass false-invalidation this state exists to stop.
	 *
	 * It is deliberately NOT `unknown`. `unknown` means "no rule matched, this
	 * response says nothing about anything"; drift means "a rule matched, and
	 * what it matched is about the probe". Collapsing the two would make a real,
	 * diagnosable, upstream change indistinguishable from an unrelated 400.
	 */
	public const PROBE_MODEL_UNAVAILABLE_STATE = 'probe_model_unavailable';

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
	 * `unknown` is the load-bearing member, and its absence was the bug this
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
	 * `probe_model_unavailable` is in here for the same reason and not only for
	 * it: the responses it names would otherwise reach the definitive-negative
	 * bucket on a 401, where being absent would cost a credential verdict.
	 *
	 * @var list<string>
	 */
	public const COULD_NOT_BE_CHECKED_STATES = array( 'uncheckable', 'network_error', 'server_error', self::UNKNOWN_STATE, self::PROBE_MODEL_UNAVAILABLE_STATE );

	/**
	 * Uncheckable states that do not resolve on their own.
	 *
	 * These take the long, jittered cache window instead of the one-minute one.
	 * The minute is for transport failures and 5xx, which clear by themselves.
	 * A gateway answering with something this plugin has no rule for, or naming
	 * a retired probe model, will still be doing so in a minute — and the
	 * one-minute branch carries no jitter at all, so routing these there would
	 * make every affected site re-probe on the same sixty-second boundary,
	 * which is the synchronized stampede the jitter exists to prevent.
	 *
	 * Published here for the same reason the buckets are: a call site that
	 * decides "transient or persistent" with its own copy of the membership
	 * repeats the exact defect that `unknown`'s absence from the fallback bucket
	 * was. A reader of this list at a call site is fine; a second copy is not.
	 *
	 * @var list<string>
	 */
	public const PERSISTENT_UNCHECKABLE_STATES = array( self::UNKNOWN_STATE, self::PROBE_MODEL_UNAVAILABLE_STATE );

	/**
	 * States that are a definitive negative for the credential.
	 *
	 * Only these clear the last-known-good flag.
	 *
	 * @var list<string>
	 */
	public const DEFINITIVE_NEGATIVE_STATES = array( 'not_configured', 'invalid_key' );

	/**
	 * Error `type`/`code` values that name a MODEL as the thing that is missing.
	 *
	 * Stored already normalised — lower case with every non-alphanumeric
	 * character removed — because the same identifier arrives in several
	 * spellings: `model_not_found`, `model-not-found` and `ModelNotFound` are one
	 * value. Comparing raw strings would recognise the one spelling this plugin
	 * happened to see first and silently miss the next.
	 *
	 * Every entry names a model explicitly, which is why a bare `NotFoundError`
	 * is NOT here despite being a common 404 type: it names nothing at all, and
	 * treating it as model-scoped would label an endpoint-level 404 as probe
	 * drift. That case still lands on `unknown`, which is also indeterminate and
	 * also takes the persistent window — it just declines to claim a cause it
	 * cannot name.
	 *
	 * @var list<string>
	 */
	private const MODEL_SCOPED_ERROR_CODES = array(
		'modelnotfound',
		'unknownmodel',
		'unknownmodelerror',
		'modelnotavailable',
		'modelunavailable',
		'modelretired',
		'retiredmodelerror',
		'invalidmodelerror',
		'deprecatedmodelerror',
		'unsupportedmodel',
		'unsupportedmodelerror',
	);

	/**
	 * Error `type`/`code` values that name the CREDENTIAL as the rejected thing.
	 *
	 * These veto attribution. The rule that identifies a probe-model failure is
	 * deliberately permissive about what counts as evidence — `param: "model"`
	 * alone is enough — and permissive in the direction that protects working
	 * keys. The risk that creates is the opposite one: a genuinely revoked key
	 * whose response mentions the model somewhere stops being reported as
	 * revoked, and the flag this plugin is built around keeps reporting a dead
	 * credential as good for another 30 days. A response that names the
	 * credential is the strongest evidence available that the model was not the
	 * subject, so it ends the search.
	 *
	 * `insufficient_permissions` is deliberately NOT here. "Your key cannot reach
	 * this model" is the drift case, not a credential verdict: the key is what the
	 * user entered and it still works for everything else.
	 *
	 * @var list<string>
	 */
	private const CREDENTIAL_SCOPED_ERROR_CODES = array(
		'invalidapikey',
		'invalidkey',
		'unauthorized',
		'authenticationerror',
		'authenticationfailed',
		'invalidtoken',
		'expiredtoken',
		'invalidauthorization',
	);

	/**
	 * Message fragments that mean the CREDENTIAL is the rejected thing.
	 *
	 * The same veto as `CREDENTIAL_SCOPED_ERROR_CODES`, expressed the way
	 * gateways actually send it. `code` and `type` are optional in every
	 * OpenAI-compatible error schema, so a 401 whose entire verdict is prose —
	 * "Invalid API key provided", which is what OpenAI itself returns — carries
	 * neither, and a veto reading only those two fields finds nothing to act
	 * on. `param: "model"` then fires and a revoked key is held open for 30 days
	 * on the strength of a sentence that also said the key was dead.
	 *
	 * Normalised for the same reason as the code list, and compared against a
	 * normalised message so a hyphenated model name and a spaced phrase both
	 * match. Deliberately excludes anything a MODEL-only failure would contain,
	 * so "your key does not have access to model X" still reads as drift.
	 *
	 * @var list<string>
	 */
	private const CREDENTIAL_SCOPED_PHRASES = array(
		'invalidapikey',
		'incorrectapikey',
		'unrecognizedapikey',
		'unrecognisedapikey',
		'invalidkey',
		'badapikey',
		'missingapikey',
		'noapikey',
		'apikeynotfound',
		'unauthorized',
		'unauthorised',
		'authenticationfailed',
		'authenticationerror',
		'invalidtoken',
		'expiredtoken',
		'invalidauthorization',
	);

	/**
	 * Message fragments that mean the MODEL is gone.
	 *
	 * Only ever consulted when the message ALSO names the probe model, so these
	 * phrases never have to carry the whole weight of the attribution. Kept as
	 * plain lowercase substrings because that is the shape the messages arrive
	 * in; a phrase that needs a regex is a sign the rule is getting less
	 * specific than it should be.
	 *
	 * @var list<string>
	 */
	private const MODEL_GONE_PHRASES = array(
		'does not exist',
		'not found',
		'no such model',
		'unknown model',
		'is not available',
		'no longer available',
		'not available for this key',
		'do not have access',
		'does not have access',
		'has been deprecated',
		'is deprecated',
		'has been retired',
		'is retired',
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
	 * $probe_model is the model the request ASKED FOR, and it is what turns a
	 * model-scoped failure from a credential verdict into an indeterminate one.
	 * It is a parameter rather than a property because the attribution is only
	 * valid for a request whose model this plugin chose: the same body is a real
	 * error to a user whose own model is missing, and the only two callers that
	 * pass it send nothing but `PROBE_MODEL`. Passing null — the default —
	 * leaves classification exactly as it was, so a caller with no probe context
	 * cannot accidentally inherit the exemption.
	 *
	 * @param int                       $status      HTTP status code, or zero for a transport failure.
	 * @param array<string, mixed>|null $data        Response data used only to identify the error type.
	 * @param \Throwable|null           $exception   Transport exception, if any.
	 * @param string|null               $probe_model Model the probe asked for, when the caller has one.
	 * @return array<string, mixed>
	 */
	public function classify( int $status, ?array $data = null, ?\Throwable $exception = null, ?string $probe_model = null ): array {
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
			// Before the definitive negative, not after it. A gateway may answer
			// 401 because the key cannot reach THIS model — for one key, or for
			// every key at once when the model is retired upstream. Either way the
			// credential is not what was rejected, and clearing last-known-good
			// here is the mass false-invalidation this branch would otherwise cause.
			if ( $this->isProbeModelDrift( $data, $probe_model ) ) {
				return $this->probeModelUnavailable( $status );
			}
			return $this->invalidKey( $status );
		}
		if ( 429 === $status ) {
			if ( 'FreeUsageLimitError' === $this->errorType( $data ) ) {
				return $this->freeTierLimit( $status );
			}
			// Deliberately NOT attributed. 429 is the gateway saying it accepted
			// the credential and refused the request for capacity reasons; that is
			// proof the key works, and downgrading it to indeterminate would stop
			// a healthy key refreshing the very flag this exemption preserves.
			return $this->rateLimited( $status );
		}
		if ( $status >= 500 && $status < 600 ) {
			return $this->uncheckable( $status );
		}
		// Ahead of the generic fallthrough for the same reason as the 401 branch:
		// `unknown` is right for a response that says nothing, and wrong for one
		// that has already told us exactly what is wrong — with the probe.
		if ( $this->isProbeModelDrift( $data, $probe_model ) ) {
			return $this->probeModelUnavailable( $status );
		}
		return $this->unknown( $status );
	}

	/**
	 * Whether a response is attributable to the probe model being unavailable.
	 *
	 * Every rule below needs POSITIVE evidence that the MODEL is the subject.
	 * The tempting shortcut is to treat a bare 404 as drift, because drift is
	 * usually a 404 — and that shortcut is what would destroy this class's
	 * authority. A 404 with no body names no model, and treating it as drift
	 * would make every unrecognised response indistinguishable from a retired
	 * model, which is precisely the over-reach that lets a genuine credential
	 * problem hide behind a plausible label. So the model must be named, either
	 * by a model-scoped code, by `param: "model"`, or by appearing in a message
	 * that also says it is gone.
	 *
	 * @param array<string, mixed>|null $data        Response data.
	 * @param string|null               $probe_model Model the probe asked for, or null for no probe context.
	 * @return bool
	 */
	private function isProbeModelDrift( ?array $data, ?string $probe_model ): bool {
		if ( null === $probe_model || '' === trim( $probe_model ) || ! is_array( $data ) || ! isset( $data['error'] ) || ! is_array( $data['error'] ) ) {
			return false;
		}
		$error   = $data['error'];
		$code    = $this->normalize( (string) ( $error['code'] ?? '' ) );
		$type    = $this->normalize( (string) ( $error['type'] ?? '' ) );
		$message = $this->normalize( (string) ( $error['message'] ?? '' ) );

		// Veto first, and for every rule below. A response that names the
		// credential has told us the credential is what was rejected; nothing
		// later in this method can make the model the subject after that.
		//
		// The message is consulted alongside code and type, and it has to be.
		// Both fields are optional in every OpenAI-compatible error schema, so
		// the common 401 — "Invalid API key provided", type
		// `invalid_request_error`, no `code` at all — carries no structural
		// credential signal whatsoever. Reading only the two fields let
		// `param: "model"` fire on it and hold a revoked key open for 30 days.
		if ( in_array( $code, self::CREDENTIAL_SCOPED_ERROR_CODES, true )
			|| in_array( $type, self::CREDENTIAL_SCOPED_ERROR_CODES, true )
			|| $this->containsAnyOf( $message, self::CREDENTIAL_SCOPED_PHRASES ) ) {
			return false;
		}

		// `param: "model"` is the OpenAI-compatible way of saying which part of
		// the request was rejected, and it is model-scoped by construction.
		if ( 'model' === $this->normalize( (string) ( $error['param'] ?? '' ) ) ) {
			return true;
		}

		// A type or code that names a model is model-scoped by construction, so
		// it does not also have to appear in the message.
		if ( in_array( $code, self::MODEL_SCOPED_ERROR_CODES, true ) || in_array( $type, self::MODEL_SCOPED_ERROR_CODES, true ) ) {
			return true;
		}

		// Everything else has to make the model the SUBJECT as well as the
		// problem, so the probe model itself has to appear in the message.
		//
		// BOTH sides are normalised, and that is load-bearing rather than
		// cosmetic. Model ids routinely contain characters the normaliser strips —
		// `deepseek-v4-flash` normalises to `deepseekv4flash` — so matching a
		// normalised needle against a raw lowercased message never matches, and
		// the whole rule silently stops working for exactly the model this plugin
		// probes with. The phrases are normalised on the same footing; comparing
		// one normalised string against a raw one would reintroduce the same
		// defect on the phrase side, where every phrase contains a space.
		if ( '' === $message || ! str_contains( $message, $this->normalize( $probe_model ) ) ) {
			return false;
		}
		return $this->containsAnyOf( $message, self::MODEL_GONE_PHRASES );
	}

	/**
	 * Whether a normalised string contains any normalised phrase.
	 *
	 * Both sides are already normalised, so this is a plain substring test. The
	 * phrases are constants and cannot be pre-normalised at declaration without
	 * a helper, so they are normalised here — which is why this helper exists
	 * at all rather than an inline `foreach`.
	 *
	 * @param string   $message  Normalised message.
	 * @param string[] $phrases  Raw phrases, normalised on the fly.
	 * @return bool
	 */
	private function containsAnyOf( string $message, array $phrases ): bool {
		if ( '' === $message ) {
			return false;
		}
		foreach ( $phrases as $phrase ) {
			if ( str_contains( $message, $this->normalize( $phrase ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reduce an error identifier to comparable form.
	 *
	 * Lower case with every non-alphanumeric character dropped, so
	 * `model_not_found`, `Model-Not-Found` and `modelnotfound` are one value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function normalize( string $value ): string {
		return strtolower( (string) preg_replace( '/[^a-zA-Z0-9]/', '', $value ) );
	}

	/**
	 * Whether a state proves the key was accepted by the gateway.
	 *
	 * Single owner for the fail-open safety policy: every caller that decides
	 * "valid key reads as connected" goes through here instead of restating
	 * the list, so a new keyed state added to `KEYED_STATES` cannot be
	 * forgotten at a call site and silently flip valid users to
	 * not-connected.
	 *
	 * @since 0.1.8
	 *
	 * @param string $state Classified state name.
	 * @return bool
	 */
	public static function isConfiguredState( string $state ): bool {
		return in_array( $state, self::KEYED_STATES, true );
	}

	/**
	 * Whether a state says nothing about the credential.
	 *
	 * Such states fall back to last-known-good instead of reporting a working
	 * key as not connected. See `isConfiguredState()` for why this lives
	 * here rather than at the call sites.
	 *
	 * @since 0.1.8
	 *
	 * @param string $state Classified state name.
	 * @return bool
	 */
	public static function isUncheckableState( string $state ): bool {
		return in_array( $state, self::COULD_NOT_BE_CHECKED_STATES, true );
	}

	/**
	 * Whether a state is a definitive negative for the credential.
	 *
	 * Only these states may clear the last-known-good flag.
	 *
	 * @since 0.1.8
	 *
	 * @param string $state Classified state name.
	 * @return bool
	 */
	public static function isDefinitiveNegativeState( string $state ): bool {
		return in_array( $state, self::DEFINITIVE_NEGATIVE_STATES, true );
	}

	/**
	 * Whether an uncheckable state takes the long, jittered cache window.
	 *
	 * @since 0.1.8
	 *
	 * @param string $state Classified state name.
	 * @return bool
	 */
	public static function isPersistentUncheckableState( string $state ): bool {
		return in_array( $state, self::PERSISTENT_UNCHECKABLE_STATES, true );
	}

	/**
	 * Every state string this class can produce.
	 *
	 * Test seam for the exhaustiveness ratchet: a new factory that adds a
	 * state without a bucket fails the classification test instead of
	 * silently landing on a fail-closed default at a call site.
	 *
	 * @since 0.1.8
	 *
	 * @return list<string>
	 */
	public static function allStates(): array {
		$states = array_merge(
			self::KEYED_STATES,
			self::COULD_NOT_BE_CHECKED_STATES,
			self::DEFINITIVE_NEGATIVE_STATES
		);
		return array_values( array_unique( $states ) );
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
		if ( self::isConfiguredState( $state ) ) {
			return 'valid';
		}
		if ( self::isDefinitiveNegativeState( $state ) ) {
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
	 * Build a probe-model-unavailable result.
	 *
	 * The gateway was reached and identified — that is the `unknown()` truth as
	 * well — but what it rejected was the MODEL this plugin chose, so it says
	 * nothing about the credential and must not be counted as a verdict on it.
	 * `configured` is therefore false for the same reason `unknown()`'s is: a
	 * consumer keying on that flag must not get an unconditional fail-open,
	 * because the fallback is resolved one level up by `isConfigured()`.
	 *
	 * @param int $status HTTP status.
	 * @return array<string, mixed>
	 */
	public function probeModelUnavailable( int $status ): array {
		return $this->result( self::PROBE_MODEL_UNAVAILABLE_STATE, false, true, false, $status, 'probe_model_unavailable' );
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
	 * Every member of COULD_NOT_BE_CHECKED_STATES resolves through the same
	 * last-known-good path, so they must project the same credential flags:
	 * none of them adjudicated the credential, so none may report
	 * `configured = true`. That is the same shape as `uncheckable()`, which
	 * is what a 5xx actually classifies to today.
	 *
	 * @param int $status HTTP status.
	 * @return array<string, mixed>
	 */
	public function serverError( int $status ): array {
		return $this->result( 'server_error', false, false, false, $status, 'server_error' );
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
