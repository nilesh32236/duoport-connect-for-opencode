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
	 * Resolve the reviewed endpoint family for a model.
	 *
	 * @param string $id      Model ID.
	 * @param string $catalog Catalog slug.
	 * @return string
	 */
	private static function endpointFamily( string $id, string $catalog ): string {
		if ( 'zen' === $catalog && in_array( $id, self::UNSUPPORTED_ZEN_MODELS, true ) ) {
			return 'unsupported';
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
		$route_supported = 'unsupported' !== $endpoint_family;
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
			'verification_status' => 'unsupported' === $endpoint_family ? 'needs-adapter' : self::VERIFICATION_STATUS,
			'last_verified'       => self::LAST_VERIFIED,
		);
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
