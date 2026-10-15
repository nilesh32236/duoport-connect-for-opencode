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
	 * Mirrors Catalog::providerId( Catalog::GO ); the constant form lets
	 * non-SDK callers (and the ID-agreement ratchet) resolve the ID without
	 * concatenating the slug.
	 *
	 * @since 0.1.8
	 */
	const PROVIDER_ID = 'opencode-go';

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
	 * The only binding this subclass owns: display name, description, and
	 * base URL all resolve from this key in the abstract base, so a
	 * display or endpoint change lands there once instead of here and in
	 * the Zen subclass.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function catalogKey(): string {
		return \OpenCodeConnector\Metadata\Catalog::GO;
	}
}
