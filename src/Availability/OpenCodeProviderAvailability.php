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

use OpenCodeConnector\Catalog;
use OpenCodeConnector\Http\RequestFactory;
use OpenCodeConnector\Http\SessionHeader;
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
	 * @param string $catalog Catalog slug (go|zen).
	 * @throws \InvalidArgumentException When the catalog is unknown.
	 */
	public function __construct( private readonly string $catalog ) {
		Catalog::require_valid( $catalog );
	}

	/**
	 * Whether the provider is configured.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		try {
			$this->getRequestAuthentication();
		} catch ( \Throwable $e ) {
			return false;
		}

		$tkey   = Catalog::transient_key( $this->catalog );
		$cached = $this->load_cached( $tkey );
		if ( null !== $cached ) {
			return $cached;
		}

		// Stampede protection: short lock so concurrent requests share one probe.
		$lock_key = $tkey . '_lock';
		if ( ! $this->acquire_lock( $lock_key ) ) {
			return false;
		}

		$cls = Catalog::provider_class( $this->catalog );
		$req = $this->probe_request( $cls );
		try {
			$req = $this->getRequestAuthentication()->authenticateRequest( $req );
			$res = $this->getHttpTransporter()->send( $req );
			$ok  = $this->classify_response( $res->getStatusCode(), $res->getData() );
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		$this->store_result( $tkey, $lock_key, $ok );
		return $ok;
	}

	/**
	 * Load a cached probe verdict.
	 *
	 * @since 0.1.5
	 *
	 * @param string $transient_key Availability transient key.
	 * @return bool|null Cached verdict, or null on cache miss.
	 */
	private function load_cached( string $transient_key ): ?bool {
		$cached = get_transient( $transient_key );
		if ( false === $cached ) {
			return null;
		}
		return (bool) $cached;
	}

	/**
	 * Acquire the probe stampede lock (10s TTL).
	 *
	 * @since 0.1.5
	 *
	 * @param string $lock_key Lock transient key.
	 * @return bool True when the lock was acquired.
	 */
	private function acquire_lock( string $lock_key ): bool {
		if ( false !== get_transient( $lock_key ) ) {
			return false;
		}
		// Set lock before network I/O (10s).
		set_transient( $lock_key, 1, 10 );
		return true;
	}

	/**
	 * Build the authentication probe request.
	 *
	 * Probe models are chosen to discriminate AUTHENTICATION, not model
	 * availability: paid models answer 401 CreditsError for a valid but
	 * empty-balance key (configured) versus other 401s for a bad key.
	 * Probing a free model instead would fail closed whenever that model
	 * is transiently unavailable upstream (observed live).
	 *
	 * @since 0.1.5
	 *
	 * @param class-string $provider_class Provider FQCN.
	 * @return Request
	 */
	private function probe_request( string $provider_class ): Request {
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
		$probe_headers = array( 'Content-Type' => 'application/json' );
		if ( SessionHeader::should_send_for( $provider_class ) ) {
			$probe_headers = SessionHeader::inject_into_headers( $probe_headers, $probe_data );
		}
		return RequestFactory::for_provider(
			$provider_class,
			HttpMethodEnum::POST(),
			'chat/completions',
			$probe_headers,
			$probe_data
		);
	}

	/**
	 * Classify a probe response into configured/not-configured.
	 *
	 * Availability policy, directly testable: 2xx → true; 401 +
	 * CreditsError → true (valid key, no credits); 429 → true (throttled:
	 * must not lock out valid users); other 4xx/5xx + exceptions → false.
	 *
	 * @since 0.1.5
	 *
	 * @param int   $status_code HTTP status code.
	 * @param mixed $data        Decoded response data.
	 * @return bool
	 */
	private function classify_response( int $status_code, $data ): bool {
		if ( $status_code >= 200 && $status_code < 300 ) {
			return true;
		}
		if ( 429 === $status_code ) {
			return true;
		}
		if ( 401 === $status_code ) {
			$err_type = is_array( $data ) ? ( $data['error']['type'] ?? '' ) : '';
			return 'CreditsError' === $err_type;
		}
		return false;
	}

	/**
	 * Persist a probe verdict with staggered expiry.
	 *
	 * @since 0.1.5
	 *
	 * @param string $transient_key Availability transient key.
	 * @param string $lock_key      Lock transient key.
	 * @param bool   $ok            Probe verdict.
	 * @return void
	 */
	private function store_result( string $transient_key, string $lock_key, bool $ok ): void {
		// Stagger expiry ±60s to avoid synchronized stampedes.
		$ttl = 5 * MINUTE_IN_SECONDS + wp_rand( -60, 60 );
		delete_transient( $lock_key );
		set_transient( $transient_key, (int) $ok, max( 60, $ttl ) );
	}
}
