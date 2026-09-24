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
	 * Current curated endpoint family.
	 */
	private const ENDPOINT_FAMILY = 'chat';

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

		$capabilities = array(
			'text'       => true,
			'tools'      => ModelAllowlist::isToolCapable( $id, $catalog ),
			'web_search' => ModelAllowlist::isWebSearchCapable( $id, $catalog ),
			'image'      => ModelAllowlist::isImageCapable( $id, $catalog ),
		);

		return array(
			'id'                  => $id,
			'catalog'             => $catalog,
			'display_name'        => ModelAllowlist::displayName( $id ),
			'free'                => ModelAllowlist::isFree( $id ),
			'endpoint_family'     => self::ENDPOINT_FAMILY,
			'capabilities'        => $capabilities,
			'verification_status' => self::VERIFICATION_STATUS,
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
