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

		$tkey   = 'opencode_connector_avail_' . $this->catalog;
		$cached = get_transient( $tkey );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		// Stampede protection: short lock so concurrent requests share one probe.
		$lock_key = $tkey . '_lock';
		if ( false !== get_transient( $lock_key ) ) {
			return false;
		}

		// Set lock before network I/O (10s).
		set_transient( $lock_key, 1, 10 );

		$cls         = 'go' === $this->catalog ? OpenCodeGoProvider::class : OpenCodeZenProvider::class;
		// Probe models are chosen to discriminate AUTHENTICATION, not model
		// availability: paid models answer 401 CreditsError for a valid but
		// empty-balance key (configured) versus other 401s for a bad key.
		// Probing a free model instead would fail closed whenever that model
		// is transiently unavailable upstream (observed live).
		$probe_model = 'deepseek-v4-flash';
		$probe_data = array(
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
			$req  = $this->getRequestAuthentication()->authenticateRequest( $req );
			$res  = $this->getHttpTransporter()->send( $req );
			$code = $res->getStatusCode();
			if ( $code >= 200 && $code < 300 ) {
				$ok = true;
			} elseif ( 429 === $code ) {
				$ok = true;
			} elseif ( 401 === $code ) {
				$data     = $res->getData();
				$err_type = is_array( $data ) ? ( $data['error']['type'] ?? '' ) : '';
				$ok       = 'CreditsError' === $err_type;
			} else {
				$ok = false;
			}
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		// Stagger expiry ±60s to avoid synchronized stampedes.
		$ttl = 5 * MINUTE_IN_SECONDS + wp_rand( -60, 60 );
		delete_transient( $lock_key );
		set_transient( $tkey, (int) $ok, max( 60, $ttl ) );
		return $ok;
	}
}
