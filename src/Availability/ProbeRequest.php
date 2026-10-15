<?php
/**
 * Shared probe request construction.
 *
 * Payload half of the OpenCodeProviderAvailability split: the one-token
 * probe payload, the catalog-appropriate headers, and the SDK-surface
 * probe live here so the availability probe and the verification probe
 * cannot drift apart when one of them is edited.
 *
 * @package OpenCodeConnector
 * @since 0.1.9
 */

declare(strict_types=1);

namespace OpenCodeConnector\Availability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Http\GoRequestHeaders;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

/**
 * Builds the HTTP request both probes send.
 *
 * @package OpenCodeConnector
 * @since 0.1.9
 */
final class ProbeRequest {
	/**
	 * Build the one-token probe payload both probes send.
	 *
	 * A single owner for the model/messages/max_tokens shape so the
	 * verification probe and the availability probe cannot drift apart when
	 * one of them is edited.
	 *
	 * @since 0.1.9
	 *
	 * @param string $probe_model Probe model slug.
	 * @return array<string, mixed>
	 */
	public static function payload( string $probe_model ): array {
		return array(
			'model'      => $probe_model,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => 'ping',
				),
			),
			'max_tokens' => 1,
		);
	}

	/**
	 * Build the headers both probes send.
	 *
	 * The Go catalog rejects requests without x-opencode-session, so Go
	 * probes carry the stable ping-derived session plus the plugin
	 * User-Agent via the shared Go header pair. Zen probes carry the
	 * session-only headers. The catalog-branch choice lives in
	 * GoRequestHeaders::forCatalog(); this stays a thin pass-through so
	 * the branch has exactly one home.
	 *
	 * @since 0.1.9
	 *
	 * @param string               $catalog Catalog slug.
	 * @param array<string, mixed> $data    Probe payload the session is derived from.
	 * @return array<string, string>
	 */
	public static function headers( string $catalog, array $data ): array {
		$base_headers = array( 'Content-Type' => 'application/json' );
		try {
			if ( class_exists( GoRequestHeaders::class ) && method_exists( GoRequestHeaders::class, 'forCatalog' ) ) {
				return GoRequestHeaders::forCatalog( $catalog, $base_headers, $data );
			}
		} catch ( \Throwable $catalog_header_exception ) {
			unset( $catalog_header_exception );
		}
		return $base_headers;
	}

	/**
	 * Whether the SDK surface a probe needs is available.
	 *
	 * HttpMethodEnum::POST() is a magic factory: the SDK declares it as an
	 * `@method static` annotation and serves it from AbstractEnum::__callStatic,
	 * so method_exists() cannot see it and is permanently false against the
	 * shipped SDK. Probe the backing constant instead, the same rule
	 * AbstractOpenCodeProvider::createProviderMetadata() applies to
	 * ProviderTypeEnum and RequestAuthenticationMethod. `url` is a real
	 * static method on AbstractApiProvider, so method_exists() is correct
	 * for it.
	 *
	 * Shared by verify() and probe() so both probes agree on what "the SDK
	 * is present" means.
	 *
	 * @since 0.1.9
	 *
	 * @param string $cls Provider class FQCN.
	 * @return bool
	 */
	public static function surfaceAvailable( string $cls ): bool {
		if ( '' === $cls ) {
			return false;
		}
		return class_exists( $cls ) && method_exists( $cls, 'url' )
			&& class_exists( Request::class ) && class_exists( HttpMethodEnum::class ) && defined( HttpMethodEnum::class . '::POST' );
	}
}
