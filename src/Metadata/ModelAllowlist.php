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
			'hy3-free',
			'nemotron-3-ultra-free',
			'nemotron-3.5-lightning-free',
			'laguna-s-2.1-free',
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
		'hy3-free',
		'nemotron-3-ultra-free',
		'nemotron-3.5-lightning-free',
		'laguna-s-2.1-free',
		'big-pickle',
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
