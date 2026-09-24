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
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $catalog Catalog slug.
	 */
	public function __construct( private readonly string $catalog ) {
		// Catalog is go or zen.
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
		return $this->last_result ?? ( new ConnectionDiagnostics() )->unknown();
	}

	/**
	 * Probe, classify, and briefly cache the safe result.
	 *
	 * @return array<string, mixed>
	 */
	private function probe(): array {
		$tkey   = 'opencode_connector_avail_' . $this->catalog;
		$cached = get_transient( $tkey );
		if ( is_array( $cached ) && isset( $cached['state'] ) ) {
			$this->last_result = $cached;
			return $cached;
		}
		if ( false !== $cached && is_bool( $cached ) ) {
			$diagnostics       = new ConnectionDiagnostics();
			$this->last_result = $cached ? $diagnostics->verified() : $diagnostics->notConfigured();
			return $this->last_result;
		}

		// Stampede protection: short lock so concurrent requests share one probe.
		$lock_key = $tkey . '_lock';
		if ( false !== get_transient( $lock_key ) ) {
			$this->last_result = ( new ConnectionDiagnostics() )->unknown();
			return $this->last_result;
		}

		$diagnostics = new ConnectionDiagnostics();
		try {
			$this->getRequestAuthentication();
		} catch ( \Throwable $exception ) {
			$this->last_result = $diagnostics->notConfigured();
			return $this->last_result;
		}

		// Set lock before network I/O (10s).
		set_transient( $lock_key, 1, 10 );

		$cls = 'go' === $this->catalog ? OpenCodeGoProvider::class : OpenCodeZenProvider::class;
		// Probe models are chosen to discriminate AUTHENTICATION, not model
		// availability: paid models answer 401 CreditsError for a valid but
		// empty-balance key (configured) versus other 401s for a bad key.
		// Probing a free model instead would fail closed whenever that model
		// is transiently unavailable upstream (observed live).
		$probe_model = 'deepseek-v4-flash';
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
		// derived from its own payload. Zen ignores the extra header.
		$probe_headers = SessionHeader::inject_into_headers(
			array( 'Content-Type' => 'application/json' ),
			$probe_data
		);
		$req           = new Request(
			HttpMethodEnum::POST(),
			$cls::url( 'chat/completions' ),
			$probe_headers,
			$probe_data
		);
		try {
			$req               = $this->getRequestAuthentication()->authenticateRequest( $req );
			$res               = $this->getHttpTransporter()->send( $req );
			$this->last_result = $diagnostics->classify( $res->getStatusCode(), $res->getData() );
		} catch ( \Throwable $exception ) {
			$this->last_result = $diagnostics->classify( 0, null, $exception );
		}
		// Stagger expiry ±60s to avoid synchronized stampedes.
		$ttl = 5 * MINUTE_IN_SECONDS + wp_rand( -60, 60 );
		delete_transient( $lock_key );
		set_transient( $tkey, $this->last_result, max( 60, $ttl ) );
		return $this->last_result;
	}

	/**
	 * Last safe result retained for a caller.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $last_result = null;
}
