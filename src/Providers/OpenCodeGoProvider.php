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

use OpenCodeConnector\Metadata\Catalog;

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
	 */
	public const PROVIDER_ID = 'opencode-go';

	/**
	 * Provider ID.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function providerId(): string {
		return self::PROVIDER_ID;
	}

	/**
	 * Catalog key.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function catalogKey(): string {
		return Catalog::GO;
	}

	/**
	 * Display name.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function displayName(): string {
		if ( function_exists( '__' ) ) {
			return __( 'OpenCode Go', 'duoport-connect-for-opencode' );
		}
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
		if ( function_exists( '__' ) ) {
			return __( 'Text generation with OpenCode Go.', 'duoport-connect-for-opencode' );
		}
		return 'Text generation with OpenCode Go.';
	}

	/**
	 * Base URL.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function baseUrl(): string {
		return Endpoints::base( Catalog::GO );
	}
}
