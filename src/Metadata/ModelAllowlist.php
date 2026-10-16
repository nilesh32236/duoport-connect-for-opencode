<?php
/**
 * Allowlisted model IDs per catalog.
 *
 * Provides allowlisted model IDs per catalog and free-model labeling.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

namespace OpenCodeConnector\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allowlisted model IDs per catalog and free-model labeling.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */
final class ModelAllowlist {
	/**
	 * Curated same-catalog tool fallback for the Go catalog.
	 *
	 * Shared with OpenCodeGoTextGenerationModel::fallback_model_ids(); the
	 * ID must stay allowlisted here or CapabilityAwareFallback returns
	 * exhausted for tool requests.
	 *
	 * @since 0.1.6
	 */
	const GO_TOOL_FALLBACK = 'glm-5.3';

	/**
	 * Curated same-catalog tool fallback for the Zen catalog.
	 *
	 * Shared with OpenCodeZenTextGenerationModel::fallback_model_ids().
	 *
	 * @since 0.1.6
	 */
	const ZEN_TOOL_FALLBACK = 'glm-5.2';

	/**
	 * Allowlisted IDs.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, list<string>>
	 */
	private const ALLOW = array(
		Catalog::GO  => array(
			'glm-5.3',
			'glm-5.2',
			'glm-5.1',
			'kimi-k3',
			'kimi-k2.7-code',
			'kimi-k2.6',
			'deepseek-v4-pro',
			'deepseek-v4-flash',
			'deepseek-v4.1-flash',
			'mimo-v2.5-pro',
			'mimo-v2.5',
			'hy3',
		),
		Catalog::ZEN => array(
			'deepseek-v4-pro',
			'deepseek-v4-flash',
			'minimax-m3',
			'minimax-m2.7',
			'minimax-m2.5',
			'glm-5.2',
			'glm-5.1',
			'glm-5',
			'kimi-k3',
			'kimi-k2.7-code',
			'kimi-k2.6',
			'kimi-k2.5',
			'big-pickle',
			'nemotron-3.5-lightning-free',
		),
	);
	/**
	 * Web-search-capable IDs per catalog.
	 *
	 * Default-deny, fail-closed: mirrors the ALLOW shape (per-catalog, never
	 * shared between Go and Zen) and ships empty. Only IDs whose grounding
	 * contract is verified by an end-to-end gateway probe may be listed
	 * here. The OpenAI-compatible base model has no `webSearch` handling on
	 * `chat/completions` and the OpenCode gateway payload is unverified, so
	 * every unverified model is explicitly unsupported and never advertises
	 * this option — a mis-declared model must degrade to text-only rather
	 * than hallucinate grounding. Add verified IDs here to advertise it;
	 * the metadata directory already consults the gate.
	 *
	 * @since 0.1.4
	 * @since 0.1.9 Per-catalog default-deny shape; was a flat list.
	 *
	 * @var array<string, list<string>>
	 */
	private const WEB_SEARCH_CAPABLE = array(
		Catalog::GO  => array(),
		Catalog::ZEN => array(),
	);
	/**
	 * Image-capable IDs per catalog.
	 *
	 * Mirrors the ALLOW shape (per-catalog, never shared between Go and Zen).
	 * Only IDs verified as image-capable against the live
	 * `https://opencode.ai/zen/{go,}v1/models` catalogs may be listed here.
	 * Ships empty: with no image-capable IDs allowlisted the image path stays
	 * inert and text generation is unaffected (fail-open).
	 *
	 * @since 0.1.4
	 *
	 * @var array<string, list<string>>
	 */
	private const IMAGE = array(
		Catalog::GO  => array(),
		Catalog::ZEN => array(),
	);

	/**
	 * Free model IDs per catalog.
	 *
	 * Mirrors the ALLOW shape (per-catalog, never shared between Go and Zen):
	 * a free-named ID in one catalog must never label or re-sort the other
	 * catalog's picker. All reviewed free records currently live in Zen.
	 *
	 * `big-pickle` carries no `-free` suffix but is billed Free for input,
	 * output, and cached reads in OpenCode's published Zen pricing table
	 * (https://opencode.ai/docs/zen/#pricing: "Big Pickle is a stealth model
	 * that's free on OpenCode for a limited time"), so it keeps the reviewed
	 * free label. Retired free IDs (`deepseek-v4-flash-free`,
	 * `mimo-v2.5-free`, `nemotron-3-ultra-free`, absent from the live Zen
	 * `/models` catalog) were removed rather than kept as dead baseline rows.
	 *
	 * @since 0.1.0
	 * @since 0.1.9 Per-catalog shape; was a flat list.
	 *
	 * @var array<string, list<string>>
	 */
	private const FREE = array(
		Catalog::GO  => array(),
		Catalog::ZEN => array(
			'nemotron-3.5-lightning-free',
			'big-pickle',
		),
	);

	/**
	 * Test-only web-search allowlist override (null means use the const).
	 *
	 * Lets specs cover both sides of the default-deny gate — a verified
	 * model advertising web_search and an unverified model denied — without
	 * a live gateway probe. Production never sets this; it stays null there,
	 * so runtime behaviour is exactly the shipped const. Credential-blind:
	 * the map carries model IDs only, never credential option values.
	 *
	 * @since 0.1.9
	 *
	 * @var array<string, list<string>>|null
	 */
	private static $web_search_override = null;

