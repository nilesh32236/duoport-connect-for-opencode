<?php
/**
 * Provider availability check.
 *
 * Probe-based availability check with transient caching.
 *
 * Facade half of the availability split: this class keeps the public
 * boolean contract (isConfigured()/diagnose()/getLastResult()/verify()),
 * the diagnostics seam, the credential-throwable rule, and the
 * last-known-good flag seam. The verification round-trip lives in
 * VerifyProbe, the availability round-trip in AvailabilityProbe, and the
 * shared request shape in ProbeRequest, so a probe edit never touches an
 * unrelated path.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

namespace OpenCodeConnector\Availability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Traits\WithHttpTransporterTrait;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;

/**
 * Probe-based availability check with transient caching.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */
final class OpenCodeProviderAvailability implements ProviderAvailabilityInterface, WithHttpTransporterInterface, WithRequestAuthenticationInterface {
	use WithHttpTransporterTrait;
	use WithRequestAuthenticationTrait;

	/**
	 * Probe model used to discriminate authentication state.
	 *
	 * A paid model is probed deliberately: a valid but empty-balance key
	 * answers 401 CreditsError (configured) versus other 401s for a bad key.
	 * Probing a free model instead would fail closed whenever that model is
	 * transiently unavailable upstream. The same model backs the opt-in
	 * verification probe.
	 *
	 * The cost of a constant is that this one is load-bearing for the whole
	 * plugin: every probe on every site sends exactly this model, so when it is
	 * retired upstream the failure is fleet-wide and instantaneous. That is why
	 * the probe hands this name to `ConnectionDiagnostics::classify()`, which
	 * uses it to separate "the credential was rejected" from "the model this
	 * plugin probes with is gone" — see PROBE_MODEL_UNAVAILABLE_STATE. Without
	 * that separation a retired model is indistinguishable from a revoked key,
	 * and the 401 rule this docblock above relies on would delete the
	 * last-known-good flag on every site at once. Retiring the constant is
	 * therefore a breaking change for anyone reading it: it has to come with a
	 * classifier rule for the model that replaces it.
	 *
	 * @since 0.1.6
	 */
	const PROBE_MODEL = 'deepseek-v4-flash';

	/**
	 * Narrow patterns that identify "the credential is absent" in a throwable.
	 *
	 * Matched case-insensitively against the throwable's own message, and only
	 * for throwables that are NOT \Error — see
	 * is_missing_credential_throwable() for why the class of the throwable
	 * carries more weight than the text in its message.
	 *
	 * Deliberately a whitelist. Every entry names the request authentication as
	 * unset, which is the only failure of `getRequestAuthentication()` that is a
	 * statement about the credential rather than about the process; the first
	 * two are verbatim the shipped SDK's and the SDK stub's wording.
	 *
	 * @since 0.1.9
	 *
	 * @var list<string>
	 */
	private const MISSING_CREDENTIAL_PATTERNS = array(
		// Verbatim from the shipped SDK trait.
		'requestauthenticationinterface instance not set',
		// Verbatim from the bundled SDK stub and any double built on it.
		'no request authentication',
		// Generic phrasings a host integration may produce.
		'request authentication is not set',
		'request authentication not set',
		'request authentication is not configured',
		'request authentication not configured',
		'no api key configured',
		'api key is not configured',
		'api key not configured',
	);

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string                     $catalog     Catalog slug.
	 * @param ConnectionDiagnostics|null $diagnostics Optional diagnostics double for tests.
	 */
	public function __construct( private readonly string $catalog, ?ConnectionDiagnostics $diagnostics = null ) {
		// Catalog is go or zen.
		$this->diagnostics_override = $diagnostics;
	}

	/**
	 * Optional diagnostics collaborator (test seam; defaults to canonical).
	 *
	 * @var ConnectionDiagnostics|null
	 */
	private ?ConnectionDiagnostics $diagnostics_override = null;

	/**
	 * Memoized diagnostics collaborator.
	 *
	 * ConnectionDiagnostics is stateless today, so sharing one instance is
	 * behavior-preserving — and it keeps the seam symmetric (an injected
	 * override and the canonical instance are both resolved once) so a
	 * future stateful collaborator does not silently get a fresh instance
	 * per probe.
	 *
	 * @var ConnectionDiagnostics|null
	 */
	private ?ConnectionDiagnostics $resolved_diagnostics = null;

