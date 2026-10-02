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
	 * Optional diagnostics collaborator (test seam; defaults to canonical).
	 *
	 * @var ConnectionDiagnostics|null
	 */
	private ?ConnectionDiagnostics $diagnostics_override = null;

	/**
	 * Last safe result retained for a caller.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $last_result = null;

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
		if ( in_array( $state, array( 'verified', 'no_credits', 'rate_limited', 'free_tier_limit' ), true ) ) {
			return true;
		}
		// Could-not-be-checked outcomes fall back to last-known-good instead of
		// flipping a valid key to not-connected during an outage.
		if ( in_array( $state, array( 'uncheckable', 'network_error', 'server_error' ), true ) ) {
			return $this->readLastGood();
		}
		return false;
	}

	/**
	 * Run or reuse the detailed, credential-blind probe result.
	 *
	 * @since 0.1.5
	 *
	 * @return array<string, mixed>
	 */
	public function diagnose(): array {
		return $this->probe();
	}

	/**
	 * Return the most recent safe detailed result.
	 *
	 * @since 0.1.5
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

		$cached = $this->read_cached( $tkey );
		if ( is_array( $cached ) && isset( $cached['state'] ) && is_string( $cached['state'] ) ) {
			return $cached;
		}
		if ( false !== $this->read_cached( $lock_key ) ) {
			return $this->verify_result( 'could-not-be-checked', null, $diagnostics );
		}

		try {
			$this->getRequestAuthentication();
		} catch ( \Throwable ) {
			$verdict = $this->verify_result( 'invalid_key', null, $diagnostics );
			$this->store_verify_verdict( $tkey, $lock_key, $verdict );
			return $verdict;
		}

		// Fail-open: a storage failure just means the probe runs unlocked.
		$this->write_cached( $lock_key, 1, 10 );

		$diagnosis = null;
		$cls       = $this->provider_class_for_catalog();
		// HttpMethodEnum::POST() is a magic factory: the SDK declares it as an
		// `@method static` annotation and serves it from AbstractEnum::__callStatic,
		// so method_exists() cannot see it and is permanently false against the
		// shipped SDK. Probe the backing constant instead, the same rule
		// AbstractOpenCodeProvider::createProviderMetadata() applies to
		// ProviderTypeEnum and RequestAuthenticationMethod. `url` is a real
		// static method on AbstractApiProvider, so method_exists() is correct
		// for it.
		$surface_ok = null !== $cls && class_exists( $cls ) && method_exists( $cls, 'url' )
			&& class_exists( Request::class ) && class_exists( HttpMethodEnum::class ) && defined( HttpMethodEnum::class . '::POST' );
		if ( ! $surface_ok ) {
			try {
				$diagnosis = $diagnostics->classify( 0, null, new \RuntimeException( 'verify surface unavailable' ) );
			} catch ( \Throwable ) {
				$diagnosis = null;
			}
		} else {
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
				$diagnosis = $diagnostics->classify( $res->getStatusCode(), $res->getData() );
			} catch ( \Throwable ) {
				try {
					$diagnosis = $diagnostics->classify( 0, null, new \RuntimeException( 'verify transport failure' ) );
				} catch ( \Throwable ) {
					$diagnosis = null;
				}
			}
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
	 * @param string                    $state       Verification state.
	 * @param array<string, mixed>|null $diagnosis   Safe diagnosis, if any.
	 * @param ConnectionDiagnostics     $diagnostics Diagnostics helper.
	 * @return array<string, mixed>
	 */
	private function verify_result( string $state, ?array $diagnosis, ConnectionDiagnostics $diagnostics ): array {
		if ( null === $diagnosis ) {
			try {
				$diagnosis = 'invalid_key' === $state ? $diagnostics->notConfigured() : $diagnostics->unknown();
			} catch ( \Throwable ) {
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
		$this->delete_cached( $lock_key );
		$minutes = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
		$this->write_cached( $tkey, $verdict, $this->jittered_ttl( 5 * $minutes ) );
	}

	/**
	 * Stagger a cache lifetime by up to a minute either way.
	 *
	 * Prevents every install that probed in the same second from re-probing
	 * in lockstep. One implementation for both probe families, so the two
	 * paths cannot drift apart on the fail-open rules.
	 *
	 * @since 0.1.8
	 *
	 * @param int $base Base lifetime in seconds.
	 * @return int
	 */
	private function jittered_ttl( int $base ): int {
		$ttl = $base;
		if ( function_exists( 'wp_rand' ) ) {
			try {
				$ttl = $base + (int) wp_rand( -60, 60 );
			} catch ( \Throwable ) {
				$ttl = $base;
			}
		}
		return max( 60, $ttl );
	}

	/**
	 * Probe, classify, and briefly cache the safe result.
	 *
	 * @since 0.1.5
	 *
	 * @return array<string, mixed>
	 */
	private function probe(): array {
		$tkey   = Catalog::AVAIL_PREFIX . $this->catalog;
		$cached = $this->read_cached( $tkey );
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
		if ( false !== $this->read_cached( $lock_key ) ) {
			// Concurrent probe: surface could-not-be-checked so isConfigured()
			// can fail open on last-known-good instead of flipping to false.
			$this->last_result = $this->diagnostics()->uncheckable();
			return $this->last_result;
		}

		$diagnostics = $this->diagnostics();
		$cls         = $this->provider_class_for_catalog();
		if ( null === $cls ) {
			// Fail closed: an unknown slug has no provider to probe, and must
			// never borrow the Zen endpoint by default.
			$this->last_result = $diagnostics->notConfigured();
			return $this->last_result;
		}
		try {
			$this->getRequestAuthentication();
		} catch ( \Throwable ) {
			$this->last_result = $diagnostics->notConfigured();
			$this->writeLastGood( false );
			return $this->last_result;
		}

		// Set lock before network I/O (10s).
		$this->write_cached( $lock_key, 1, 10 );

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
		$probe_headers = Catalog::GO === $this->catalog && class_exists( GoRequestHeaders::class )
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
		$this->delete_cached( $lock_key );
		$state = isset( $this->last_result['state'] ) && is_string( $this->last_result['state'] ) ? $this->last_result['state'] : '';
		// Could-not-be-checked (5xx, transport failure, concurrent probe):
		// never write the failure to last-known-good. The verdict is cached
		// briefly so a persistent outage costs one probe per window instead
		// of one per call, while isConfigured() keeps failing open.
		if ( in_array( $state, array( 'uncheckable', 'network_error', 'server_error' ), true ) ) {
			$second = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
			$this->write_cached( $tkey, $this->last_result, $second );
			return $this->last_result;
		}
		// Definitive keyed outcomes refresh last-known-good. A Zen free-tier
		// quota stop proves the key is valid, so it counts as a good result.
		if ( in_array( $state, array( 'verified', 'no_credits', 'rate_limited', 'free_tier_limit' ), true ) ) {
			$this->writeLastGood( true );
		} else {
			$this->writeLastGood( false );
		}
		// Stagger expiry ±60s to avoid synchronized stampedes.
		$minute = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
		$this->write_cached( $tkey, $this->last_result, $this->jittered_ttl( 5 * $minute ) );
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
		return ! empty( $this->read_cached( Catalog::AVAIL_PREFIX . $this->catalog . Catalog::LAST_GOOD_SUFFIX ) );
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
			$this->delete_cached( $key );
			return;
		}
		$day = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
		$this->write_cached( $key, 1, 30 * $day );
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
	 * Transient read that treats a storage failure as a cache miss.
	 *
	 * One fail-open rule for every cached value this class owns: a broken
	 * option API must never turn a probe into a fatal error.
	 *
	 * @since 0.1.8
	 *
	 * @param string $key Transient key.
	 * @return mixed Stored value, or false for a miss or an unreadable store.
	 */
	private function read_cached( string $key ): mixed {
		try {
			return $this->getCached( $key );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Transient write that swallows storage failures.
	 *
	 * @since 0.1.8
	 *
	 * @param string $key   Transient key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Time to live in seconds.
	 * @return void
	 */
	private function write_cached( string $key, mixed $value, int $ttl ): void {
		try {
			$this->setCached( $key, $value, $ttl );
		} catch ( \Throwable ) {
			// Fail-open: caching is an optimization, never a precondition.
			return;
		}
	}

	/**
	 * Transient delete that swallows storage failures.
	 *
	 * @since 0.1.8
	 *
	 * @param string $key Transient key.
	 * @return void
	 */
	private function delete_cached( string $key ): void {
		try {
			$this->deleteCached( $key );
		} catch ( \Throwable ) {
			// Fail-open: a stale key expires on its own; never fatal.
			return;
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
	 * Provider class for this catalog, or null when the slug is unknown.
	 *
	 * One mapping, validated through Catalog: an unrecognised slug returns
	 * null instead of silently falling through to the Zen provider, so a
	 * future third catalog can never borrow Zen's endpoint and credentials
	 * path.
	 *
	 * @since 0.1.8
	 *
	 * @return class-string|null Provider class name, or null for an unknown catalog.
	 */
	private function provider_class_for_catalog(): ?string {
		if ( Catalog::GO === $this->catalog ) {
			return OpenCodeGoProvider::class;
		}
		if ( Catalog::ZEN === $this->catalog ) {
			return OpenCodeZenProvider::class;
		}
		return null;
	}
}
