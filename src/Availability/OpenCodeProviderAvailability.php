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

use OpenCodeConnector\Http\SessionHeader;
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
	 * transiently unavailable upstream. Keep in sync with the DeepSeek policy
	 * in ModelAllowlist/AbstractOpenCodeModelMetadataDirectory, which exclude
	 * DeepSeek IDs from tool/JSON-schema capability — the probe depends on
	 * the opposite property (paid-model 401 discrimination), not capability.
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
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string                     $catalog Catalog slug.
	 * @param ConnectionDiagnostics|null $diagnostics Optional diagnostics double for tests.
	 */
	public function __construct( private readonly string $catalog, ?ConnectionDiagnostics $diagnostics = null ) {
		// Catalog is go or zen.
		$this->diagnostics_override = $diagnostics;
	}

	/**
	 * Whether the provider is configured, preserving the legacy boolean contract.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		$result = $this->probe();
		return in_array( $result['state'], array( 'verified', 'no_credits', 'rate_limited' ), true );
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
	 * Probe, classify, and briefly cache the safe result.
	 *
	 * @return array<string, mixed>
	 */
	private function probe(): array {
		$cached = $this->readCachedResult();
		if ( null !== $cached ) {
			$this->last_result = $cached;
			return $cached;
		}

		// Stampede protection: short lock so concurrent requests share one probe.
		$lock_key = \OpenCodeConnector\Metadata\Catalog::AVAIL_PREFIX . $this->catalog . '_lock';
		if ( false !== get_transient( $lock_key ) ) {
			$this->last_result = $this->diagnostics()->unknown();
			return $this->last_result;
		}

		try {
			$this->getRequestAuthentication();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$this->last_result = $this->diagnostics()->notConfigured();
			return $this->last_result;
		}

		// Set lock before network I/O (10s).
		set_transient( $lock_key, 1, 10 );

		$req = $this->buildProbeRequest();
		try {
			$req               = $this->getRequestAuthentication()->authenticateRequest( $req );
			$res               = $this->getHttpTransporter()->send( $req );
			$this->last_result = $this->diagnostics()->classify( $res->getStatusCode(), $res->getData() );
		} catch ( \Throwable $exception ) {
			$this->last_result = $this->diagnostics()->classify( 0, null, $exception );
		}
		$this->persistResult( $this->last_result );
		return $this->last_result;
	}

	/**
	 * Read a cached probe result, preserving the legacy boolean contract.
	 *
	 * @since 0.1.6
	 *
	 * @return array<string, mixed>|null Null when no usable cache exists.
	 */
	private function readCachedResult(): ?array {
		$tkey   = \OpenCodeConnector\Metadata\Catalog::AVAIL_PREFIX . $this->catalog;
		$cached = get_transient( $tkey );
		if ( is_array( $cached ) && isset( $cached['state'] ) ) {
			return $cached;
		}
		if ( false !== $cached && is_bool( $cached ) ) {
			return $cached ? $this->diagnostics()->verified() : $this->diagnostics()->notConfigured();
		}
		return null;
	}

	/**
	 * Build the probe request for the bound catalog.
	 *
	 * @since 0.1.6
	 *
	 * @return Request
	 */
	private function buildProbeRequest(): Request {
		$cls        = \OpenCodeConnector\Metadata\Catalog::GO === $this->catalog ? OpenCodeGoProvider::class : OpenCodeZenProvider::class;
		$probe_data = array(
			'model'      => self::PROBE_MODEL,
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
		// derived from its own payload. Zen ignores the extra header.
		$probe_headers = SessionHeader::inject_into_headers(
			array( 'Content-Type' => 'application/json' ),
			$probe_data
		);
		return new Request(
			HttpMethodEnum::POST(),
			$cls::url( 'chat/completions' ),
			$probe_headers,
			$probe_data
		);
	}

	/**
	 * Persist a probe result with jittered TTL and release the lock.
	 *
	 * @since 0.1.6
	 *
	 * @param array<string, mixed> $result Classified result.
	 * @return void
	 */
	private function persistResult( array $result ): void {
		$tkey = \OpenCodeConnector\Metadata\Catalog::AVAIL_PREFIX . $this->catalog;
		// Stagger expiry ±60s to avoid synchronized stampedes.
		$ttl = 5 * MINUTE_IN_SECONDS + wp_rand( -60, 60 );
		delete_transient( $tkey . '_lock' );
		set_transient( $tkey, $result, max( 60, $ttl ) );
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
