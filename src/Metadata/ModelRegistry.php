<?php
/**
 * Curated capability and endpoint records for allowlisted models.
 *
 * @package OpenCodeConnector
 * @since 0.1.5
 */

declare(strict_types=1);

namespace OpenCodeConnector\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small canonical registry for the currently curated model surface.
 *
 * The registry is intentionally conservative: unknown IDs and capabilities
 * are denied, and live catalog discovery never creates records automatically.
 */
final class ModelRegistry {

	/**
	 * Verification status for the existing curated compatibility set.
	 */
	private const VERIFICATION_STATUS = 'legacy-verified';

	/**
	 * Date of the last reviewed compatibility mapping.
	 */
	private const LAST_VERIFIED = '2026-09-24';

	/**
	 * Current implemented endpoint family.
	 */
	private const ENDPOINT_FAMILY = 'chat';

	/**
	 * Zen IDs whose documented family is not implemented by this adapter.
	 *
	 * @var list<string>
	 */
	private const UNSUPPORTED_ZEN_MODELS = array(
		'minimax-m3',
		'minimax-m2.7',
		'minimax-m2.5',
	);

	/**
	 * Known non-chat endpoint families with no verified transport.
	 *
	 * This is the single source of truth for *named* non-chat families, so a
	 * denial can say which family was rejected instead of leaking a sentinel.
	 * It is not the enforcement point: `EndpointRoute::PATHS` is a
	 * chat-only allowlist and remains the gate that fails every unknown or
	 * unlisted family closed. Adding a family here improves the diagnostic;
	 * omitting one still fails closed.
	 *
	 * @var list<string>
	 */
	private const UNSUPPORTED_FAMILIES = array(
		'responses',
		'messages',
		'systemone',
		'provider-specific',
	);

	/**
	 * Sentinel endpoint family for allowlisted models whose documented family
	 * is not implemented by this adapter.
	 *
	 * @var string
	 */
	public const ENDPOINT_FAMILY_UNSUPPORTED = 'unsupported';

	/**
	 * Whether an endpoint family is a known, named non-chat family.
	 *
	 * @param string $family Endpoint family name.
	 * @return bool
	 */
	public static function isUnsupportedFamily( string $family ): bool {
		return in_array( $family, self::UNSUPPORTED_FAMILIES, true );
	}

	/**
	 * Resolve the reviewed endpoint family for a model.
	 *
	 * @param string $id      Model ID.
	 * @param string $catalog Catalog slug.
	 * @return string
	 */
	private static function endpointFamily( string $id, string $catalog ): string {
		if ( 'zen' === $catalog && in_array( $id, self::UNSUPPORTED_ZEN_MODELS, true ) ) {
			return self::ENDPOINT_FAMILY_UNSUPPORTED;
		}
		return self::ENDPOINT_FAMILY;
	}

	/**
	 * Get a canonical record for an allowlisted model.
	 *
	 * @param string $id      Model ID.
	 * @param string $catalog Catalog slug.
	 * @return array<string, mixed>|null
	 */
	public static function record( string $id, string $catalog ): ?array {
		if ( ! ModelAllowlist::isAllowed( $id, $catalog ) ) {
			return null;
		}

		$endpoint_family = self::endpointFamily( $id, $catalog );
		$route_supported = self::ENDPOINT_FAMILY_UNSUPPORTED !== $endpoint_family;
		$capabilities    = array(
			'text'       => $route_supported,
			'tools'      => $route_supported && ModelAllowlist::isToolCapable( $id, $catalog ),
			'web_search' => $route_supported && ModelAllowlist::isWebSearchCapable( $id, $catalog ),
			'image'      => $route_supported && ModelAllowlist::isImageCapable( $id, $catalog ),
		);

		return array(
			'id'                  => $id,
			'catalog'             => $catalog,
			'display_name'        => ModelAllowlist::displayName( $id ),
			'free'                => ModelAllowlist::isFree( $id ),
			'endpoint_family'     => $endpoint_family,
			'capabilities'        => $capabilities,
			'verification_status' => self::ENDPOINT_FAMILY_UNSUPPORTED === $endpoint_family ? 'needs-adapter' : self::VERIFICATION_STATUS,
			'last_verified'       => self::LAST_VERIFIED,
		);
	}

	/**
	 * Get the second probe candidate for a catalog.
	 *
	 * The availability probe retries with this model when the primary probe model
	 * is refused model-side, so that a RETIRED model cannot present itself as a
	 * credential failure. It is resolved from the registry rather than hardcoded,
	 * so it is always an allowlisted, endpoint-reviewed model of that catalog.
	 *
	 * A free model is never returned: the primary probe model is deliberately
	 * paid, because a paid model discriminates authentication (401
	 * CreditsError for a valid key with an empty balance) while a free model
	 * fails closed whenever it is transiently unavailable upstream.
	 *
	 * Nullable on purpose: a catalog whose only reviewed models are free has no
	 * paid alternative, and the caller must degrade to a single probe rather than
	 * fatal. It cannot be non-nullable, because returning null from a `string`
	 * return type is a TypeError that would escape probe() into isConfigured(),
	 * whose production caller catches it and reports not configured — turning a
	 * missing retry candidate back into the fail-closed disconnect this fallback
	 * exists to avoid.
	 *
	 * @param string $catalog Catalog slug.
	 * @param string $exclude Model id to skip (the primary probe model).
	 * @return string|null Model id, or null when the catalog has no paid alternative.
	 */
	public static function fallbackProbeModel( string $catalog, string $exclude ): ?string {
		foreach ( self::records( $catalog ) as $record ) {
			$id = (string) ( $record['id'] ?? '' );
			if ( '' === $id || $id === $exclude ) {
				continue;
			}
			if ( true === ( $record['free'] ?? false ) ) {
				continue;
			}
			if ( self::VERIFICATION_STATUS !== ( $record['verification_status'] ?? '' ) ) {
				continue;
			}
			return $id;
		}
		return null;
	}

	/**
	 * Get all canonical records for a catalog.
	 *
	 * @param string $catalog Catalog slug.
	 * @return list<array<string, mixed>>
	 */
	public static function records( string $catalog ): array {
		$records = array();
		foreach ( ModelAllowlist::allowedIds( $catalog ) as $id ) {
			$record = self::record( $id, $catalog );
			if ( null !== $record ) {
				$records[] = $record;
			}
		}
		return $records;
	}

	/**
	 * Whether a capability is explicitly verified for a model.
	 *
	 * @param string $id         Model ID.
	 * @param string $catalog    Catalog slug.
	 * @param string $capability Capability key.
	 * @return bool
	 */
	public static function supports( string $id, string $catalog, string $capability ): bool {
		$record = self::record( $id, $catalog );
		return null !== $record && true === ( $record['capabilities'][ $capability ] ?? false );
	}
}
