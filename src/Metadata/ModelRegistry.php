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
 *
 * Each catalog carries its own endpoint-family evidence. An allowlisted ID
 * routes only when a family is recorded for that catalog, and membership in
 * the live `/models` catalog is never treated as that evidence: `/models`
 * carries no endpoint or pricing field, and OpenCode documents Responses,
 * Messages, and chat families side by side. Families that are recorded but
 * not re-confirmed stay routable without a verification date, and are reported
 * as verification-required instead of as reviewed support.
 */
final class ModelRegistry {

	/**
	 * Verification status for a record with a dated endpoint-family review.
	 */
	public const VERIFICATION_STATUS_REVIEWED = 'legacy-verified';

	/**
	 * Verification status for an allowlisted record whose endpoint family has
	 * not been re-confirmed against the current published table.
	 *
	 * Such a record stays routable (it is allowlisted and its family is the
	 * implemented one) but is NOT counted as reviewed support: the Model Radar
	 * and the catalog watch report it as verification-required so it is
	 * re-checked instead of inheriting someone else's verification date.
	 */
	public const VERIFICATION_STATUS_PENDING = 'verification-required';

	/**
	 * Verification status for a record whose documented family is not
	 * implemented by this adapter.
	 */
	public const VERIFICATION_STATUS_NEEDS_ADAPTER = 'needs-adapter';

	/**
	 * Statuses that count as reviewed support for a routed model.
	 *
	 * Single source of truth for the vocabulary so the registry, the Model
	 * Radar, and the fallback selector cannot drift on what "reviewed" means.
	 *
	 * @var list<string>
	 */
	public const REVIEWED_STATUSES = array( 'legacy-verified', 'verified' );

	/**
	 * Statuses whose chat route may be used.
	 *
	 * Wider than REVIEWED_STATUSES on purpose: a pending record is allowlisted
	 * and served on the implemented family, so hiding it would remove a
	 * working model from the picker and break generation for callers that
	 * already select it. It is reported as unreviewed instead.
	 *
	 * @var list<string>
	 */
	public const ROUTABLE_STATUSES = array( 'legacy-verified', 'verified', 'verification-required' );

	/**
	 * Date of the last reviewed compatibility mapping.
	 */
	private const LAST_VERIFIED = '2026-09-24';

	/**
	 * Current implemented endpoint family.
	 */
	private const ENDPOINT_FAMILY = 'chat';

