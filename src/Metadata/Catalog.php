<?php
/**
 * Catalog slugs and endpoints shared by Go and Zen.
 *
 * @package OpenCodeConnector
 * @since 0.1.7
 */

declare(strict_types=1);

namespace OpenCodeConnector\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for catalog slugs and base URLs.
 *
 * @package OpenCodeConnector
 * @since 0.1.7
 */
final class Catalog {
	/**
	 * Go catalog slug (subscription catalog).
	 *
	 * @since 0.1.7
	 */
	const GO = 'go';

	/**
	 * Zen catalog slug (pay-as-you-go catalog).
	 *
	 * @since 0.1.7
	 */
	const ZEN = 'zen';

	/**
	 * All known catalog slugs.
	 *
	 * @since 0.1.7
	 *
	 * @var list<string>
	 */
	const ALL = array( self::GO, self::ZEN );

	/**
	 * Go catalog base URL (without the trailing /models path).
	 *
	 * @since 0.1.7
	 */
	const GO_BASE_URL = 'https://opencode.ai/zen/go/v1';

	/**
	 * Zen catalog base URL (without the trailing /models path).
	 *
	 * @since 0.1.7
	 */
	const ZEN_BASE_URL = 'https://opencode.ai/zen/v1';

	/**
	 * Transient key prefix for availability probes and stampede locks.
	 *
	 * Dependency-free on purpose: Settings, the entry-file bust hook, and
	 * uninstall.php derive cache keys from here without loading any
	 * SDK-trait-dependent class.
	 *
	 * @since 0.1.7
	 */
	const AVAIL_PREFIX = 'opencode_connector_avail_';

	/**
	 * Base URLs per catalog (without the trailing /models path).
	 *
	 * @since 0.1.7
	 *
	 * @var array<string, string>
	 */
	private const BASE_URLS = array(
		self::GO  => self::GO_BASE_URL,
		self::ZEN => self::ZEN_BASE_URL,
	);

	/**
	 * Whether a slug is a known catalog.
	 *
	 * @since 0.1.7
	 *
	 * @param string $catalog Catalog slug.
	 * @return bool
	 */
	public static function isValid( string $catalog ): bool {
		return in_array( $catalog, self::ALL, true );
	}

	/**
	 * Base URL for a catalog.
	 *
	 * @since 0.1.7
	 *
	 * @param string $catalog Catalog slug.
	 * @return string Empty string for unknown catalogs (fail-open).
	 */
	public static function baseUrl( string $catalog ): string {
		return self::BASE_URLS[ $catalog ] ?? '';
	}

	/**
	 * Public /models discovery URL for a catalog.
	 *
	 * @since 0.1.7
	 *
	 * @param string $catalog Catalog slug.
	 * @return string Empty string for unknown catalogs.
	 */
	public static function modelsUrl( string $catalog ): string {
		$base = self::baseUrl( $catalog );
		return '' === $base ? '' : $base . '/models';
	}

	/**
	 * Availability transient keys for one catalog (result + stampede lock).
	 *
	 * @since 0.1.7
	 *
	 * @param string $catalog Catalog slug.
	 * @return list<string>
	 */
	public static function availabilityKeys( string $catalog ): array {
		$base = self::AVAIL_PREFIX . $catalog;
		return array( $base, $base . '_lock' );
	}

	/**
	 * All availability transient keys across catalogs.
	 *
	 * @since 0.1.7
	 *
	 * @return list<string>
	 */
	public static function allAvailabilityKeys(): array {
		$keys = array();
		foreach ( self::ALL as $catalog ) {
			foreach ( self::availabilityKeys( $catalog ) as $key ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}
}
