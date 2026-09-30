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
			'glm-5',
			'kimi-k3',
			'kimi-k2.7-code',
			'kimi-k2.6',
			'kimi-k2.5',
			'deepseek-v4-pro',
			'deepseek-v4-flash',
			'deepseek-v4.1-flash',
			'mimo-v2.5-pro',
			'mimo-v2.5',
			'mimo-v2-pro',
			'mimo-v2-omni',
			'hy3',
			'hy3-preview',
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
			'deepseek-v4-flash-free',
			'mimo-v2.5-free',
			'nemotron-3-ultra-free',
			'nemotron-3.5-lightning-free',
		),
	);
	/**
	 * Web-search-capable IDs per catalog.
	 *
	 * Intentionally empty (fail-open default): the OpenAI-compatible base
	 * model has no `webSearch` handling on `chat/completions` and the
	 * OpenCode gateway payload is unverified, so no model advertises this
	 * option until an end-to-end gateway probe passes. Add verified IDs here
	 * to advertise it; the metadata directory already consults the gate.
	 *
	 * @since 0.1.4
	 *
	 * @var array<string, list<string>>
	 */
	private const WEB_SEARCH_CAPABLE = array();
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
	 * Mirrors the ALLOW shape (per-catalog, never shared between Go and Zen)
	 * because billing is a per-catalog fact: a free-named ID served by the Go
	 * catalog says nothing about Zen pricing, and a flat list would label it
	 * `(Free)` and sort it to the top of the Go picker.
	 *
	 * Every ID below is confirmed Free in OpenCode's published Zen pricing
	 * table (https://opencode.ai/docs/zen/, accessed 2026-09-29). The live
	 * `/models` payload carries no free or pricing field, so that table is the
	 * only free evidence available; an ID that is not confirmed there stays a
	 * free-name candidate (Model Radar reports it) and is NOT listed here.
	 * `deepseek-v4-flash-free` is the current example: it is still served by
	 * `/models`, but it is absent from the current published tables, so it is
	 * no longer a reviewed free record.
	 *
	 * @since 0.1.0
	 * @since 0.1.8 Reshaped per catalog and re-sourced from the published
	 *              Zen pricing table.
	 *
	 * @var array<string, list<string>>
	 */
	private const FREE = array(
		Catalog::GO  => array(),
		Catalog::ZEN => array(
			'big-pickle',
			'mimo-v2.5-free',
			'nemotron-3-ultra-free',
			'nemotron-3.5-lightning-free',
		),
	);

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
	 * Per-catalog by design: the free label, the free-first sort order, and the
	 * free-model tool gate all read this value, so a cross-catalog lookup
	 * would mislabel a model and silently drop its function-calling support.
	 *
	 * @since 0.1.0
	 * @since 0.1.8 Added the required catalog argument.
	 *
	 * @param string $id      Model ID.
	 * @param string $catalog Catalog slug.
	 * @return bool
	 */
	public static function isFree( string $id, string $catalog ): bool {
		return in_array( $id, self::FREE[ $catalog ] ?? array(), true );
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
		if ( str_starts_with( $id, 'deepseek' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a model may advertise web-search support.
	 *
	 * Fail-open default: backed by the (currently empty) WEB_SEARCH_CAPABLE
	 * list. The OpenAI-compatible base model has no `webSearch` handling on
	 * `chat/completions` (unlike the Responses-API `web_search` tool), and
	 * the OpenCode gateway payload is unverified, so no model advertises this
	 * option until an end-to-end gateway probe passes. Enabling a verified
	 * model is a one-line change to the list above.
	 *
	 * @since 0.1.4
	 *
	 * @param string $id      Model ID.
	 * @param string $catalog Catalog slug.
	 * @return bool
	 */
	public static function isWebSearchCapable( string $id, string $catalog ): bool {
		return in_array( $id, self::WEB_SEARCH_CAPABLE[ $catalog ] ?? array(), true );
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
