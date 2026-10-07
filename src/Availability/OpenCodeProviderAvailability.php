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
use OpenCodeConnector\Providers\OpenCodeGoProvider;
use OpenCodeConnector\Providers\OpenCodeZenProvider;
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
		if ( in_array( $state, ConnectionDiagnostics::KEYED_STATES, true ) ) {
			return true;
		}
		// Could-not-be-checked outcomes fall back to last-known-good instead of
		// flipping a valid key to not-connected during an outage.
		if ( in_array( $state, ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES, true ) ) {
			return $this->readLastGood();
		}
		// Definitive negatives are the only states that may report
		// not-configured: `invalid_key` is a proven bad credential and
		// `not_configured` is a proven missing one. Both have adjudicated the
		// credential, which is what earns them the right to end the call false.
		if ( in_array( $state, ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES, true ) ) {
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
		$diagnostics = class_exists( ConnectionDiagnostics::class ) ? $this->diagnostics_or_null() : null;
		$tkey        = Catalog::VERIFY_PREFIX . $this->catalog;
		$lock_key    = $tkey . '_lock';

		if ( function_exists( 'get_transient' ) ) {
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

		$diagnosis = null;
		$cls       = 'go' === $this->catalog ? OpenCodeGoProvider::class : OpenCodeZenProvider::class;
		// HttpMethodEnum::POST() is a magic factory: the SDK declares it as an
		// `@method static` annotation and serves it from AbstractEnum::__callStatic,
		// so method_exists() cannot see it and is permanently false against the
		// shipped SDK. Probe the backing constant instead, the same rule
		// AbstractOpenCodeProvider::createProviderMetadata() applies to
		// ProviderTypeEnum and RequestAuthenticationMethod. `url` is a real
		// static method on AbstractApiProvider, so method_exists() is correct
		// for it.
		$surface_ok = class_exists( $cls ) && method_exists( $cls, 'url' )
			&& class_exists( Request::class ) && class_exists( HttpMethodEnum::class ) && defined( HttpMethodEnum::class . '::POST' );
		if ( ! $surface_ok && null !== $diagnostics ) {
			try {
				$diagnosis = $diagnostics->classify( 0, null, new \RuntimeException( 'verify surface unavailable' ) );
			} catch ( \Throwable $surface_exception ) {
				unset( $surface_exception );
				$diagnosis = null;
			}
		} elseif ( $surface_ok ) {
			try {
				$probe_data   = array(
					'model'      => self::PROBE_MODEL,
					'messages'   => array(
						array(
							'role'    => 'user',
							'content' => 'ping',
						),
					),
					'max_tokens' => 1,
				);
				$base_headers = array( 'Content-Type' => 'application/json' );
				if ( class_exists( SessionHeader::class ) && method_exists( SessionHeader::class, 'inject_into_headers' ) ) {
					$probe_headers = SessionHeader::inject_into_headers( $base_headers, $probe_data );
				} else {
					$probe_headers = $base_headers;
				}
				$req       = new Request(
					HttpMethodEnum::POST(),
					$cls::url( 'chat/completions' ),
					$probe_headers,
					$probe_data
				);
				$req       = $this->getRequestAuthentication()->authenticateRequest( $req );
				$res       = $this->getHttpTransporter()->send( $req );
				$diagnosis = null !== $diagnostics ? $diagnostics->classify( $res->getStatusCode(), $res->getData(), null, self::PROBE_MODEL ) : null;
			} catch ( \Throwable $exception ) {
				unset( $exception );
				if ( null !== $diagnostics ) {
					try {
						$diagnosis = $diagnostics->classify( 0, null, new \RuntimeException( 'verify transport failure' ) );
					} catch ( \Throwable $classify_exception ) {
						unset( $classify_exception );
						$diagnosis = null;
					}
				}
			}
		}

		$state = null !== $diagnostics && null !== $diagnosis ? $diagnostics->verify_state( $diagnosis ) : 'could-not-be-checked';
		// Explicit fail-open: transport/server/unknown outcomes already map to
		// `could-not-be-checked` via verify_state(); never fatal here.
		$verdict = $this->verify_result( $state, $diagnosis, $diagnostics );
		$this->store_verify_verdict( $tkey, $lock_key, $verdict );
		return $verdict;
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
		$ttl  = (int) $base;
		if ( function_exists( 'wp_rand' ) ) {
			try {
				$ttl = (int) $base + wp_rand( -60, 60 );
			} catch ( \Throwable $rand_exception ) {
				unset( $rand_exception );
				$ttl = (int) $base;
			}
		}
		try {
			set_transient( $tkey, $verdict, max( 60, $ttl ) );
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
		$tkey   = Catalog::AVAIL_PREFIX . $this->catalog;
		$cached = $this->getCached( $tkey );
		if ( is_array( $cached ) && isset( $cached['state'] ) ) {
			$this->last_result = $cached;
			return $cached;
		}
		if ( false !== $cached && is_bool( $cached ) ) {
			$this->last_result = $cached ? $this->diagnostics()->verified() : $this->diagnostics()->notConfigured();
			return $this->last_result;
		}

		// Stampede protection: short lock so concurrent requests share one probe.
		$lock_key = $tkey . '_lock';
		if ( false !== $this->getCached( $lock_key ) ) {
			// Concurrent probe: surface could-not-be-checked so isConfigured()
			// can fail open on last-known-good instead of flipping to false.
			$this->last_result = $this->diagnostics()->uncheckable();
			return $this->last_result;
		}

		$diagnostics = $this->diagnostics();
		try {
			$this->getRequestAuthentication();
		} catch ( \Throwable $exception ) {
			// Credential resolution failed, which is NOT the same as the
			// credential being absent — see is_missing_credential_throwable().
			// Only the proven-absent case is allowed to report not-configured,
			// and only it may clear the 30-day last-known-good flag. Everything
			// else is an infrastructure fault that proves nothing about the key,
			// so it degrades to could-not-be-checked and leaves the flag alone.
			if ( ! self::is_missing_credential_throwable( $exception ) ) {
				$this->last_result = $diagnostics->uncheckable();
				return $this->last_result;
			}
			$this->last_result = $diagnostics->notConfigured();
			$this->writeLastGood( false );
			return $this->last_result;
		}

		// Set lock before network I/O (10s).
		$this->setCached( $lock_key, 1, 10 );

		$cls = 'go' === $this->catalog ? OpenCodeGoProvider::class : OpenCodeZenProvider::class;
		// Probe models are chosen to discriminate AUTHENTICATION, not model
		// availability: paid models answer 401 CreditsError for a valid but
		// empty-balance key (configured) versus other 401s for a bad key.
		// Probing a free model instead would fail closed whenever that model
		// is transiently unavailable upstream (observed live).
		$probe_model = self::PROBE_MODEL;
		$probe_data  = array(
			'model'      => $probe_model,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => 'ping',
				),
			),
			'max_tokens' => 1,
		);
		// The Go catalog rejects requests without x-opencode-session (400
		// MissingSessionID), so the probe carries a stable session value
		// derived from its own payload. Zen ignores the extra header. The Go
		// probe additionally carries the plugin User-Agent via the shared Go
		// header pair; the opencode user agent is never spoofed.
		$base_headers  = array( 'Content-Type' => 'application/json' );
		$probe_headers = 'go' === $this->catalog && class_exists( GoRequestHeaders::class )
			? GoRequestHeaders::for_go( $base_headers, $probe_data )
			: SessionHeader::inject_into_headers( $base_headers, $probe_data );
		try {
			// Construction belongs INSIDE the try. `$cls::url()` is a static call
			// on the provider class and HttpMethodEnum::POST() is a magic factory
			// served by __callStatic, so a broken or half-installed SDK throws
			// \Error or \BadMethodCallException here rather than returning a value
			// that could be checked. verify() already guards that surface with an
			// explicit method_exists/defined check for exactly this reason (see
			// $surface_ok above); probe() had no equivalent, and building the
			// request outside the try left this as the one path in the class that
			// fails neither open nor closed — it escaped isConfigured() as an
			// uncaught throwable and skipped the stampede-lock release below,
			// leaving every later caller locked out for the lock's full TTL.
			$req               = new Request(
				HttpMethodEnum::POST(),
				$cls::url( 'chat/completions' ),
				$probe_headers,
				$probe_data
			);
			$req               = $this->getRequestAuthentication()->authenticateRequest( $req );
			$res               = $this->getHttpTransporter()->send( $req );
			$data              = $res->getData();
			$this->last_result = $diagnostics->classify( $res->getStatusCode(), is_array( $data ) ? $data : null, null, $probe_model );
		} catch ( \Throwable $exception ) {
			$this->last_result = $diagnostics->classify( 0, null, $exception );
		}
		$this->deleteCached( $lock_key );
		$state = isset( $this->last_result['state'] ) && is_string( $this->last_result['state'] ) ? $this->last_result['state'] : '';
		// Could-not-be-checked (5xx, transport failure, concurrent probe, and
		// any unrecognised response): never write the failure to
		// last-known-good. The verdict is cached so a persistent condition costs
		// one probe per window instead of one per call, while isConfigured()
		// keeps failing open.
		//
		// The two kinds of could-not-be-checked get different windows, and the
		// reason is not only cost. A transport failure or a 5xx resolves on its
		// own, so the one-minute window notices a recovered gateway quickly.
		// `unknown` is not transient: the gateway answered with something this
		// plugin has no rule for, and that does not resolve itself in a minute —
		// a 404 after an upstream model rename is the standing case.
		// `probe_model_unavailable` is the same shape for the same reason: the
		// gateway has said the model this plugin probes with is gone, and it will
		// still be gone in a minute.
		//
		// Both also take the full jittered window because the one-minute branch
		// has no jitter at all, so routing them there would make every site whose
		// upstream answered that way re-probe in lockstep on the same 60-second
		// boundary — precisely the synchronized stampede the jitter below exists
		// to prevent, introduced by the very fix that is supposed to quieten the
		// probe. Membership is read from the published list rather than an
		// `!== UNKNOWN_STATE` test, so adding a third persistent state later
		// cannot land on the short branch by being forgotten here.
		if ( in_array( $state, ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES, true ) ) {
			if ( ! in_array( $state, ConnectionDiagnostics::PERSISTENT_UNCHECKABLE_STATES, true ) ) {
				$second = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
				$this->setCached( $tkey, $this->last_result, $second );
				return $this->last_result;
			}
			// Persistent: fall through to the jittered window below, skipping
			// the last-known-good writes on the way. It must NOT reach them —
			// neither state proves anything about the key, and writing false here
			// would destroy last-known-good and reinstate the exact production
			// bug this PR fixes.
			$minute = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
			$jitter = function_exists( 'wp_rand' ) ? wp_rand( -60, 60 ) : 0;
			$ttl    = 5 * $minute + (int) $jitter;
			$this->setCached( $tkey, $this->last_result, max( 60, $ttl ) );
			return $this->last_result;
		}
		// Definitive keyed outcomes refresh last-known-good. A Zen free-tier
		// quota stop proves the key is valid, so it counts as a good result.
		$this->applyLastGood( $state );
		// Stagger expiry ±60s to avoid synchronized stampedes.
		$minute = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
		$jitter = function_exists( 'wp_rand' ) ? wp_rand( -60, 60 ) : 0;
		$ttl    = 5 * $minute + (int) $jitter;
		$this->setCached( $tkey, $this->last_result, max( 60, $ttl ) );
		return $this->last_result;
	}

	/**
	 * Read the last-known-good configured flag for this catalog.
	 *
	 * Transient-only and credential-blind: stores only whether a keyed probe
	 * previously succeeded, never any option value.
	 *
	 * @since 0.1.6
	 *
	 * @return bool
	 */
	private function readLastGood(): bool {
		$value = $this->getCached( Catalog::AVAIL_PREFIX . $this->catalog . Catalog::LAST_GOOD_SUFFIX );
		return ! empty( $value );
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
		if ( in_array( $state, ConnectionDiagnostics::KEYED_STATES, true ) ) {
			$this->writeLastGood( true );
			return;
		}
		if ( in_array( $state, ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES, true ) ) {
			$this->writeLastGood( false );
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
	 * @since 0.1.6
	 *
	 * @param bool $good Whether a keyed probe just succeeded.
	 * @return void
	 */
	private function writeLastGood( bool $good ): void {
		$key = Catalog::AVAIL_PREFIX . $this->catalog . Catalog::LAST_GOOD_SUFFIX;
		if ( ! $good ) {
			$this->deleteCached( $key );
			return;
		}
		// Rewritten unconditionally, and deliberately so: this is what pushes
		// the TTL forward. See the docblock before changing it.
		$day = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
		$this->setCached( $key, 1, 30 * $day );
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
		if ( ! function_exists( 'get_transient' ) ) {
			return false;
		}
		try {
			return get_transient( $key );
		} catch ( \Throwable $read_exception ) {
			unset( $read_exception );
			// Fail-open: an unreadable cache is a miss, never a fatal.
			return false;
		}
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
		if ( ! function_exists( 'set_transient' ) ) {
			return false;
		}
		try {
			return (bool) set_transient( $key, $value, $ttl );
		} catch ( \Throwable $write_exception ) {
			unset( $write_exception );
			// Fail-open: caching must never be fatal.
			return false;
		}
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
		if ( ! function_exists( 'delete_transient' ) ) {
			return false;
		}
		try {
			return (bool) delete_transient( $key );
		} catch ( \Throwable $delete_exception ) {
			unset( $delete_exception );
			// Fail-open: the entry expires on its own TTL.
			return false;
		}
	}

	/**
	 * Diagnostics collaborator (canonical instance unless overridden).
	 *
	 * @since 0.1.6
	 *
	 * @return ConnectionDiagnostics
	 */
	private function diagnostics(): ConnectionDiagnostics {
		return $this->diagnostics_override ?? new ConnectionDiagnostics();
	}

	/**
	 * Last safe result retained for a caller.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $last_result = null;
}
