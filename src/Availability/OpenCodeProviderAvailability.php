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
	 * answers 401 CreditsError (configured) versus a rejected credential.
	 * Probing a free model instead would fail closed whenever that model is
	 * transiently unavailable upstream. The same model backs the opt-in
	 * verification probe, and both fall back to a second reviewed, paid
	 * allowlisted model when the gateway refuses this one model-side, so a
	 * retired probe model can never read as an invalid key.
	 *
	 * @since 0.1.6
	 */
	const PROBE_MODEL = 'deepseek-v4-flash';

	/**
	 * States that prove the key was accepted by the gateway.
	 *
	 * `free_tier_limit` is a Zen free-tier quota stop, which is still a valid
	 * key, so it counts as configured even though nothing is usable.
	 *
	 * @var list<string>
	 */
	private const KEYED_STATES = array( 'verified', 'no_credits', 'rate_limited', 'free_tier_limit' );

	/**
	 * States that say nothing about the credential.
	 *
	 * 5xx, transport failures, concurrent probes, a response that matched no
	 * known rule (`unknown`), and a 401 that names no credential error
	 * (`probe_model_unavailable`) all preserve last-known-good state instead of
	 * flipping a valid key to not-connected.
	 *
	 * @var list<string>
	 */
	private const COULD_NOT_BE_CHECKED_STATES = array( 'uncheckable', 'network_error', 'server_error', 'unknown', 'probe_model_unavailable' );

	/**
	 * States that are a definitive negative for the credential.
	 *
	 * Only these clear the 30-day last-known-good flag; an unexpected upstream
	 * status must never destroy good state.
	 *
	 * @var list<string>
	 */
	private const DEFINITIVE_NEGATIVE_STATES = array( 'not_configured', 'invalid_key' );

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
	 * Follows the diagnosis instead of re-deriving truth from the state name:
	 * a state the gateway accepted stays configured; every could-not-be-checked
	 * state (5xx, transport failure, concurrent probe, an unrecognised
	 * response, or a model-side 401) falls back to last-known-good; only
	 * `not_configured` and `invalid_key` are definitive negatives. Unkeyed
	 * installs still read as not configured.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		$result = $this->probe();
		$state  = $this->stateOf( $result );
		// Definitive, keyed outcomes stay configured.
		if ( in_array( $state, self::KEYED_STATES, true ) ) {
			return true;
		}
		// Could-not-be-checked outcomes fall back to last-known-good instead of
		// flipping a valid key to not-connected during an outage.
		if ( in_array( $state, self::COULD_NOT_BE_CHECKED_STATES, true ) ) {
			return $this->readLastGood();
		}
		// A state this plugin does not know is not a credential verdict. Trust
		// the diagnosis when it says the gateway accepted the key, and let only
		// last-known-good answer.
		return true === ( $result['configured'] ?? false ) && $this->readLastGood();
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
		$diagnostics = $this->diagnostics();
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
		$cls       = $this->providerClassName();
		if ( ! $this->sdkSurfaceAvailable( $cls ) ) {
			$diagnosis = $diagnostics->classify( 0, null, new \RuntimeException( 'verify surface unavailable' ) );
		} else {
			// Same guarded send, headers, and probe-model fallback as the
			// availability probe, so the two paths cannot drift.
			$diagnosis = $this->sendProbe( $diagnostics, $cls, $this->probeModels() );
		}

		$state = null !== $diagnosis ? $diagnostics->verify_state( $diagnosis ) : 'could-not-be-checked';
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

		// The whole SDK touch (class_exists(), Request construction, url()) is
		// inside the guarded send, so a missing DTO or enum degrades to
		// could-not-be-checked instead of escaping probe() with the lock held.
		$this->last_result = $this->sendProbe( $diagnostics, $this->providerClassName(), $this->probeModels() );
		$this->deleteCached( $lock_key );
		$state = $this->stateOf( $this->last_result );
		// Could-not-be-checked (5xx, transport failure, concurrent probe, or a
		// response that says nothing about the key): never write the failure to
		// last-known-good. The verdict is cached briefly so a persistent
		// upstream problem costs one probe per window instead of one per call,
		// while isConfigured() keeps failing open.
		if ( in_array( $state, self::COULD_NOT_BE_CHECKED_STATES, true ) ) {
			$second = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
			$this->setCached( $tkey, $this->last_result, $second );
			return $this->last_result;
		}
		// Definitive keyed outcomes refresh last-known-good. A Zen free-tier
		// quota stop proves the key is valid, so it counts as a good result.
		// Only a definitive negative (unkeyed, rejected credential) clears it:
		// an unexpected upstream answer must never destroy good state.
		$this->writeLastGood( ! in_array( $state, self::DEFINITIVE_NEGATIVE_STATES, true ) );
		// Stagger expiry ±60s to avoid synchronized stampedes.
		$minute = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
		$jitter = function_exists( 'wp_rand' ) ? wp_rand( -60, 60 ) : 0;
		$ttl    = 5 * $minute + (int) $jitter;
		$this->setCached( $tkey, $this->last_result, max( 60, $ttl ) );
		return $this->last_result;
	}

	/**
	 * Send the one-token probe for each candidate model and classify the last.
	 *
	 * @since 0.1.8
	 *
	 * @param ConnectionDiagnostics $diagnostics Classifier.
	 * @param string                $cls        Provider class.
	 * @param string[]              $models     Probe models to try, in order.
	 * @return array<string, mixed>
	 */
	private function sendProbe( ConnectionDiagnostics $diagnostics, string $cls, array $models ): array {
		if ( ! $this->sdkSurfaceAvailable( $cls ) ) {
			return $diagnostics->classify( 0, null, new \RuntimeException( 'probe surface unavailable' ) );
		}
		$result     = null;
		$last_index = count( $models ) - 1;
		foreach ( $models as $index => $probe_model ) {
			$result = $this->sendProbeOnce( $diagnostics, $cls, (string) $probe_model );
			// Probe-model drift (OpenCode retires or renames a model): a
			// model-side 401 is not a credential verdict, so retry once with the
			// next candidate instead of reporting a valid key as invalid.
			if ( 'probe_model_unavailable' !== $this->stateOf( $result ) || $index >= $last_index ) {
				break;
			}
		}
		return $result ?? $diagnostics->classify( 0, null, new \RuntimeException( 'probe transport failure' ) );
	}

	/**
	 * Send one one-token chat/completions probe and classify the response.
	 *
	 * Never throws: any failure (missing DTO, unavailable provider class,
	 * transport error) becomes a could-not-be-checked verdict.
	 *
	 * @since 0.1.8
	 *
	 * @param ConnectionDiagnostics $diagnostics Classifier.
	 * @param string                $cls        Provider class.
	 * @param string                $probe_model Model ID to probe.
	 * @return array<string, mixed>
	 */
	private function sendProbeOnce( ConnectionDiagnostics $diagnostics, string $cls, string $probe_model ): array {
		try {
			$probe_data    = array(
				'model'      => $probe_model,
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => 'ping',
					),
				),
				'max_tokens' => 1,
			);
			$probe_headers = $this->probeHeaders( array( 'Content-Type' => 'application/json' ), $probe_data );
			$req           = new Request(
				HttpMethodEnum::POST(),
				$cls::url( 'chat/completions' ),
				$probe_headers,
				$probe_data
			);
			$req           = $this->getRequestAuthentication()->authenticateRequest( $req );
			$res           = $this->getHttpTransporter()->send( $req );
			$data          = $res->getData();
			return $diagnostics->classify( $res->getStatusCode(), is_array( $data ) ? $data : null );
		} catch ( \Throwable $exception ) {
			return $diagnostics->classify( 0, null, $exception );
		}
	}

	/**
	 * Build the probe request headers for this catalog.
	 *
	 * One helper for both the availability probe and the verification probe so
	 * the two paths cannot fingerprint differently to the gateway: the Go
	 * catalog rejects requests without `x-opencode-session` (400
	 * MissingSessionID) and OpenCode asks clients to identify themselves with
	 * their own User-Agent, so the Go pair (session + client User-Agent) is
	 * applied here. The opencode user agent is never spoofed. Never throws.
	 *
	 * @since 0.1.8
	 *
	 * @param array $base_headers Base request headers.
	 * @param array $probe_data   Probe payload.
	 * @return array
	 */
	private function probeHeaders( array $base_headers, array $probe_data ): array {
		try {
			if ( Catalog::GO === $this->catalog && class_exists( GoRequestHeaders::class ) && method_exists( GoRequestHeaders::class, 'for_go' ) ) {
				return GoRequestHeaders::for_go( $base_headers, $probe_data );
			}
			if ( class_exists( SessionHeader::class ) && method_exists( SessionHeader::class, 'inject_into_headers' ) ) {
				return SessionHeader::inject_into_headers( $base_headers, $probe_data );
			}
		} catch ( \Throwable ) {
			// Fail-open: an unmapped header is not a reason to fail the probe.
			return $base_headers;
		}
		return $base_headers;
	}

	/**
	 * Probe models to try, in order.
	 *
	 * The first entry is the curated paid model, which discriminates
	 * authentication (a valid but empty-balance key answers 401
	 * CreditsError). The second is a different reviewed, paid allowlisted
	 * model, used only when the first is refused model-side, so a retired
	 * PROBE_MODEL cannot present itself as a credential failure. Probing a
	 * free model is never the answer: it would fail closed whenever that model
	 * is transiently unavailable upstream (observed live).
	 *
	 * @since 0.1.8
	 *
	 * @return list<string>
	 */
	private function probeModels(): array {
		$models   = array( self::PROBE_MODEL );
		$fallback = $this->fallbackProbeModel();
		if ( '' !== $fallback ) {
			$models[] = $fallback;
		}
		return $models;
	}

	/**
	 * Resolve a different reviewed, paid allowlisted model for this catalog.
	 *
	 * @since 0.1.8
	 *
	 * @return string Empty string when the registry cannot supply one.
	 */
	private function fallbackProbeModel(): string {
		if ( ! class_exists( ModelRegistry::class ) || ! method_exists( ModelRegistry::class, 'records' ) ) {
			return '';
		}
		try {
			$records = ModelRegistry::records( $this->catalog );
		} catch ( \Throwable ) {
			return '';
		}
		if ( ! is_array( $records ) ) {
			return '';
		}
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$id = (string) ( $record['id'] ?? '' );
			if ( '' === $id || self::PROBE_MODEL === $id ) {
				continue;
			}
			// Paid and reviewed only: a free model cannot discriminate a valid
			// key from an empty balance, and an unreviewed one would spread
			// unproven assumptions.
			if ( true === ( $record['free'] ?? false ) || ! ModelRegistry::isReviewed( $record ) ) {
				continue;
			}
			return $id;
		}
		return '';
	}

	/**
	 * Provider class for this catalog.
	 *
	 * @since 0.1.8
	 *
	 * @return string
	 */
	private function providerClassName(): string {
		return Catalog::GO === $this->catalog ? OpenCodeGoProvider::class : OpenCodeZenProvider::class;
	}

	/**
	 * Whether the SDK surface both probes depend on is available.
	 *
	 * HttpMethodEnum::POST() is a magic factory served from
	 * AbstractEnum::__callStatic, so method_exists() cannot see it: probe the
	 * backing constant instead, the same rule
	 * AbstractOpenCodeProvider::createProviderMetadata() applies to
	 * ProviderTypeEnum and RequestAuthenticationMethod. `url` is a real static
	 * method on AbstractApiProvider, so method_exists() is correct for it.
	 *
	 * @since 0.1.8
	 *
	 * @param string $cls Provider class.
	 * @return bool
	 */
	private function sdkSurfaceAvailable( string $cls ): bool {
		return class_exists( $cls ) && method_exists( $cls, 'url' )
			&& class_exists( Request::class ) && class_exists( HttpMethodEnum::class )
			&& defined( HttpMethodEnum::class . '::POST' );
	}

	/**
	 * Read the state name from a diagnosis.
	 *
	 * @param array<string, mixed> $result Diagnosis.
	 * @return string Empty string when absent.
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
