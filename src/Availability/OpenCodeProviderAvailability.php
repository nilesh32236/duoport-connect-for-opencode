<?php
/**
 * Provider availability check.
 *
 * Probe-based availability check with transient caching.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

namespace OpenCodeConnector\Availability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Http\GoRequestHeaders;
use OpenCodeConnector\Http\SessionHeader;
use OpenCodeConnector\Metadata\Catalog;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
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
	 * Maximum age of the last-known-good fallback, in seconds.
	 *
	 * Fail-open is bounded: a flag armed by a keyed success stops carrying
	 * could-not-be-checked verdicts once this long has passed without a fresh
	 * keyed confirmation. The bound exists because key rotations delivered
	 * outside the options table — a `wp-config.php` constant or an
	 * environment variable — fire none of the cache-bust hooks, so without it
	 * the flag could outlive the rotation it describes by up to its full
	 * transient TTL. Every keyed success re-arms the flag with a fresh
	 * timestamp, so a genuinely healthy key never reaches this bound; only a
	 * key that has not been confirmed for two days stops failing open.
	 *
	 * Literal seconds rather than `48 * HOUR_IN_SECONDS` so the value is
	 * available before WP defines its time constants.
	 *
	 * @since 0.1.10
	 */
	private const LAST_GOOD_MAX_AGE = 172800;

	/**
	 * Guarded jitter source for probe cache windows.
	 *
	 * Delegates to TransientCache so the jitter policy has one home.
	 *
	 * @since 0.1.10
	 *
	 * @param int $min Minimum spread (inclusive).
	 * @param int $max Maximum spread (inclusive).
	 * @return int Drawn spread, or 0 when `wp_rand()` is unavailable or throws.
	 */
	private static function rand_spread( int $min, int $max ): int {
		return TransientCache::randSpread( $min, $max );
	}

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
	 * Per-instance memo of the last-known-good flag.
	 *
	 * Retained for backward compatibility; the canonical memo lives in
	 * LastGoodStore. `writeLastGood()` keeps both in step so a write
	 * followed by a read in the same request never goes back to storage.
	 *
	 * @since 0.1.10
	 *
	 * @var bool|null
	 */
	private ?bool $last_good_memo = null;

	/**
	 * Flag store for this catalog (lazy).
	 *
	 * @since 0.1.8
	 *
	 * @var LastGoodStore|null
	 */
	private ?LastGoodStore $last_good_store = null;

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
	 * @since 0.1.9
	 *
	 * @param \Throwable $exception Throwable raised while resolving credentials.
	 * @return bool True only when the credential is proven absent.
	 */
	private static function is_missing_credential_throwable( \Throwable $exception ): bool {
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
	 * Separate from the lightweight availability probe: sends a minimal
	 * `chat/completions` request (`max_tokens: 1`) and maps the outcome to
	 * one of `valid`, `invalid_key`, or `could-not-be-checked`. The verdict
	 * is cached about five minutes and never contains key material.
	 * Fail-open: verification failures degrade to `could-not-be-checked`,
	 * never fatal, and never block chat. Configured does not equal verified.
	 *
	 * @since 0.1.6
	 *
	 * @return array<string, mixed>
	 */
	public function verify(): array {
		$diagnostics = $this->diagnostics_or_null();
		$tkey        = Catalog::VERIFY_PREFIX . $this->catalog;
		$lock_key    = $tkey . '_lock';

		$early = $this->readVerifyCache( $tkey, $lock_key, $diagnostics );
		if ( null !== $early ) {
			return $early;
		}

		try {
			$this->getRequestAuthentication();
		} catch ( \Throwable $exception ) {
			// Same split as probe(): only a PROVEN absent credential is an
			// `invalid_key` verdict. Any other throwable here is an
			// infrastructure fault — a throwing filter, an unregistered
			// Connectors registry, a broken SDK bootstrap — and caching it as
			// `invalid_key` for five minutes renders that fault on the settings
			// page as a credential verdict the plugin never actually received.
			$state   = self::is_missing_credential_throwable( $exception ) ? 'invalid_key' : 'could-not-be-checked';
			$verdict = $this->verify_result( $state, null, $diagnostics );
			$this->store_verify_verdict( $tkey, $lock_key, $verdict );
			return $verdict;
		}

		if ( function_exists( 'set_transient' ) ) {
			try {
				set_transient( $lock_key, 1, 10 );
			} catch ( \Throwable $lock_exception ) {
				unset( $lock_exception );
				// Fail-open: proceed without the lock.
			}
		}

		// An unrecognised catalog fails closed instead of silently probing as
		// the Zen catalog: the mapping lives in Catalog::providerClassFor()
		// so a future third catalog cannot fall through to a wrong branch.
		$cls = Catalog::providerClassFor( $this->catalog );
		if ( '' === $cls ) {
			$verdict = $this->verify_result( 'invalid_key', null, $diagnostics );
			$this->store_verify_verdict( $tkey, $lock_key, $verdict );
			return $verdict;
		}

		$diagnosis = $this->sendVerifyProbe( $cls, $diagnostics );

		$state = null !== $diagnostics && null !== $diagnosis ? $diagnostics->verify_state( $diagnosis ) : 'could-not-be-checked';
		// Explicit fail-open: transport/server/unknown outcomes already map to
		// `could-not-be-checked` via verify_state(); never fatal here.
		$verdict = $this->verify_result( $state, $diagnosis, $diagnostics );
		$this->store_verify_verdict( $tkey, $lock_key, $verdict );
		return $verdict;
	}

	/**
	 * Read a cached verification verdict or stampede-lock signal.
	 *
	 * Returns the verdict to report immediately (a cached verdict, or a
	 * `could-not-be-checked` verdict when a concurrent probe holds the
	 * lock), or null when no cache entry applies and the caller should
	 * proceed to probe. Never throws; an unreadable cache is a miss.
	 *
	 * @since 0.1.8
	 *
	 * @param string                     $tkey        Verdict transient key.
	 * @param string                     $lock_key    Lock transient key.
	 * @param ConnectionDiagnostics|null $diagnostics Diagnostics helper, if available.
	 * @return array<string, mixed>|null Verdict to return, or null to proceed.
	 */
	private function readVerifyCache( string $tkey, string $lock_key, ?ConnectionDiagnostics $diagnostics ): ?array {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}
		try {
			$cached = get_transient( $tkey );
		} catch ( \Throwable ) {
			$cached = false;
		}
		if ( is_array( $cached ) && isset( $cached['state'] ) && is_string( $cached['state'] ) ) {
			return $cached;
		}
		try {
			$locked = get_transient( $lock_key );
		} catch ( \Throwable ) {
			$locked = false;
		}
		if ( false !== $locked ) {
			return $this->verify_result( 'could-not-be-checked', null, $diagnostics );
		}
		return null;
	}

	/**
	 * Whether the SDK surface a probe needs is available.
	 *
	 * HttpMethodEnum::POST() is a magic factory: the SDK declares it as an
	 * `@method static` annotation and serves it from AbstractEnum::__callStatic,
	 * so method_exists() cannot see it and is permanently false against the
	 * shipped SDK. Probe the backing constant instead, the same rule
	 * AbstractOpenCodeProvider::createProviderMetadata() applies to
	 * ProviderTypeEnum and RequestAuthenticationMethod. `url` is a real
	 * static method on AbstractApiProvider, so method_exists() is correct
	 * for it.
	 *
	 * Shared by verify() and probe() so both probes agree on what "the SDK
	 * is present" means.
	 *
	 * @since 0.1.8
	 *
	 * @param string $cls Provider class FQCN.
	 * @return bool
	 */
	private function probeSurfaceAvailable( string $cls ): bool {
		if ( '' === $cls ) {
			return false;
		}
		return class_exists( $cls ) && method_exists( $cls, 'url' )
			&& class_exists( Request::class ) && class_exists( HttpMethodEnum::class ) && defined( HttpMethodEnum::class . '::POST' );
	}

	/**
	 * Build the one-token probe payload both probes send.
	 *
	 * A single owner for the model/messages/max_tokens shape so the
	 * verification probe and the availability probe cannot drift apart when
	 * one of them is edited.
	 *
	 * @since 0.1.8
	 *
	 * @return array<string, mixed>
	 */
	private function probePayload(): array {
		return array(
			'model'      => self::PROBE_MODEL,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => 'ping',
				),
			),
			'max_tokens' => 1,
		);
	}

	/**
	 * Build the headers both probes send.
	 *
	 * The Go catalog rejects requests without x-opencode-session, so Go
	 * probes carry the stable ping-derived session plus the plugin
	 * User-Agent via the shared Go header pair. Zen probes carry the
	 * session-only headers. Guarded so a missing helper degrades to fewer
	 * headers, never a fatal — and kept inside the probe's try region by
	 * the callers that need it there.
	 *
	 * @since 0.1.8
	 *
	 * @param array<string, mixed> $data Probe payload the session is derived from.
	 * @return array<string, string>
	 */
	private function probeHeaders( array $data ): array {
		$base_headers = array( 'Content-Type' => 'application/json' );
		if ( Catalog::GO === $this->catalog ) {
			try {
				if ( class_exists( GoRequestHeaders::class ) && method_exists( GoRequestHeaders::class, 'for_go' ) ) {
					return GoRequestHeaders::for_go( $base_headers, $data );
				}
			} catch ( \Throwable $go_header_exception ) {
				unset( $go_header_exception );
			}
		}
		try {
			if ( class_exists( SessionHeader::class ) && method_exists( SessionHeader::class, 'inject_into_headers' ) ) {
				return SessionHeader::inject_into_headers( $base_headers, $data );
			}
		} catch ( \Throwable $session_header_exception ) {
			unset( $session_header_exception );
		}
		return $base_headers;
	}

	/**
	 * Send the verification HTTP round-trip and classify the outcome.
	 *
	 * Flat guard-clause shape instead of the elseif/catch nesting it
	 * replaces: a surface failure classifies without sending, a transport
	 * failure classifies as uncheckable, and a classifier failure degrades
	 * to null so the caller fails open. Never throws.
	 *
	 * @since 0.1.8
	 *
	 * @param string                     $cls         Provider class FQCN.
	 * @param ConnectionDiagnostics|null $diagnostics Diagnostics helper, if available.
	 * @return array<string, mixed>|null Safe diagnosis, or null when none exists.
	 */
	private function sendVerifyProbe( string $cls, ?ConnectionDiagnostics $diagnostics ): ?array {
		if ( ! $this->probeSurfaceAvailable( $cls ) ) {
			if ( null === $diagnostics ) {
				return null;
			}
			try {
				return $diagnostics->classify( 0, null, new \RuntimeException( 'verify surface unavailable' ) );
			} catch ( \Throwable $surface_exception ) {
				unset( $surface_exception );
				return null;
			}
		}
		try {
			$probe_data    = $this->probePayload();
			$probe_headers = $this->probeHeaders( $probe_data );
			$req           = new Request(
				HttpMethodEnum::POST(),
				$cls::url( 'chat/completions' ),
				$probe_headers,
				$probe_data
			);
			$req           = $this->getRequestAuthentication()->authenticateRequest( $req );
			$res           = $this->getHttpTransporter()->send( $req );
			return null !== $diagnostics ? $diagnostics->classify( $res->getStatusCode(), $res->getData(), null, self::PROBE_MODEL ) : null;
		} catch ( \Throwable $exception ) {
			unset( $exception );
			if ( null === $diagnostics ) {
				return null;
			}
			try {
				return $diagnostics->classify( 0, null, new \RuntimeException( 'verify transport failure' ) );
			} catch ( \Throwable $classify_exception ) {
				unset( $classify_exception );
				return null;
			}
		}
	}

	/**
	 * Add jitter to a cache TTL so probes do not stampede.
	 *
	 * Single owner for the TTL+jitter policy both probes share: base window
	 * plus wp_rand(-60,60) with a 60-second floor. Never throws.
	 *
	 * @since 0.1.8
	 *
	 * @param int $base Base TTL in seconds.
	 * @return int Jittered TTL in seconds.
	 */
	private function jitteredTtl( int $base ): int {
		return TransientCache::jitteredTtl( $base );
	}

	/**
	 * The could-not-be-checked window for TRANSIENT failures, jittered 45-75s.
	 *
	 * A fixed 60s window makes every site whose upstream is failing the same
	 * way re-probe in lockstep on the same boundary, which is precisely the
	 * synchronized stampede the jitter exists to prevent. The short branch
	 * therefore draws `wp_rand( -15, 15 )` around the minute: a -15 draw
	 * caches 45s and a +15 draw caches 75s.
	 *
	 * The 30-second floor is not decoration. `MINUTE_IN_SECONDS` is
	 * filterable, so a site that filters it down would otherwise be able to
	 * drive this window to zero and turn the cache into no cache at all. It
	 * is also below the 45s minimum a real draw can produce, so it never
	 * binds on an unfiltered install.
	 *
	 * Kept separate from jitteredTtl() because the two windows differ in both
	 * spread (±15 vs ±60) and floor (30 vs 60); folding them into one helper
	 * with a parameter would let a caller pick the wrong pair silently.
	 *
	 * @since 0.1.10
	 *
	 * @return int Jittered short window in seconds.
	 */
	private function jitteredShortTtl(): int {
		return TransientCache::jitteredShortTtl();
	}

	/**
	 * Build a credential-blind verification verdict.
	 *
	 * Stores only the safe state plus the safe diagnosis; never request
	 * headers, payloads, or key material.
	 *
	 * A null diagnosis means no response exists, and it therefore projects
	 * `uncheckable()`, never `unknown()`. `unknown()` carries
	 * `verified = true`, which asserts the backend was reached and identified —
	 * and every path that arrives here with no diagnosis reached nothing: a
	 * concurrent probe holds the stampede lock, the SDK's request surface is
	 * unavailable, or the classifier itself threw. Substituting `unknown()`
	 * there claimed a request had arrived at OpenCode and been understood. It
	 * had not been sent. `uncheckable()` is the verdict that means exactly this,
	 * and it reports `verified = false`.
	 *
	 * `invalid_key` is the one branch that keeps a verdict of its own: the
	 * probe never got as far as sending because the credential was PROVEN
	 * absent, which is a statement about the key rather than about reach. A
	 * credential-resolution fault that proves nothing takes
	 * `could-not-be-checked` instead and lands on `uncheckable()` here.
	 *
	 * @since 0.1.6
	 *
	 * @param string                     $state       Verification state.
	 * @param array<string,mixed>|null   $diagnosis   Safe diagnosis, if any.
	 * @param ConnectionDiagnostics|null $diagnostics Diagnostics helper, if available.
	 * @return array<string, mixed>
	 */
	private function verify_result( string $state, ?array $diagnosis, ?ConnectionDiagnostics $diagnostics ): array {
		if ( null === $diagnosis ) {
			if ( null !== $diagnostics ) {
				try {
					$diagnosis = 'invalid_key' === $state
						? $diagnostics->notConfigured()
						: $diagnostics->uncheckable();
				} catch ( \Throwable $verdict_exception ) {
					unset( $verdict_exception );
					// The helper itself could not be called. This is
					// belt-and-braces — its factories are pure array literals —
					// but the fallback must not reintroduce the claim this method
					// was fixed to stop making, so it carries the same verdict
					// name rather than the "reached and identified" one.
					$diagnosis = array( 'state' => 'uncheckable' );
				}
			} else {
				$diagnosis = array( 'state' => 'uncheckable' );
			}
		}
		return array(
			'state'     => $state,
			'diagnosis' => $diagnosis,
			'catalog'   => $this->catalog,
		);
	}

	/**
	 * Cache a verification verdict for about five minutes.
	 *
	 * @since 0.1.6
	 *
	 * @param string               $tkey    Transient key.
	 * @param string               $lock_key Lock transient key.
	 * @param array<string, mixed> $verdict Safe verdict.
	 * @return void
	 */
	private function store_verify_verdict( string $tkey, string $lock_key, array $verdict ): void {
		if ( function_exists( 'delete_transient' ) ) {
			try {
				delete_transient( $lock_key );
			} catch ( \Throwable $delete_exception ) {
				unset( $delete_exception );
				// Fail-open: caching must never be fatal.
			}
		}
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}
		$base = defined( 'MINUTE_IN_SECONDS' ) ? 5 * MINUTE_IN_SECONDS : 300;
		try {
			set_transient( $tkey, $verdict, $this->jitteredTtl( (int) $base ) );
		} catch ( \Throwable $store_exception ) {
			unset( $store_exception );
			// Fail-open: caching must never be fatal.
		}
	}

	/**
	 * Probe, classify, and briefly cache the safe result.
	 *
	 * @return array<string, mixed>
	 */
	private function probe(): array {
		$tkey     = Catalog::AVAIL_PREFIX . $this->catalog;
		$lock_key = $tkey . '_lock';
		$early    = $this->readProbeCache( $tkey, $lock_key );
		if ( null !== $early ) {
			return $early;
		}

		$diagnostics = $this->diagnostics();
		$credential  = $this->resolveCredentialOrUncheckable( $diagnostics );
		if ( null !== $credential ) {
			return $credential;
		}

		// Set lock before network I/O (10s).
		$this->setCached( $lock_key, 1, 10 );

		// An unrecognised catalog fails closed instead of silently probing as
		// the Zen catalog; see verify() for why the mapping has one home.
		$cls = Catalog::providerClassFor( $this->catalog );
		if ( '' === $cls ) {
			$this->last_result = $diagnostics->notConfigured();
			$this->writeLastGood( false );
			return $this->last_result;
		}
		$this->last_result = $this->sendAvailabilityProbe( $cls, $diagnostics );
		$this->deleteCached( $lock_key );
		$state = isset( $this->last_result['state'] ) && is_string( $this->last_result['state'] ) ? $this->last_result['state'] : '';
		$this->persistProbeResult( $tkey, $state );
		return $this->last_result;
	}

	/**
	 * Resolve the request credential, or return an early verdict.
	 *
	 * Returns null when the credential resolved and the caller should
	 * proceed to probe. A proven-absent credential reports not-configured
	 * (and clears last-known-good); any other resolution fault degrades to
	 * could-not-be-checked and leaves the flag alone. Never throws.
	 *
	 * @since 0.1.8
	 *
	 * @param ConnectionDiagnostics $diagnostics Diagnostics helper.
	 * @return array<string, mixed>|null Early verdict, or null to proceed.
	 */
	private function resolveCredentialOrUncheckable( ConnectionDiagnostics $diagnostics ): ?array {
		try {
			$this->getRequestAuthentication();
		} catch ( \Throwable $exception ) {
			if ( ! self::is_missing_credential_throwable( $exception ) ) {
				$this->last_result = $diagnostics->uncheckable();
				return $this->last_result;
			}
			$this->last_result = $diagnostics->notConfigured();
			$this->writeLastGood( false );
			return $this->last_result;
		}
		return null;
	}

	/**
	 * Send the availability HTTP round-trip and classify the outcome.
	 *
	 * Probe models are chosen to discriminate AUTHENTICATION, not model
	 * availability: paid models answer 401 CreditsError for a valid but
	 * empty-balance key (configured) versus other 401s for a bad key.
	 * Never throws; transport and construction faults classify as
	 * uncheckable so the stampede-lock release in probe() always runs.
	 *
	 * @since 0.1.8
	 *
	 * @param string                $cls         Provider class FQCN.
	 * @param ConnectionDiagnostics $diagnostics Diagnostics helper.
	 * @return array<string, mixed> Safe diagnosis.
	 */
	private function sendAvailabilityProbe( string $cls, ConnectionDiagnostics $diagnostics ): array {
		$probe_model   = self::PROBE_MODEL;
		$probe_data    = $this->probePayload();
		$probe_headers = $this->probeHeaders( $probe_data );
		try {
			// Construction belongs INSIDE the try. `$cls::url()` is a static call
			// on the provider class and HttpMethodEnum::POST() is a magic factory
			// served by __callStatic, so a broken or half-installed SDK throws
			// \Error or \BadMethodCallException here rather than returning a value
			// that could be checked.
			$req  = new Request(
				HttpMethodEnum::POST(),
				$cls::url( 'chat/completions' ),
				$probe_headers,
				$probe_data
			);
			$req  = $this->getRequestAuthentication()->authenticateRequest( $req );
			$res  = $this->getHttpTransporter()->send( $req );
			$data = $res->getData();
			return $diagnostics->classify( $res->getStatusCode(), is_array( $data ) ? $data : null, null, $probe_model );
		} catch ( \Throwable $exception ) {
			return $diagnostics->classify( 0, null, $exception );
		}
	}

	/**
	 * Read a cached probe result or stampede-lock signal.
	 *
	 * Returns the result to report immediately (a cached verdict with its
	 * legacy boolean-cache projection, or a could-not-be-checked verdict
	 * when a concurrent probe holds the lock), or null when no cache entry
	 * applies and the caller should proceed to probe. Records the early
	 * result in last_result like probe() itself would.
	 *
	 * @since 0.1.8
	 *
	 * @param string $tkey     Probe result transient key.
	 * @param string $lock_key Stampede-lock transient key.
	 * @return array<string, mixed>|null Result to return, or null to proceed.
	 */
	private function readProbeCache( string $tkey, string $lock_key ): ?array {
		$cached = $this->getCached( $tkey );
		if ( is_array( $cached ) && isset( $cached['state'] ) ) {
			$this->last_result = $cached;
			return $cached;
		}
		if ( false !== $cached && is_bool( $cached ) ) {
			$this->last_result = $cached ? $this->diagnostics()->verified() : $this->diagnostics()->notConfigured();
			return $this->last_result;
		}
		if ( false !== $this->getCached( $lock_key ) ) {
			// Concurrent probe: surface could-not-be-checked so isConfigured()
			// can fail open on last-known-good instead of flipping to false.
			$this->last_result = $this->diagnostics()->uncheckable();
			return $this->last_result;
		}
		return null;
	}

	/**
	 * Write a probe outcome to the cache and the last-known-good flag.
	 *
	 * The safety-critical write-back policy, kept behind the transient
	 * reads and the HTTP round-trip so future edits to those cannot land
	 * inside it: could-not-be-checked verdicts never touch last-known-good
	 * (they only refresh their cache window), definitive keyed outcomes
	 * refresh it, and only definitive negatives clear it (via
	 * applyLastGood()). Membership is read from the published
	 * ConnectionDiagnostics helpers rather than restated lists.
	 *
	 * Could-not-be-checked (5xx, transport failure, concurrent probe, and
	 * any unrecognised response): never write the failure to
	 * last-known-good. The verdict is cached so a persistent condition costs
	 * one probe per window instead of one per call, while isConfigured()
	 * keeps failing open.
	 *
	 * The two kinds of could-not-be-checked get different windows, and the
	 * reason is not only cost. A transport failure or a 5xx resolves on its
	 * own, so the one-minute window notices a recovered gateway quickly.
	 * `unknown` is not transient: the gateway answered with something this
	 * plugin has no rule for, and that does not resolve itself in a minute —
	 * a 404 after an upstream model rename is the standing case.
	 * `probe_model_unavailable` is the same shape for the same reason: the
	 * gateway has said the model this plugin probes with is gone, and it will
	 * still be gone in a minute.
	 *
	 * Both also take the full jittered window because the one-minute branch
	 * has no jitter at all, so routing them there would make every site whose
	 * upstream answered that way re-probe in lockstep on the same 60-second
	 * boundary — precisely the synchronized stampede the jitter exists to
	 * prevent. Membership is read from the published helpers, so adding a
	 * third persistent state later cannot land on the short branch by being
	 * forgotten here. A persistent verdict must NOT reach the
	 * last-known-good writes — neither state proves anything about the key,
	 * and writing false there would reinstate the exact production bug the
	 * persistent window exists to fix.
	 *
	 * Definitive keyed outcomes refresh last-known-good. A Zen free-tier
	 * quota stop proves the key is valid, so it counts as a good result.
	 *
	 * @since 0.1.8
	 *
	 * @param string $tkey  Probe result transient key.
	 * @param string $state Classified state name.
	 * @return void
	 */
	private function persistProbeResult( string $tkey, string $state ): void {
		if ( ConnectionDiagnostics::isUncheckableState( $state ) ) {
			if ( ! ConnectionDiagnostics::isPersistentUncheckableState( $state ) ) {
				// Jittered 45-75s, not a fixed 60s: a fixed window makes every
				// site whose upstream is failing the same way re-probe in
				// lockstep on the same boundary.
				$this->setCached( $tkey, $this->last_result, $this->jitteredShortTtl() );
				return;
			}
			// Persistent: the longer jittered window, and NOT the
			// last-known-good writes — neither state proves anything about the
			// key, and writing false there would reinstate the exact production
			// bug the persistent window exists to fix.
			$minute = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
			$this->setCached( $tkey, $this->last_result, $this->jitteredTtl( 5 * $minute ) );
			return;
		}
		$this->applyLastGood( $state );
		// Stagger expiry ±60s to avoid synchronized stampedes.
		$minute = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
		$this->setCached( $tkey, $this->last_result, $this->jitteredTtl( 5 * $minute ) );
	}

	/**
	 * Read the last-known-good configured flag for this catalog.
	 *
	 * Transient-only and credential-blind: stores only whether a keyed probe
	 * previously succeeded, never any option value. Memoised per instance —
	 * see $last_good_memo — so repeated reads in one request cost one
	 * transient fetch.
	 *
	 * @since 0.1.6
	 *
	 * @return bool
	 */
	private function readLastGood(): bool {
		if ( null !== $this->last_good_memo ) {
			return $this->last_good_memo;
		}
		$this->last_good_memo = $this->lastGoodStore()->read();
		return $this->last_good_memo;
	}

	/**
	 * Whether a stored last-known-good value still arms the fallback.
	 *
	 * The current shape is `array( 'v' => '1', 'ts' => <unix time> )`: the
	 * string sentinel keeps the stored type stable across the database
	 * round-trip (an int `1` comes back as string `'1'`), and the timestamp
	 * bounds the fallback by age — see LAST_GOOD_MAX_AGE. The age cap binds
	 * only timestamped shapes.
	 *
	 * Legacy shapes (int `1`, string `'1'`) predate the timestamp and read as
	 * armed with no age bound: they fail open, exactly as they did before the
	 * bound existed, and the next keyed success upgrades them to the
	 * timestamped shape. That unbounded read is intentional — treating a
	 * pre-upgrade flag as expired would disconnect working keys on upgrade.
	 * A stale timestamped entry reads as disarmed; the transient itself still
	 * expires on its own TTL, and definitive negatives still delete outright.
	 *
	 * @since 0.1.10
	 *
	 * @param mixed $value Raw transient value.
	 * @return bool True while the fallback may carry a could-not-be-checked verdict.
	 */
	private function isFreshLastGood( mixed $value ): bool {
		return LastGoodStore::isFresh( $value );
	}

	/**
	 * Apply a classified verdict to the last-known-good flag.
	 *
	 * Three outcomes, not two: arm, clear, or leave alone. The third is the
	 * one that matters and it exists because the earlier version of this
	 * decision was `KEYED_STATES ? write(true) : write(false)` — an `else`
	 * that cleared the flag for everything not keyed, including any state in
	 * NO bucket at all.
	 *
	 * That fallthrough is the same hazard as the production bug this PR fixes,
	 * one branch later. The bug was `unknown` missing from the bucket list, so
	 * a state that had adjudicated nothing reached a write that decided the
	 * credential was bad, and a working key was disconnected. An unbucketed
	 * state is the identical mistake made permanently: today the buckets happen
	 * to be exhaustive, so the `else` is unreachable for real states and reads
	 * as harmless — until the next state is added to the vocabulary without a
	 * bucket, which is exactly when nobody is looking at this method.
	 *
	 * So the fallthrough fails OPEN on whatever the flag already says. Only a
	 * state in DEFINITIVE_NEGATIVE_STATES may clear it, because only those two
	 * are a proven verdict about the credential. Everything else has proved
	 * nothing, and proving nothing must not cost a site its connection.
	 *
	 * @param string $state Classified state name.
	 * @return void
	 */
	private function applyLastGood( string $state ): void {
		$this->lastGoodStore()->apply( $state );
		// Keep the legacy memo in step: apply() may arm or clear the store,
		// and a stale local memo would otherwise disagree with it.
		if ( ConnectionDiagnostics::isConfiguredState( $state ) ) {
			$this->last_good_memo = true;
			return;
		}
		if ( ConnectionDiagnostics::isDefinitiveNegativeState( $state ) ) {
			$this->last_good_memo = false;
		}
	}

	/**
	 * Record or clear the last-known-good configured flag for this catalog.
	 *
	 * THE WINDOW IS ROLLING AND MUST STAY ROLLING. Every keyed success
	 * re-writes the flag and pushes its 30-day TTL forward. Do not "optimise"
	 * the redundant write away — that was tried in this PR and reverted, and
	 * the optimisation is a bug.
	 *
	 * Why it must roll. This flag is the entire mechanism by which a working
	 * key keeps looking working when the gateway answers something the plugin
	 * cannot interpret. The defect this PR fixes is precisely that a 400/404
	 * made `isConfigured()` report a perfectly valid key as unconfigured; the
	 * fallback flag is the fix for that. If the window were absolute — set
	 * once and left to count down — then a site whose last confirmed good
	 * probe was on day 1 and whose upstream starts answering unrecognisably
	 * on day 29 loses its fallback on day 31, and `isConfigured()` starts
	 * reporting that valid key as not connected again. That is not a subtler
	 * version of the fix; it is the original defect returning on a 30-day
	 * horizon, with a good key and no upstream error to explain it.
	 *
	 * Rolling is what makes the guarantee hold for as long as the credential
	 * keeps working: each keyed success says "still good, today", so the
	 * fallback cannot lapse while the key is genuinely healthy, and it starts
	 * counting down only from the last real confirmation.
	 *
	 * The cost is one options-row UPDATE per keyed probe, about every five
	 * minutes per catalog for the life of the install — and that figure is
	 * conditional, not universal. WP core's `set_transient()` branches on
	 * `wp_using_ext_object_cache()`: on a site with a persistent object cache
	 * (Redis, Memcached) it returns through `wp_cache_set()` and writes no
	 * options row at all, so the database cost is zero there. Only the default
	 * path, which writes `_transient_*` rows with a raw UPDATE and no equality
	 * short-circuit, pays it. The earlier version of this paragraph stated the
	 * cost as if it were unconditional, which is wrong for the majority of
	 * production sites and was offered as support for a decision that does not
	 * depend on it.
	 *
	 * The decision does not depend on the cost. Rolling is required for
	 * correctness; the price is what it happens to be on the sites that pay it.
	 * That write is the accepted price of the guarantee above, not an
	 * oversight. If it ever needs to be avoided, the fix belongs at the storage
	 * layer (an equality check that still extends the TTL), never at the cost of
	 * the rolling behaviour.
	 *
	 * Clearing is unaffected: the first definitive negative deletes the flag
	 * outright and immediately, so a genuinely revoked key is never held open
	 * by any of this.
	 *
	 * The stored shape is `array( 'v' => '1', 'ts' => time() )`, still
	 * credential-blind. The string sentinel (not int `1`) keeps the stored
	 * type stable across the database round-trip; it does not skip the
	 * value-row write, because `ts` changes on every keyed success so the
	 * serialised value is never equal — both the value row and the timeout
	 * row move on each rewrite, and that rewrite is the rolling behaviour
	 * above, not a pointless write. The timestamp is what `readLastGood()`
	 * bounds by LAST_GOOD_MAX_AGE (timestamped shapes only; pre-timestamp
	 * int/string shapes read as armed until a keyed success upgrades them),
	 * so a flag that has not been confirmed since before a `wp-config.php` or
	 * environment rotation stops failing open.
	 *
	 * @since 0.1.6
	 *
	 * @param bool $good Whether a keyed probe just succeeded.
	 * @return void
	 */
	private function writeLastGood( bool $good ): void {
		$this->lastGoodStore()->write( $good );
		$this->last_good_memo = $good;
	}

	/**
	 * Guarded transient read with a cache-miss fallback.
	 *
	 * Guards the function being ABSENT and being BROKEN. A persistent object
	 * cache that throws is a cache that cannot be read, which is a miss — and a
	 * miss costs one probe, where the exception costs the page. Same rule
	 * verify() already applies to its own get_transient() call.
	 *
	 * @since 0.1.6
	 *
	 * @param string $key Transient key.
	 * @return mixed
	 */
	private function getCached( string $key ): mixed {
		return TransientCache::get( $key );
	}

	/**
	 * Guarded transient write.
	 *
	 * A write that fails loses the cache, not the verdict: the caller has
	 * already adjudicated the credential by the time it gets here, and the cost
	 * is the next request re-probing. This is the guard the rolling
	 * last-known-good write in particular needs — its whole value is that it
	 * cannot turn a storage problem into a fatal one.
	 *
	 * @since 0.1.6
	 *
	 * @param string $key   Transient key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Time to live in seconds.
	 * @return bool
	 */
	private function setCached( string $key, mixed $value, int $ttl ): bool {
		return TransientCache::set( $key, $value, $ttl );
	}

	/**
	 * Guarded transient delete.
	 *
	 * Two call sites, both on paths that have already decided something. It
	 * releases the stampede lock after a completed probe, and it clears the
	 * last-known-good flag on the first definitive negative. Neither may let a
	 * broken cache turn a proven revoked key into an uncaught throwable, and
	 * neither may stop the verdict that was just reached from being reported.
	 *
	 * @since 0.1.6
	 *
	 * @param string $key Transient key.
	 * @return bool
	 */
	private function deleteCached( string $key ): bool {
		return TransientCache::delete( $key );
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
