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
	 * Verdict: connected (2xx, throttled, or valid-key-no-credits).
	 *
	 * @since 0.1.5
	 */
	const VERDICT_CONNECTED = 'connected';

	/**
	 * Verdict: bad key (401 without CreditsError, or missing auth).
	 *
	 * @since 0.1.5
	 */
	const VERDICT_INVALID_KEY = 'invalid_key';

	/**
	 * Verdict: transport/server failure (exceptions, 5xx, other 4xx).
	 *
	 * @since 0.1.5
	 */
	const VERDICT_NETWORK_ERROR = 'network_error';

	/**
	 * Verdict: no key configured (auth unavailable).
	 *
	 * @since 0.1.5
	 */
	const VERDICT_NOT_CONFIGURED = 'not_configured';

	/**
	 * Whether the provider is configured.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		if ( function_exists( 'get_transient' ) ) {
			try {
				$cached = get_transient( 'opencode_connector_avail_' . $this->catalog );
			} catch ( \Throwable $e ) {
				$cached = false;
			}
			if ( false !== $cached ) {
				return (bool) $cached;
			}
		}
		return self::VERDICT_CONNECTED === $this->check_connection();
	}

	/**
	 * Credential-blind connection verdict with a short cache.
	 *
	 * Distinguishes a bad key from a network failure so the Test Connection
	 * button can report distinct causes. Credential-blind: reads no
	 * `connectors_ai_*` option value; authentication flows through the SDK
	 * transporter. Verdicts are cached for 5 minutes.
	 *
	 * @since 0.1.5
	 *
	 * @return string One of connected, invalid_key, network_error, not_configured.
	 */
	public function check_connection(): string {
		$catalog = ( 'go' === $this->catalog ) ? 'go' : 'zen';
		$tkey    = 'opencode_connector_test_' . $catalog;

		if ( function_exists( 'get_transient' ) ) {
			try {
				$cached = get_transient( $tkey );
			} catch ( \Throwable $e ) {
				$cached = false;
			}
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		// Stampede protection: short lock so concurrent checks share one probe.
		$lock_key = $tkey . '_lock';
		if ( function_exists( 'get_transient' ) ) {
			try {
				$locked = get_transient( $lock_key );
			} catch ( \Throwable $e ) {
				$locked = false;
			}
			if ( false !== $locked ) {
				return self::VERDICT_NETWORK_ERROR;
			}
		}
		if ( function_exists( 'set_transient' ) ) {
			try {
				set_transient( $lock_key, 1, 10 );
			} catch ( \Throwable $e ) {
				// Fail-open: probe without the lock.
				unset( $e );
			}
		}

		try {
			$verdict = $this->execute_probe();
		} catch ( \Throwable $e ) {
			unset( $e );
			$verdict = self::VERDICT_NETWORK_ERROR;
		}

		if ( function_exists( 'delete_transient' ) ) {
			try {
				delete_transient( $lock_key );
			} catch ( \Throwable $e ) {
				// Fail-open.
				unset( $e );
			}
		}
		if ( function_exists( 'set_transient' ) ) {
			$ttl = 5 * ( defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 );
			try {
				set_transient( $tkey, $verdict, $ttl );
			} catch ( \Throwable $e ) {
				// Fail-open: uncached verdict.
				unset( $e );
			}
			// Keep the legacy bool probe cache in sync (fail-open).
			try {
				$legacy     = 'opencode_connector_avail_' . $catalog;
				$legacy_ttl = $ttl + ( function_exists( 'wp_rand' ) ? wp_rand( -60, 60 ) : 0 );
				set_transient( $legacy, (int) ( self::VERDICT_CONNECTED === $verdict ), max( 60, $legacy_ttl ) );
			} catch ( \Throwable $e ) {
				// Fail-open.
				unset( $e );
			}
		}
		return $verdict;
	}

	/**
	 * Map an HTTP probe outcome to a verdict.
	 *
	 * Pure helper so verdict mapping is unit-testable without HTTP.
	 *
	 * @since 0.1.5
	 *
	 * @param int    $code            HTTP status code (0 when no response).
	 * @param string $error_type      Upstream `error.type` string ('' when absent).
	 * @param bool   $had_exception   Whether the transport threw.
	 * @param bool   $auth_unavailable Whether request authentication was unavailable.
	 * @return string Verdict string.
	 */
	public static function map_verdict( int $code, string $error_type = '', bool $had_exception = false, bool $auth_unavailable = false ): string {
		if ( $auth_unavailable ) {
			return self::VERDICT_NOT_CONFIGURED;
		}
		if ( $had_exception || 0 === $code ) {
			return self::VERDICT_NETWORK_ERROR;
		}
		if ( $code >= 200 && $code < 300 ) {
			return self::VERDICT_CONNECTED;
		}
		if ( 429 === $code ) {
			return self::VERDICT_CONNECTED;
		}
		if ( 401 === $code ) {
			return 'CreditsError' === $error_type ? self::VERDICT_CONNECTED : self::VERDICT_INVALID_KEY;
		}
		return self::VERDICT_NETWORK_ERROR;
	}

	/**
	 * Execute one probe round-trip and return the verdict. Never throws.
	 *
	 * @since 0.1.5
	 *
	 * @return string Verdict string.
	 */
	private function execute_probe(): string {
		try {
			$this->getRequestAuthentication();
		} catch ( \Throwable $e ) {
			return self::VERDICT_NOT_CONFIGURED;
		}

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
			$req  = $this->getRequestAuthentication()->authenticateRequest( $req );
			$res  = $this->getHttpTransporter()->send( $req );
			$code = $res->getStatusCode();
			if ( 401 === $code ) {
				$data     = $res->getData();
				$err_type = is_array( $data ) ? ( $data['error']['type'] ?? '' ) : '';
				return self::map_verdict( $code, is_string( $err_type ) ? $err_type : '' );
			}
			return self::map_verdict( (int) $code );
		} catch ( \Throwable $e ) {
			return self::map_verdict( 0, '', true );
		}
	}
}
