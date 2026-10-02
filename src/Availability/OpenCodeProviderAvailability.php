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
	 * @since 0.1.6
	 */
	const PROBE_MODEL = 'deepseek-v4-flash';

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
		return false;
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
	 * @return array<string, mixed>
	 */
	public function getLastResult(): array {
		return $this->last_result ?? $this->diagnostics()->unknown();
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
		$diagnostics = class_exists( ConnectionDiagnostics::class ) ? $this->diagnostics() : null;
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
		} catch ( \Throwable ) {
			$verdict = $this->verify_result( 'invalid_key', null, $diagnostics );
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
				$diagnosis = null !== $diagnostics ? $diagnostics->classify( $res->getStatusCode(), $res->getData() ) : null;
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
					if ( 'invalid_key' === $state ) {
						$diagnosis = $diagnostics->notConfigured();
					} else {
						$diagnosis = $diagnostics->unknown();
					}
				} catch ( \Throwable $verdict_exception ) {
					unset( $verdict_exception );
					$diagnosis = array( 'state' => 'unknown' );
				}
			} else {
				$diagnosis = array( 'state' => 'unknown' );
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
		$req           = new Request(
			HttpMethodEnum::POST(),
			$cls::url( 'chat/completions' ),
			$probe_headers,
			$probe_data
		);
		try {
			$req               = $this->getRequestAuthentication()->authenticateRequest( $req );
			$res               = $this->getHttpTransporter()->send( $req );
			$data              = $res->getData();
			$this->last_result = $diagnostics->classify( $res->getStatusCode(), is_array( $data ) ? $data : null );
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
		// a 404 after an upstream model rename is the standing case. It also
		// takes the full jittered window because the one-minute branch has no
		// jitter at all, so routing it there would make every site whose
		// upstream answers 400/404 re-probe in lockstep on the same 60-second
		// boundary — precisely the synchronized stampede the jitter below
		// exists to prevent, introduced by the very fix that is supposed to
		// quieten the probe.
		if ( in_array( $state, ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES, true ) ) {
			if ( ConnectionDiagnostics::UNKNOWN_STATE !== $state ) {
				$second = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
				$this->setCached( $tkey, $this->last_result, $second );
				return $this->last_result;
			}
			// Persistent: fall through to the jittered window below, skipping
			// the last-known-good writes on the way. It must NOT reach them —
			// `unknown` proves nothing about the key, and writing false here
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
		if ( in_array( $state, ConnectionDiagnostics::KEYED_STATES, true ) ) {
			$this->writeLastGood( true );
		} else {
			$this->writeLastGood( false );
		}
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
	 * minutes per catalog for the life of the install, because WP core's
	 * `set_transient()` has no equality short-circuit. That write is the
	 * accepted price of the guarantee above, not an oversight. If it ever
	 * needs to be avoided, the fix belongs at the storage layer (an
	 * equality check that still extends the TTL), never at the cost of the
	 * rolling behaviour.
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
	 * @since 0.1.6
	 *
	 * @param string $key Transient key.
	 * @return mixed
	 */
	private function getCached( string $key ): mixed {
		if ( ! function_exists( 'get_transient' ) ) {
			return false;
		}
		return get_transient( $key );
	}

	/**
	 * Guarded transient write.
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
		return (bool) set_transient( $key, $value, $ttl );
	}

	/**
	 * Guarded transient delete.
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
		return (bool) delete_transient( $key );
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
