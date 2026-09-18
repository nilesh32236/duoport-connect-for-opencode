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
	 * Allowlisted IDs.
	 *
	 * @var array<string, list<string>>
	 */
	private const ALLOW = array(
		'go'  => array(
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
			'mimo-v2.5-pro',
			'mimo-v2.5',
			'mimo-v2-pro',
			'mimo-v2-omni',
			'hy3',
			'hy3-preview',
			'deepseek-flash',
			'deepseek-v4-flash-vision-exp',
			'deepseek-v4.1-flash',
			'glm-5.3-flash',
			'gpt-5.6-luna',
			'grok-4.5',
			'grok-4.6',
			'hy4-preview',
			'longcat-2.0',
			'minimax-m2.5',
			'minimax-m2.7',
			'minimax-m3',
			'muse-spark-1.2-contributor',
			'muse-spark-1.3-contributor',
			'omen-alpha',
			'qwen3.5-plus',
			'qwen3.6-plus',
			'qwen3.7-max',
			'qwen3.7-plus',
			'qwen3.8-flash',
			'qwen3.8-max',
			'union-alpha',
		),
		'zen' => array(
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
			'claude-fable-5',
			'claude-fable-5-1',
			'claude-haiku-4-5',
			'claude-opus-4-5',
			'claude-opus-4-6',
			'claude-opus-4-7',
			'claude-opus-4-8',
			'claude-opus-5',
			'claude-sonnet-4',
			'claude-sonnet-4-5',
			'claude-sonnet-4-6',
			'claude-sonnet-5',
			'deepseek-v4-flash-vision-exp',
			'gemini-3-flash',
			'gemini-3.1-pro',
			'gemini-3.5-flash',
			'gemini-3.5-flash-lite',
			'gemini-3.6-flash',
			'gemini-3.7-flash',
			'gemini-3.8-flash',
			'glm-5.3',
			'glm-5.3-flash',
			'gpt-5',
			'gpt-5-codex',
			'gpt-5-nano',
			'gpt-5.1',
			'gpt-5.1-codex',
			'gpt-5.1-codex-max',
			'gpt-5.1-codex-mini',
			'gpt-5.2',
			'gpt-5.2-codex',
			'gpt-5.3-codex',
			'gpt-5.3-codex-spark',
			'gpt-5.4',
			'gpt-5.4-mini',
			'gpt-5.4-nano',
			'gpt-5.4-pro',
			'gpt-5.5',
			'gpt-5.5-pro',
			'gpt-5.6-luna',
			'gpt-5.6-sol',
			'gpt-5.6-terra',
			'gpt-6-astra',
			'grok-4.5',
			'grok-4.6',
			'grok-build-0.1',
			'ling-3.0-flash-fin-free',
			'muse-spark-1.2',
			'muse-spark-1.2-contributor-free',
			'muse-spark-1.3',
			'muse-spark-1.3-contributor-free',
			'qwen3.5-plus',
			'qwen3.6-plus',
			'union-alpha',
		),
	);
	/**
	 * Free model IDs.
	 *
	 * @var list<string>
	 */
	private const FREE = array(
		'deepseek-v4-flash-free',
		'mimo-v2.5-free',
		'nemotron-3-ultra-free',
		'nemotron-3.5-lightning-free',
		'big-pickle',
		'ling-3.0-flash-fin-free',
		'muse-spark-1.2-contributor-free',
		'muse-spark-1.3-contributor-free',
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
	 * Whether a model is billed as free.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Model ID.
	 * @return bool
	 */
	public static function isFree( string $id ): bool {
		return in_array( $id, self::FREE, true );
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
