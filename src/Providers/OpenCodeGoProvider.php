<?php
/**
 * OpenCode Go provider.
 *
 * OpenCode Go provider for subscription catalog.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

namespace OpenCodeConnector\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenCode Go provider (subscription catalog).
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */
final class OpenCodeGoProvider extends AbstractOpenCodeProvider {
	/**
	 * Provider ID.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function providerId(): string {
		return 'opencode-go';
	}

	/**
	 * Catalog key.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function catalogKey(): string {
		return 'go';
	}

	/**
	 * Display name.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function displayName(): string {
		return 'OpenCode Go';
	}

	/**
	 * Description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function description(): string {
		return __( 'Text generation with OpenCode Go.', 'duoport-connect-for-opencode' );
	}

	/**
	 * Base URL.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function baseUrl(): string {
		return 'https://opencode.ai/zen/go/v1';
	}
}