	/**
	 * Whether a model is allowlisted.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id      Model ID.
	 * @param string $catalog Catalog slug.
	 * @return bool
	 */
	public static function isAllowed( string $id, string $catalog ): bool {
		return in_array( $id, self::ALLOW[ $catalog ] ?? array(), true );
	}

	/**
	 * Return the curated IDs for a catalog.
	 *
	 * @since 0.1.5
	 *
	 * @param string $catalog Catalog slug.
	 * @return list<string>
	 */
	public static function allowedIds( string $catalog ): array {
		return self::ALLOW[ $catalog ] ?? array();
	}

	/**
	 * Whether a model is allowlisted as image-capable.
	 *
	 * Image capability is advertised only for IDs in this set; the text
	 * allowlist is unaffected.
	 *
	 * @since 0.1.4
	 *
	 * @param string $id      Model ID.
	 * @param string $catalog Catalog slug.
	 * @return bool
	 */
	public static function isImageCapable( string $id, string $catalog ): bool {
		return in_array( $id, self::IMAGE[ $catalog ] ?? array(), true );
	}

	/**
	 * Whether a model is billed as free in a catalog.
	 *
	 * Per-catalog by design: the Go catalog already serves free-named IDs
	 * (e.g. longcat-2.5-preview-free) that are NOT reviewed here, so a flat
	 * lookup would mislabel the other catalog's picker on the first name
	 * collision.
	 * An empty or unknown catalog falls back to the flat union so legacy and
	 * catalog-blind callers fail open rather than fatal.
	 *
	 * @since 0.1.0
	 * @since 0.1.9 Added the catalog parameter; was a flat-list lookup.
	 *
	 * @param string $id      Model ID.
	 * @param string $catalog Catalog slug.
	 * @return bool
	 */
	public static function isFree( string $id, string $catalog = '' ): bool {
		if ( '' !== $catalog && array_key_exists( $catalog, self::FREE ) ) {
			return in_array( $id, self::FREE[ $catalog ], true );
		}
		foreach ( self::FREE as $ids ) {
			if ( in_array( $id, $ids, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a model can be trusted with strict JSON schema output.
	 *
	 * Single home for the DeepSeek exclusion the tool-capability gate and
	 * the metadata outputSchema gate each derived inline via the same
	 * prefix: DeepSeek models already return malformed strict JSON, so
	 * neither tool-call arguments nor structured output can be trusted.
	 * A change to the exclusion set lands here once.
	 *
	 * @since 0.1.8
	 *
	 * @param string $id Model ID.
	 * @return bool False for excluded families, true otherwise.
	 */
	public static function isStrictSchemaCapable( string $id ): bool {
		return ! str_starts_with( $id, 'deepseek' );
	}

	/**
	 * Whether a model may advertise function-calling (tools) support.
	 *
	 * Capability gate keeping spend and injection surface bounded: the model
	 * must be allowlisted for the catalog, must not be billed as free, and
	 * must not be a DeepSeek model (which already returns malformed strict
	 * JSON, so tool-call arguments cannot be trusted either). Transport is
	 * inherited from the OpenAI-compatible base model, which already maps
	 * function declarations to `tools: [{type: function, ...}]` and parses
	 * `tool_calls` responses.
	 *
	 * @since 0.1.4
	 *
	 * @param string $id      Model ID.
	 * @param string $catalog Catalog slug.
	 * @return bool
	 */
	public static function isToolCapable( string $id, string $catalog ): bool {
		if ( ! self::isAllowed( $id, $catalog ) ) {
			return false;
		}
		if ( self::isFree( $id, $catalog ) ) {
			return false;
		}
		if ( ! self::isStrictSchemaCapable( $id ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a model may advertise web-search support.
	 *
	 * Default-deny, fail-closed: backed by the (currently empty-per-catalog)
	 * WEB_SEARCH_CAPABLE list. Only models whose grounding contract is
	 * verified by an end-to-end gateway probe are listed; every unverified
	 * model — including unknown catalogs and IDs absent from the list — is
	 * explicitly unsupported and denied, so grounded generation can never be
	 * attempted on a mis-declared model. The OpenAI-compatible base model
	 * has no `webSearch` handling on `chat/completions` (unlike the
	 * Responses-API `web_search` tool), so enabling a verified model is a
	 * one-line change to the per-catalog list above.
	 *
	 * @since 0.1.4
	 * @since 0.1.9 Default-deny per-catalog lookup with a test-only override seam.
	 *
	 * @param string $id      Model ID.
	 * @param string $catalog Catalog slug.
	 * @return bool
	 */
	public static function isWebSearchCapable( string $id, string $catalog ): bool {
		$list = null !== self::$web_search_override ? self::$web_search_override : self::WEB_SEARCH_CAPABLE;
		return in_array( $id, $list[ $catalog ] ?? array(), true );
	}

	/**
	 * Inject a web-search allowlist for isolated tests.
	 *
	 * Pass null to restore the shipped const. Test-only; production callers
	 * must never call this.
	 *
	 * @since 0.1.9
	 *
	 * @param array<string, list<string>>|null $map Per-catalog IDs, or null to restore the const.
	 * @return void
	 */
	public static function setWebSearchCapableForTests( ?array $map ): void {
		self::$web_search_override = $map;
	}

	/**
	 * Human-readable display name for a model.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Model ID.
	 * @return string
	 */
	public static function displayName( string $id ): string {
		$human = ucwords( str_replace( array( '-', '_' ), ' ', $id ) );
		// e.g. "Deepseek V4 Flash Free" -> normalize V4 casing left as-is.
		return $human;
	}
}
