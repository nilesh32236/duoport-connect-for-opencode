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
	 * Mirrors Catalog::providerId( Catalog::ZEN ); see OpenCodeGoProvider
	 * for why the constant form exists alongside providerId().
	 *
	 * @since 0.1.8
	 */
	const PROVIDER_ID = 'opencode-zen';

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
	 * the Go subclass.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function catalogKey(): string {
		return \OpenCodeConnector\Metadata\Catalog::ZEN;
	}
}
