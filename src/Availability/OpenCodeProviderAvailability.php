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
	 * Delegates to probe() so the boolean verdict and diagnose() share one
	 * cached probe. Behavior is unchanged: 2xx, 429, and 401 CreditsError
	 * count as configured; everything else (including exceptions) does not.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		try {
			$result = $this->probe();
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
		return (bool) ( $result['configured'] ?? false );
	}

	/**
	 * Diagnose the provider state without touching secret values.
	 *
	 * Runs the same cached probe as isConfigured() but maps the outcome to
	 * a cause class instead of collapsing to bool. Never accepts or returns
	 * a key value (credential-blind: status only).
	 *
	 * @since 0.1.5
	 *
	 * @return array{configured: bool, cause: string} Cause is one of validCauses().
	 */
	public function diagnose(): array {
		try {
			$result = $this->probe();
		} catch ( \Throwable $e ) {
			unset( $e );
			return array(
				'configured' => false,
				'cause'      => 'network-failure',
			);
		}
		$cause = isset( $result['cause'] ) && is_string( $result['cause'] ) ? $result['cause'] : 'unknown';
		if ( ! in_array( $cause, self::validCauses(), true ) ) {
			$cause = 'unknown';
		}
		return array(
			'configured' => (bool) ( $result['configured'] ?? false ),
			'cause'      => $cause,
		);
	}

	/**
	 * Allowlisted diagnosis cause classes.
	 *
	 * @since 0.1.5
	 *
	 * @return string[]
	 */
	public static function validCauses(): array {
		return array( 'ok', 'valid-no-credits', 'throttled', 'bad-key', 'network-failure', 'server-error', 'unconfigured', 'unknown' );
	}

	/**
	 * Shared cached probe backing isConfigured() and diagnose().
	 *
	 * @since 0.1.5
	 *
	 * @throws \RuntimeException When SDK entry points are unavailable.
	 * @return array{configured: bool, cause: string}
	 */
	private function probe(): array {
		try {
			$this->getRequestAuthentication();
		} catch ( \Throwable $e ) {
			unset( $e );
			return array(
				'configured' => false,
				'cause'      => 'unconfigured',
			);
		}

		$tkey     = 'opencode_connector_avail_' . $this->catalog;
		$causekey = $tkey . '_cause';
		if ( function_exists( 'get_transient' ) ) {
			try {
				$cached = get_transient( $tkey );
				if ( false !== $cached ) {
					$cause = get_transient( $causekey );
					if ( ! is_string( $cause ) || ! in_array( $cause, self::validCauses(), true ) ) {
						$cause = (bool) $cached ? 'ok' : 'unknown';
					}
					return array(
						'configured' => (bool) $cached,
						'cause'      => $cause,
					);
				}
				if ( false !== get_transient( $tkey . '_lock' ) ) {
					return array(
						'configured' => false,
						'cause'      => 'unknown',
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		// Stampede protection: short lock so concurrent requests share one probe.
		$lock_key  = $tkey . '_lock';
		$have_lock = false;
		if ( function_exists( 'set_transient' ) ) {
			try {
				set_transient( $lock_key, 1, 10 );
				$have_lock = true;
			} catch ( \Throwable $e ) {
				unset( $e );
				$have_lock = false;
			}
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
		// SDK entry points are probed defensively so older SDK shapes fail
		// open to network-failure instead of fataling.
		if ( ! class_exists( SessionHeader::class ) || ! method_exists( SessionHeader::class, 'inject_into_headers' ) ) {
			$this->storeProbeResult( $tkey, $causekey, $lock_key, $have_lock, false, 'network-failure' );
			return array(
				'configured' => false,
				'cause'      => 'network-failure',
			);
		}
		if ( ! class_exists( Request::class ) || ! class_exists( HttpMethodEnum::class ) || ! class_exists( $cls ) || ! method_exists( $cls, 'url' ) ) {
			$this->storeProbeResult( $tkey, $causekey, $lock_key, $have_lock, false, 'network-failure' );
			return array(
				'configured' => false,
				'cause'      => 'network-failure',
			);
		}
		// NOTE: HttpMethodEnum::POST() may be a magic __callStatic factory
		// (like ProviderTypeEnum::cloud()), which method_exists() cannot see,
		// so it is invoked inside try/catch instead of being probed.
		try {
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
		} catch ( \Throwable $e ) {
			unset( $e );
			$this->storeProbeResult( $tkey, $causekey, $lock_key, $have_lock, false, 'network-failure' );
			return array(
				'configured' => false,
				'cause'      => 'network-failure',
			);
		}
		try {
			$auth = $this->getRequestAuthentication();
			if ( ! is_object( $auth ) || ! method_exists( $auth, 'authenticateRequest' ) ) {
				throw new \RuntimeException( 'Request authentication unavailable.' );
			}
			$transport = $this->getHttpTransporter();
			if ( ! is_object( $transport ) || ! method_exists( $transport, 'send' ) ) {
				throw new \RuntimeException( 'HTTP transporter unavailable.' );
			}
			$req = $auth->authenticateRequest( $req );
			$res = $transport->send( $req );
			if ( ! is_object( $res ) || ! method_exists( $res, 'getStatusCode' ) ) {
				throw new \RuntimeException( 'Unexpected probe response shape.' );
			}
			$code = (int) $res->getStatusCode();
			if ( $code >= 200 && $code < 300 ) {
				$ok    = true;
				$cause = 'ok';
			} elseif ( 429 === $code ) {
				$ok    = true;
				$cause = 'throttled';
			} elseif ( 401 === $code ) {
				$data     = method_exists( $res, 'getData' ) ? $res->getData() : null;
				$err_type = is_array( $data ) ? ( $data['error']['type'] ?? '' ) : '';
				if ( 'CreditsError' === $err_type ) {
					$ok    = true;
					$cause = 'valid-no-credits';
				} else {
					$ok    = false;
					$cause = 'bad-key';
				}
			} elseif ( 403 === $code ) {
				$ok    = false;
				$cause = 'bad-key';
			} elseif ( $code >= 500 && $code < 600 ) {
				$ok    = false;
				$cause = 'server-error';
			} else {
				$ok    = false;
				$cause = 'unknown';
			}
		} catch ( \Throwable $e ) {
			unset( $e );
			$ok    = false;
			$cause = 'network-failure';
		}
		$this->storeProbeResult( $tkey, $causekey, $lock_key, $have_lock, $ok, $cause );
		return array(
			'configured' => $ok,
			'cause'      => $cause,
		);
	}

	/**
	 * Persist the probe verdict plus its cause class.
	 *
	 * Shares one TTL between both transients so diagnose() never adds extra
	 * HTTP versus isConfigured(). Every WP call is guarded so the probe
	 * fails open outside a WP context (unit tests) instead of fataling.
	 *
	 * @since 0.1.5
	 *
	 * @param string $tkey      Availability transient key.
	 * @param string $causekey  Cause transient key.
	 * @param string $lock_key  Stampede-lock transient key.
	 * @param bool   $have_lock Whether this request set the lock.
	 * @param bool   $ok        Configured verdict.
	 * @param string $cause     Cause class (must be in validCauses()).
	 * @return void
	 */
	private function storeProbeResult( string $tkey, string $causekey, string $lock_key, bool $have_lock, bool $ok, string $cause ): void {
		if ( ! in_array( $cause, self::validCauses(), true ) ) {
			$cause = 'unknown';
		}
		$minute = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
		$jitter = 0;
		if ( function_exists( 'wp_rand' ) ) {
			try {
				$jitter = (int) wp_rand( -60, 60 );
			} catch ( \Throwable $e ) {
				unset( $e );
				$jitter = 0;
			}
		}
		// Stagger expiry ±60s to avoid synchronized stampedes.
		$ttl = 5 * $minute + $jitter;
		$ttl = max( 60, $ttl );
		try {
			if ( $have_lock && function_exists( 'delete_transient' ) ) {
				delete_transient( $lock_key );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		try {
			if ( function_exists( 'set_transient' ) ) {
				set_transient( $tkey, (int) $ok, $ttl );
				set_transient( $causekey, $cause, $ttl );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
