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
use OpenCodeConnector\Metadata\ModelRegistry;
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
	 * transport failures, concurrent probes) preserve last-known-good state
	 * instead of flipping valid keys to not-connected. Unkeyed installs
	 * still read as not configured.
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
		// flipping a valid key to not-connected during an outage. This bucket
		// includes `unknown`: an unrecognised response reached the gateway, so it
		// is not evidence about the credential, and returning false for it
		// disconnected working keys whenever the upstream introduced a status.
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

		$cls = 'go' === $this->catalog ? OpenCodeGoProvider::class : OpenCodeZenProvider::class;
		// Probe models are chosen to discriminate AUTHENTICATION, not model
		// availability: paid models answer 401 CreditsError for a valid but
		// empty-balance key (configured) versus other 401s for a bad key.
		// Probing a free model instead would fail closed whenever that model
		// is transiently unavailable upstream (observed live).
		//
		// A second candidate exists so a RETIRED probe model cannot present itself
		// as a credential failure: when the first candidate is refused
		// model-side, the probe retries with the next reviewed, paid model
		// instead of reporting a valid key as invalid.
		$probe_models = $this->probeModels();
		// Lock sized for the whole round: two sequential blocking requests must
		// not outlive the lock, or a second request starts a full re-probe.
		$this->setCached( $lock_key, 1, 10 * count( $probe_models ) );

		$last_index = count( $probe_models ) - 1;
		foreach ( $probe_models as $index => $probe_model ) {
			$probe_data = array(
				'model'      => (string) $probe_model,
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
			if ( ! $this->isProbeModelDrift( $this->last_result ) || $index >= $last_index ) {
				break;
			}
		}
		$this->deleteCached( $lock_key );
		$state = isset( $this->last_result['state'] ) && is_string( $this->last_result['state'] ) ? $this->last_result['state'] : '';
		// Could-not-be-checked (5xx, transport failure, concurrent probe, or a
		// response that says nothing about the key): never write the failure to
		// last-known-good. The verdict is cached briefly so a persistent outage
		// costs one probe per window instead of one per call, while
		// isConfigured() keeps failing open.
		if ( in_array( $state, ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES, true ) ) {
			if ( $this->isDriftStatus( $this->last_result ) ) {
				// Probe-model drift does not resolve in a minute: a retired model
				// will not come back within this window. A short cache here costs
				// two probe requests a minute for as long as the drift lasts.
				$minute = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
				$jitter = function_exists( 'wp_rand' ) ? wp_rand( -60, 60 ) : 0;
				$this->setCached( $tkey, $this->last_result, max( 60, 5 * $minute + (int) $jitter ) );
				return $this->last_result;
			}
			$second = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
			$this->setCached( $tkey, $this->last_result, $second );
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
	 * Candidate probe models to try, in order.
	 *
	 * The first is the curated paid model, which discriminates authentication.
	 * The second is a different reviewed, paid allowlisted model, used only when
	 * the first is refused model-side. A FREE model is never a candidate: it
	 * would fail closed whenever that model is transiently unavailable upstream
	 * (observed live).
	 *
	 * @return list<string>
	 */
	private function probeModels(): array {
		$models = array( self::PROBE_MODEL );
		$second = ModelRegistry::fallbackProbeModel( $this->catalog, self::PROBE_MODEL );
		if ( null !== $second ) {
			$models[] = $second;
		}
		return $models;
	}

	/**
	 * Whether a verdict means the probe model itself was refused.
	 *
	 * Probe-model drift (OpenCode retires or renames a model) is not a
	 * credential verdict, so the probe is retried with the next candidate
	 * instead of reporting a valid key as invalid. Two shapes count: a 401 the
	 * gateway attributes to the model, and a model-not-found at 400/404, which
	 * is at least as likely once a model is renamed. No credential verdict is
	 * weakened by this predicate.
	 *
	 * The status list is read from
	 * ConnectionDiagnostics::PROBE_MODEL_DRIFT_STATUSES rather than restated
	 * here. The retry decision and the cache-window decision below must agree
	 * about which statuses mean drift; they are the same fact, so they read the
	 * same list.
	 *
	 * @param array<string, mixed> $result Diagnosis of one attempt.
	 * @return bool
	 */
	private function isProbeModelDrift( array $result ): bool {
		if ( 'probe_model_unavailable' === $this->stateOf( $result ) ) {
			return true;
		}
		return 'unknown' === $this->stateOf( $result )
			&& in_array( (int) ( $result['status'] ?? 0 ), ConnectionDiagnostics::PROBE_MODEL_DRIFT_STATUSES, true );
	}

	/**
	 * Whether an unrecognised verdict arrived at one of the drift statuses.
	 *
	 * Reads the same published list as isProbeModelDrift(), so a status added
	 * there cannot be retried on one path and re-probed every minute on the
	 * other.
	 *
	 * @param array<string, mixed> $result Diagnosis.
	 * @return bool
	 */
	private function isDriftStatus( array $result ): bool {
		return 'unknown' === $this->stateOf( $result )
			&& in_array( (int) ( $result['status'] ?? 0 ), ConnectionDiagnostics::PROBE_MODEL_DRIFT_STATUSES, true );
	}

	/**
	 * Read a result's state.
	 *
	 * @param array<string, mixed> $result Diagnosis.
	 * @return string
	 */
	private function stateOf( array $result ): string {
		return isset( $result['state'] ) && is_string( $result['state'] ) ? $result['state'] : '';
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