	/**
	 * Flag store for this catalog (lazy).
	 *
	 * Its per-instance read memo is the single flag memo, so the facade
	 * reads through it rather than keeping a second copy in step.
	 *
	 * @since 0.1.8
	 *
	 * @var LastGoodStore|null
	 */
	private ?LastGoodStore $last_good_store = null;

	/**
	 * Availability probe for this catalog (lazy).
	 *
	 * Built once per instance so repeated isConfigured()/diagnose() calls
	 * in one request reuse the object graph instead of re-allocating it.
	 * Its closures capture $this, so a later setHttpTransporter() /
	 * setRequestAuthentication() is still honoured on the next run.
	 *
	 * @since 0.1.9
	 *
	 * @var AvailabilityProbe|null
	 */
	private ?AvailabilityProbe $availability_probe = null;

	/**
	 * Flag store for this catalog.
	 *
	 * @since 0.1.8
	 *
	 * @return LastGoodStore
	 */
	private function lastGoodStore(): LastGoodStore {
		if ( null === $this->last_good_store ) {
			$this->last_good_store = new LastGoodStore( $this->catalog );
		}
		return $this->last_good_store;
	}

	/**
	 * Whether the provider is configured, preserving the legacy boolean contract.
	 *
	 * Fail-open: quota exhaustion, rate limiting, and previously cached
	 * server errors read as configured; could-not-be-checked verdicts (5xx,
	 * transport failures, concurrent probes, and any UNRECOGNISED response)
	 * preserve last-known-good state instead of flipping valid keys to
	 * not-connected. Unkeyed installs still read as not configured.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		$result = $this->probe();
		$state  = isset( $result['state'] ) && is_string( $result['state'] ) ? $result['state'] : '';
		// Definitive, keyed outcomes stay configured. `free_tier_limit` is a
		// Zen free-tier quota stop, which is still a valid key.
		if ( ConnectionDiagnostics::isConfiguredState( $state ) ) {
			return true;
		}
		// Could-not-be-checked outcomes fall back to last-known-good instead of
		// flipping a valid key to not-connected during an outage.
		if ( ConnectionDiagnostics::isUncheckableState( $state ) ) {
			return $this->readLastGood();
		}
		// Definitive negatives are the only states that may report
		// not-configured: `invalid_key` is a proven bad credential and
		// `not_configured` is a proven missing one. Both have adjudicated the
		// credential, which is what earns them the right to end the call false.
		if ( ConnectionDiagnostics::isDefinitiveNegativeState( $state ) ) {
			return false;
		}
		// Everything else is in NO bucket. This used to be a bare `return false`,
		// which read as "unknown state, so assume the worst" — the same
		// fail-closed assumption applyLastGood() was just hardened against, and
		// it was the last one standing. The buckets are exhaustive over today's
		// vocabulary, so this is currently unreachable, which is exactly why it
		// was harmless and exactly when it would be reached: the day someone adds
		// a state without giving it a bucket. Fail open on whatever the flag
		// says, for the same reason the flag survives: a state that adjudicated
		// nothing is not evidence against the credential.
		return $this->readLastGood();
	}

	/**
	 * Whether a throwable raised while resolving credentials PROVES the key is absent.
	 *
	 * `getRequestAuthentication()` is third-party SDK surface, and it can throw
	 * for a great many reasons that say nothing whatsoever about the credential:
	 * the Connectors registry not being registered yet, a `connectors_ai_*`
	 * filter that throws, an autoload or SDK bootstrap fault, a plain \Error
	 * from a half-installed SDK. This class used to catch every \Throwable on
	 * that path and call it a proven bad key, which contradicted the rule the
	 * rest of this file is built around ("only a proven invalid or missing key
	 * reports not configured") and paid for it in the worst currency available:
	 * `writeLastGood(false)` deletes the 30-day last-known-good transient, so an
	 * infrastructure blip on a site whose gateway was about to answer 400/404
	 * anyway left it reporting not-connected with nothing to explain why.
	 *
	 * So the default here is the conservative one: an unrecognised throwable is
	 * treated as "the key could not be read", which routes to `uncheckable()`
	 * and therefore falls back on last-known-good instead of adjudicating the
	 * credential. Only an explicit, narrow "authentication is not configured"
	 * signal counts as an absent key.
	 *
	 * \Error is excluded before the message is even read. \Error and its
	 * subclasses (\TypeError, \BadMethodCallException, \ParseError) mean the
	 * PROCESS is broken, not that a user left a field blank, and their messages
	 * are code-shaped rather than credential-shaped; letting a string match
	 * there would re-open the hole this closes.
	 *
	 * Public so both probe collaborators share the single rule instead of
	 * restating the whitelist.
	 *
	 * @since 0.1.9
	 *
	 * @param \Throwable $exception Throwable raised while resolving credentials.
	 * @return bool True only when the credential is proven absent.
	 */
	public static function is_missing_credential_throwable( \Throwable $exception ): bool {
		if ( $exception instanceof \Error ) {
			return false;
		}
		$message = strtolower( $exception->getMessage() );
		if ( '' === $message ) {
			return false;
		}
		foreach ( self::MISSING_CREDENTIAL_PATTERNS as $pattern ) {
			if ( str_contains( $message, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read or construct the diagnostics collaborator safely.
	 *
	 * @since 0.1.9
	 *
	 * @return ConnectionDiagnostics|null
	 */
	private function diagnostics_or_null(): ?ConnectionDiagnostics {
		try {
			return $this->diagnostics();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			// Never fatal: an unbuildable helper degrades to "unchecked".
			return null;
		}
	}

	/**
	 * Run or reuse the detailed, credential-blind probe result.
	 *
	 * @return array<string, mixed>
	 */
	public function diagnose(): array {
		return $this->probe();
	}

	/**
	 * Return the most recent safe detailed result.
	 *
	 * Before any probe has run the answer is `uncheckable()`, not
	 * `unknown()`: nothing has been sent, and `unknown()` reports
	 * `verified = true`, which asserts the backend was reached and identified.
	 * A caller reading this before the first probe would otherwise be told a
	 * gateway had been contacted when the request had not left the process.
	 *
	 * @return array<string, mixed>
	 */
	public function getLastResult(): array {
		return $this->last_result ?? $this->diagnostics()->uncheckable();
	}

	/**
	 * Run an explicit one-token generation probe for genuine credential verification.
	 *
	 * Delegates to VerifyProbe; see that class for the verdict contract.
	 * Fail-open: verification failures degrade to `could-not-be-checked`,
	 * never fatal, and never block chat. Configured does not equal verified.
	 *
	 * @since 0.1.6
	 *
	 * @return array<string, mixed>
	 */
	public function verify(): array {
		$probe = new VerifyProbe(
			$this->catalog,
			$this->diagnostics_or_null(),
			fn(): object => $this->getRequestAuthentication(),
			fn( Request $request ): object => $this->getHttpTransporter()->send( $request ),
			fn( \Throwable $exception ): bool => self::is_missing_credential_throwable( $exception )
		);
		return $probe->run();
	}

	/**
	 * Probe, classify, and briefly cache the safe result.
	 *
	 * Delegates to the lazily-built AvailabilityProbe and records its
	 * result.
	 *
	 * @return array<string, mixed>
	 */
	private function probe(): array {
		$this->last_result = $this->availabilityProbe()->run();
		return $this->last_result;
	}

	/**
	 * Availability probe for this catalog (canonical unless overridden).
	 *
	 * Built once and reused; the closures resolve the
	 * transporter/authentication lazily so their setters still take effect.
	 *
	 * @since 0.1.9
	 *
	 * @return AvailabilityProbe
	 */
	private function availabilityProbe(): AvailabilityProbe {
		if ( null === $this->availability_probe ) {
			$this->availability_probe = new AvailabilityProbe(
				$this->catalog,
				$this->diagnostics(),
				fn(): object => $this->getRequestAuthentication(),
				fn( Request $request ): object => $this->getHttpTransporter()->send( $request ),
				$this->lastGoodStore(),
				fn( \Throwable $exception ): bool => self::is_missing_credential_throwable( $exception )
			);
		}
		return $this->availability_probe;
	}

	/**
	 * Read the last-known-good configured flag for this catalog.
	 *
	 * Transient-only and credential-blind: stores only whether a keyed probe
	 * previously succeeded, never any option value. LastGoodStore memoises
	 * the read per instance, so repeated reads in one request cost one
	 * transient fetch.
	 *
	 * @since 0.1.6
	 *
	 * @return bool
	 */
	private function readLastGood(): bool {
		return $this->lastGoodStore()->read();
	}

	/**
	 * Diagnostics collaborator (canonical instance unless overridden).
	 *
	 * @since 0.1.6
	 *
	 * @return ConnectionDiagnostics
	 */
	private function diagnostics(): ConnectionDiagnostics {
		if ( null === $this->resolved_diagnostics ) {
			$this->resolved_diagnostics = $this->diagnostics_override ?? new ConnectionDiagnostics();
		}
		return $this->resolved_diagnostics;
	}

	/**
	 * Last safe result retained for a caller.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $last_result = null;
}
