<?php
/**
 * OpenCode Zen provider.
 *
 * OpenCode Zen provider for pay-as-you-go catalog.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

namespace OpenCodeConnector\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Metadata\Catalog;

/**
 * OpenCode Zen provider (pay-as-you-go catalog including free models).
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */
final class OpenCodeZenProvider extends AbstractOpenCodeProvider {
	/**
	 * Provider ID.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function providerId(): string {
		return 'opencode-zen';
	}

	/**
	 * Catalog key.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function catalogKey(): string {
		return Catalog::ZEN;
	}

	/**
	 * Display name.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function displayName(): string {
		return __( 'OpenCode Zen', 'duoport-connect-for-opencode' );
	}

	/**
	 * Description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function description(): string {
		return __( 'Text generation with OpenCode Zen, including free models.', 'duoport-connect-for-opencode' );
	}

	/**
	 * Base URL.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function baseUrl(): string {
		return Catalog::baseUrl( Catalog::ZEN );
	}
}