	/**
	 * Reviewed endpoint family per allowlisted model ID, per catalog.
	 *
	 * Membership is the evidence: an ID appears only after its family was
	 * confirmed for that catalog (a chat/completions request that returned
	 * 2xx, or the current published endpoint table). Presence in the live
	 * `/models` catalog is deliberately NOT enough — that catalog carries no
	 * endpoint or pricing field, and OpenCode documents Responses, Messages,
	 * and chat families side by side. An allowlisted ID missing from both
	 * this map and PENDING_FAMILIES therefore fails closed instead of being
	 * assumed chat.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const ENDPOINT_FAMILIES = array(
		Catalog::GO  => array(
			'deepseek-v4-flash'   => self::ENDPOINT_FAMILY,
			'deepseek-v4-pro'     => self::ENDPOINT_FAMILY,
			'deepseek-v4.1-flash' => self::ENDPOINT_FAMILY,
			'glm-5.3'             => self::ENDPOINT_FAMILY,
			'glm-5.2'             => self::ENDPOINT_FAMILY,
			'kimi-k3'             => self::ENDPOINT_FAMILY,
			'kimi-k2.7-code'      => self::ENDPOINT_FAMILY,
			'kimi-k2.6'           => self::ENDPOINT_FAMILY,
			'mimo-v2.5-pro'       => self::ENDPOINT_FAMILY,
			'mimo-v2.5'           => self::ENDPOINT_FAMILY,
			'hy3'                 => self::ENDPOINT_FAMILY,
		),
		Catalog::ZEN => array(
			'deepseek-v4-pro'             => self::ENDPOINT_FAMILY,
			'deepseek-v4-flash'           => self::ENDPOINT_FAMILY,
			'minimax-m3'                  => self::ENDPOINT_FAMILY,
			'minimax-m2.7'                => self::ENDPOINT_FAMILY,
			'minimax-m2.5'                => self::ENDPOINT_FAMILY,
			'glm-5.2'                     => self::ENDPOINT_FAMILY,
			'glm-5.1'                     => self::ENDPOINT_FAMILY,
			'glm-5'                       => self::ENDPOINT_FAMILY,
			'kimi-k3'                     => self::ENDPOINT_FAMILY,
			'kimi-k2.7-code'              => self::ENDPOINT_FAMILY,
			'kimi-k2.6'                   => self::ENDPOINT_FAMILY,
			'kimi-k2.5'                   => self::ENDPOINT_FAMILY,
			'big-pickle'                  => self::ENDPOINT_FAMILY,
			'mimo-v2.5-free'              => self::ENDPOINT_FAMILY,
			'nemotron-3-ultra-free'       => self::ENDPOINT_FAMILY,
			'nemotron-3.5-lightning-free' => self::ENDPOINT_FAMILY,
		),
	);

	/**
	 * Allowlisted IDs per catalog whose family has not been re-confirmed.
	 *
	 * These stay routable (the implemented family is the only sane assumption
	 * for an allowlisted ID, and OpenCode's docs tables are not exhaustive)
	 * but they carry no individual verification date, so they are reported as
	 * verification-required rather than as reviewed support. Each one returns
	 * to ENDPOINT_FAMILIES with a dated 2xx chat/completions probe.
	 *
	 * @var array<string, list<string>>
	 */
	private const PENDING_FAMILIES = array(
		// Dropped from the current Go endpoint table (checked 2026-09-29):
		// present in the live catalog, not documented as any other family.
		Catalog::GO  => array(
			'glm-5.1',
			'glm-5',
			'kimi-k2.5',
			'mimo-v2-pro',
			'mimo-v2-omni',
			'hy3-preview',
		),
		// Absent from the current Zen endpoint and pricing tables (checked
		// 2026-09-29) even though `/models` still serves it.
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
	 * Resolve the recorded endpoint family for a model.
	 *
	 * Fail-closed: an allowlisted ID with no recorded family returns the
	 * unsupported sentinel instead of being assumed chat, so adding an ID to
	 * `ModelAllowlist::ALLOW` can never silently widen the transport surface.
	 *
	 * @param string $id      Model ID.
	 * @param string $catalog Catalog slug.
	 * @return string
	 */
	private static function endpointFamily( string $id, string $catalog ): string {
		$families = self::ENDPOINT_FAMILIES[ $catalog ] ?? array();
		if ( isset( $families[ $id ] ) ) {
			return (string) $families[ $id ];
		}
		if ( self::isPending( $id, $catalog ) ) {
			return self::ENDPOINT_FAMILY;
		}
		return self::ENDPOINT_FAMILY_UNSUPPORTED;
	}

	/**
	 * Whether an allowlisted model's family still needs an individual review.
	 *
	 * @param string $id      Model ID.
	 * @param string $catalog Catalog slug.
	 * @return bool
	 */
	public static function isPending( string $id, string $catalog ): bool {
		return in_array( $id, self::PENDING_FAMILIES[ $catalog ] ?? array(), true );
	}

	/**
	 * Whether a record is a reviewed, routable chat record.
	 *
	 * Shared gate for the Model Radar and the catalog watch so "reviewed
	 * support" means the same thing everywhere. A pending record routes today
	 * but is not counted as reviewed support.
	 *
	 * @param array<string, mixed>|null $record Registry record.
	 * @return bool
	 */
	public static function isReviewed( ?array $record ): bool {
		return null !== $record
			&& self::ENDPOINT_FAMILY_UNSUPPORTED !== ( $record['endpoint_family'] ?? '' )
			&& in_array( (string) ( $record['verification_status'] ?? '' ), self::REVIEWED_STATUSES, true );
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
		$pending         = $route_supported && self::isPending( $id, $catalog );
		$capabilities    = array(
			'text'       => $route_supported,
			'tools'      => $route_supported && ModelAllowlist::isToolCapable( $id, $catalog ),
			'web_search' => $route_supported && ModelAllowlist::isWebSearchCapable( $id, $catalog ),
			'image'      => $route_supported && ModelAllowlist::isImageCapable( $id, $catalog ),
		);

		if ( ! $route_supported ) {
			$verification_status = self::VERIFICATION_STATUS_NEEDS_ADAPTER;
		} elseif ( $pending ) {
			$verification_status = self::VERIFICATION_STATUS_PENDING;
		} else {
			$verification_status = self::VERIFICATION_STATUS_REVIEWED;
		}

		return array(
			'id'                  => $id,
			'catalog'             => $catalog,
			'display_name'        => ModelAllowlist::displayName( $id ),
			'free'                => ModelAllowlist::isFree( $id, $catalog ),
			'endpoint_family'     => $endpoint_family,
			'capabilities'        => $capabilities,
			'verification_status' => $verification_status,
			// Split per record: an unreviewed entry carries no date instead of
			// inheriting the reviewed set's date.
			'last_verified'       => self::VERIFICATION_STATUS_REVIEWED === $verification_status ? self::LAST_VERIFIED : '',
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
