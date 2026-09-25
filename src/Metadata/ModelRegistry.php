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
	 *
	 * @since 0.1.6
	 */
	public const LEGACY_VERIFIED_STATUS = 'legacy-verified';

	/**
	 * Verification status for a fully verified record.
	 *
	 * @since 0.1.6
	 */
	public const VERIFIED_STATUS = 'verified';

	/**
	 * Verification states accepted as reviewed support.
	 *
	 * Single source of truth for every gate (fallback selection, radar
	 * support, watch diffing, metadata listing).
	 *
	 * @since 0.1.6
	 *
	 * @var list<string>
	 */
	public const VERIFIED_STATUSES = array( 'legacy-verified', 'verified' );

	/**
	 * Verification status for records whose endpoint is not implemented.
	 *
	 * @since 0.1.6
	 */
	public const NEEDS_ADAPTER_STATUS = 'needs-adapter';

	/**
	 * Current implemented endpoint family.
	 *
	 * @since 0.1.6
	 */
	public const IMPLEMENTED_ENDPOINT = 'chat';

	/**
	 * Endpoint family for records no transport implements.
	 *
	 * @since 0.1.6
	 */
	public const UNSUPPORTED_FAMILY = 'unsupported';

	/**
	 * Verification status for the existing curated compatibility set.
	 *
	 * Kept as the canonical value behind LEGACY_VERIFIED_STATUS.
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
		if ( Catalog::ZEN === $catalog && in_array( $id, self::UNSUPPORTED_ZEN_MODELS, true ) ) {
			return self::UNSUPPORTED_FAMILY;
		}
		return self::IMPLEMENTED_ENDPOINT;
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
		$route_supported = self::UNSUPPORTED_FAMILY !== $endpoint_family;
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
			'verification_status' => self::UNSUPPORTED_FAMILY === $endpoint_family ? self::NEEDS_ADAPTER_STATUS : self::VERIFICATION_STATUS,
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

	/**
	 * Whether a verification status counts as reviewed support.
	 *
	 * @since 0.1.6
	 *
	 * @param string $status Verification status value.
	 * @return bool
	 */
	public static function isVerifiedStatus( string $status ): bool {
		return in_array( $status, self::VERIFIED_STATUSES, true );
	}

	/**
	 * Whether a reviewed record has a verified status.
	 *
	 * @since 0.1.6
	 *
	 * @param array<string, mixed> $record Registry record.
	 * @return bool
	 */
	public static function isVerified( array $record ): bool {
		return self::isVerifiedStatus( (string) ( $record['verification_status'] ?? '' ) );
	}

	/**
	 * Whether a reviewed record has a supported endpoint and verification.
	 *
	 * @since 0.1.6
	 *
	 * @param array<string, mixed>|null $record Registry record.
	 * @return bool
	 */
	public static function isSupported( ?array $record ): bool {
		return null !== $record
			&& self::UNSUPPORTED_FAMILY !== ( $record['endpoint_family'] ?? '' )
			&& self::isVerified( $record );
	}

	/**
	 * Whether a model reliably supports structured output.
	 *
	 * Single home of the DeepSeek vendor quirk: DeepSeek models return
	 * malformed strict JSON, so neither tool-call arguments nor output
	 * schemas can be trusted for them.
	 *
	 * @since 0.1.6
	 *
	 * @param string $id Model ID.
	 * @return bool
	 */
	public static function supportsStructuredOutput( string $id ): bool {
		return ! str_starts_with( $id, 'deepseek' );
	}
}
