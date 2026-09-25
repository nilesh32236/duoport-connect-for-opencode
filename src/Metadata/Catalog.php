<?php
/**
 * Canonical catalog slugs.
 *
 * Single source of truth for the Go/Zen catalog keys so a third catalog
 * means one edit here instead of coordinated edits across Metadata,
 * Availability, Providers, and Models.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */

declare(strict_types=1);

namespace OpenCodeConnector\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical catalog slugs.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */
final class Catalog {

	/**
	 * Subscription catalog slug.
	 *
	 * @since 0.1.6
	 */
	public const GO = 'go';

	/**
	 * Pay-as-you-go catalog slug.
	 *
	 * @since 0.1.6
	 */
	public const ZEN = 'zen';

	/**
	 * All known catalog slugs.
	 *
	 * @since 0.1.6
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::GO, self::ZEN );
	}

	/**
	 * Whether a slug is a known catalog.
	 *
	 * @since 0.1.6
	 *
	 * @param string $catalog Catalog slug.
	 * @return bool
	 */
	public static function is_valid( string $catalog ): bool {
		return in_array( $catalog, self::all(), true );
	}
}
