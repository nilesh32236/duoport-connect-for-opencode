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

use OpenCodeConnector\Transport\EndpointRoute;

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
	 * Verification states accepted as reviewed.
	 *
	 * Single owner for the "reviewed" vocabulary: CapabilityAwareFallback
	 * and ModelRadar both delegate here, so a status the registry can
	 * assign cannot be rejected by one of its consumers.
	 *
	 * @since 0.1.8
	 *
	 * @var list<string>
	 */
	public const VERIFIED_STATUSES = array( 'legacy-verified', 'verified' );

	/**
	 * Date of the last reviewed compatibility mapping.
	 */
	private const LAST_VERIFIED = '2026-09-24';

	/**
	 * Date the pending-verification set was last reviewed.
	 *
	 * These IDs are present in the live /models catalog but absent from
	 * OpenCode's published endpoint tables (Go docs and Zen pricing/endpoint
	 * tables, last updated 2026-09-29), so their chat/completions family is
	 * recorded as unverified rather than asserted. A successful
	 * chat/completions probe with that model moves it back to the reviewed
	 * set with a fresh date; until then it stays fail-closed.
	 *
	 * @since 0.1.9
	 */
	private const PENDING_LAST_REVIEWED = '2026-09-29';

	/**
	 * Verification status for allowlisted models whose endpoint family is
	 * not yet evidenced.
	 *
	 * Deliberately NOT a member of VERIFIED_STATUSES: ModelRadar and
	 * CapabilityAwareFallback both delegate to isVerified(), so this status
	 * keeps such models out of the picker, out of routing, and in the drift
	 * reports until a chat/completions probe passes.
	 *
	 * @since 0.1.9
	 */
	public const VERIFICATION_REQUIRED_STATUS = 'verification-required';

	/**
	 * Current implemented endpoint family.
	 *
	 * Derived from EndpointRoute, which owns the family-to-path map: the
	 * registry admits only what the router implements, so the two cannot
	 * drift apart.
	 *
	 * @var string
	 */
	private const ENDPOINT_FAMILY = EndpointRoute::IMPLEMENTED_FAMILY;

	/**
	 * Allowlisted (catalog, ID) pairs whose chat/completions family is
	 * recorded as unverified.
	 *
	 * Per-catalog, never shared between Go and Zen: the evidence gap is
	 * catalog-specific (the Go endpoint table omits the six Go IDs; the Zen
	 * pricing/endpoint tables omit deepseek-v4-flash-free), so demoting an
	 * ID in one catalog must not hide the same ID where it is reviewed.
	 * Every entry here resolves to ENDPOINT_FAMILY_UNSUPPORTED with
	 * VERIFICATION_REQUIRED_STATUS until a chat/completions request with
	 * that model returns 2xx.
	 *
	 * @since 0.1.9
	 *
	 * @var array<string, list<string>>
	 */
	private const PENDING_ENDPOINT_VERIFICATION = array(
		Catalog::GO  => array(
			'glm-5.1',
			'glm-5',
			'kimi-k2.5',
			'mimo-v2-pro',
			'mimo-v2-omni',
			'hy3-preview',
		),
		Catalog::ZEN => array(
			'deepseek-v4-flash-free',
		),
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
	 * Fail-closed: an allowlisted ID with no recorded chat/completions
	 * evidence (see PENDING_ENDPOINT_VERIFICATION) resolves to the
	 * unsupported sentinel instead of inheriting the implemented family.
	 * `/models` membership proves a model exists, not that it is served on
	 * chat/completions, so existence alone never routes.
	 *
	 * @param string $id      Model ID.
	 * @param string $catalog Catalog slug.
	 * @return string
	 */
	private static function endpointFamily( string $id, string $catalog ): string {
		if ( in_array( $id, self::PENDING_ENDPOINT_VERIFICATION[ $catalog ] ?? array(), true ) ) {
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
			'free'                => ModelAllowlist::isFree( $id, $catalog ),
			'endpoint_family'     => $endpoint_family,
			'capabilities'        => $capabilities,
			'verification_status' => self::ENDPOINT_FAMILY_UNSUPPORTED === $endpoint_family ? self::VERIFICATION_REQUIRED_STATUS : self::VERIFICATION_STATUS,
			'last_verified'       => self::ENDPOINT_FAMILY_UNSUPPORTED === $endpoint_family ? self::PENDING_LAST_REVIEWED : self::LAST_VERIFIED,
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
	 * Whether a registry record counts as reviewed.
	 *
	 * Single owner for the "reviewed" predicate behind VERIFIED_STATUSES.
	 *
	 * @since 0.1.8
	 *
	 * @param array<string, mixed> $record Registry record.
	 * @return bool
	 */
	public static function isVerified( array $record ): bool {
		return in_array( $record['verification_status'] ?? '', self::VERIFIED_STATUSES, true );
	}

	/**
	 * Whether a registry record still needs endpoint verification.
	 *
	 * Single owner for the "needs a human + a probe" predicate behind
	 * VERIFICATION_REQUIRED_STATUS. `needs-adapter` is retained here for
	 * readers of older records: the registry no longer assigns it, but a
	 * stale snapshot carrying it must still read as verification-required
	 * rather than silently counting as reviewed.
	 *
	 * @since 0.1.9
	 *
	 * @param array<string, mixed> $record Registry record.
	 * @return bool
	 */
	public static function needsVerification( array $record ): bool {
		return in_array( $record['verification_status'] ?? '', array( self::VERIFICATION_REQUIRED_STATUS, 'needs-adapter' ), true );
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
