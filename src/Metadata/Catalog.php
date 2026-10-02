<?php
/**
 * Catalog slugs and endpoints shared by Go and Zen.
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
 * Single source of truth for catalog slugs and base URLs.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */
final class Catalog {
	/**
	 * Go catalog slug (subscription catalog).
	 *
	 * @since 0.1.6
	 */
	const GO = 'go';

	/**
	 * Zen catalog slug (pay-as-you-go catalog).
	 *
	 * @since 0.1.6
	 */
	const ZEN = 'zen';

	/**
	 * All known catalog slugs.
	 *
	 * @since 0.1.6
	 *
	 * @var list<string>
	 */
	const ALL = array( self::GO, self::ZEN );

	/**
	 * Go catalog base URL (without the trailing /models path).
	 *
	 * @since 0.1.6
	 */
	const GO_BASE_URL = 'https://opencode.ai/zen/go/v1';

	/**
	 * Zen catalog base URL (without the trailing /models path).
	 *
	 * @since 0.1.6
	 */
	const ZEN_BASE_URL = 'https://opencode.ai/zen/v1';

	/**
	 * Transient key prefix for availability probes and stampede locks.
	 *
	 * Dependency-free on purpose: Settings, the entry-file bust hook, and
	 * uninstall.php derive cache keys from here without loading any
	 * SDK-trait-dependent class.
	 *
	 * @since 0.1.6
	 */
	const AVAIL_PREFIX = 'opencode_connector_avail_';

	/**
	 * Transient key prefix for the opt-in credential verification probe.
	 *
	 * Dependency-free for the same reason as AVAIL_PREFIX.
	 *
	 * @since 0.1.6
	 */
	const VERIFY_PREFIX = 'opencode_connector_verify_';

	/**
	 * Suffix for the transient-only last-known-good availability flag.
	 *
	 * Holds a literal boolean, never a `connectors_ai_*` option value, so a
	 * key rotation cannot leak through it.
	 *
	 * @since 0.1.6
	 */
	const LAST_GOOD_SUFFIX = '_last_good';

	/**
	 * Base URLs per catalog (without the trailing /models path).
	 *
	 * @since 0.1.6
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
	 * @since 0.1.6
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
	 * @since 0.1.6
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
	 * @since 0.1.6
	 *
	 * @param string $catalog Catalog slug.
	 * @return string Empty string for unknown catalogs.
	 */
	public static function modelsUrl( string $catalog ): string {
		$base = self::baseUrl( $catalog );
		return '' === $base ? '' : $base . '/models';
	}

	/**
	 * The probe result and its stampede lock, without the last-known-good flag.
	 *
	 * The split exists so no caller has to select list members by position to
	 * express "clear the stale verdict but keep the fallback". The last-known-good
	 * flag is a statement about the credential, and only an event that changes the
	 * credential may drop it — a display setting does not.
	 *
	 * @since 0.1.6
	 *
	 * @param string $catalog Catalog slug.
	 * @return list<string>
	 */
	public static function availabilityResultKeys( string $catalog ): array {
		$base = self::AVAIL_PREFIX . $catalog;
		return array( $base, $base . '_lock' );
	}

	/**
	 * Availability transient keys for one catalog.
	 *
	 * Covers the probe result, its stampede lock, and the transient-only
	 * last-known-good flag, so one call clears everything a key change must
	 * invalidate for this catalog.
	 *
	 * @since 0.1.6
	 *
	 * @param string $catalog Catalog slug.
	 * @return list<string>
	 */
	public static function availabilityKeys( string $catalog ): array {
		return array_merge(
			self::availabilityResultKeys( $catalog ),
			array( self::AVAIL_PREFIX . $catalog . self::LAST_GOOD_SUFFIX )
		);
	}

	/**
	 * Verification transient keys for one catalog (verdict + stampede lock).
	 *
	 * @since 0.1.6
	 *
	 * @param string $catalog Catalog slug.
	 * @return list<string>
	 */
	public static function verifyKeys( string $catalog ): array {
		$base = self::VERIFY_PREFIX . $catalog;
		return array( $base, $base . '_lock' );
	}

	/**
	 * Every transient this plugin owns for one catalog.
	 *
	 * @since 0.1.6
	 *
	 * @param string $catalog Catalog slug.
	 * @return list<string>
	 */
	public static function allKeys( string $catalog ): array {
		return array_merge( self::availabilityKeys( $catalog ), self::verifyKeys( $catalog ) );
	}

	/**
	 * All availability transient keys across catalogs.
	 *
	 * @since 0.1.6
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

	/**
	 * All verification transient keys across catalogs.
	 *
	 * @since 0.1.6
	 *
	 * @return list<string>
	 */
	public static function allVerifyKeys(): array {
		$keys = array();
		foreach ( self::ALL as $catalog ) {
			foreach ( self::verifyKeys( $catalog ) as $key ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * Every transient key this plugin owns, across catalogs and families.
	 *
	 * Settings bust hooks, the key-rotation hook, and uninstall.php derive
	 * their delete list from here, so adding a cached verdict can never
	 * require a synchronized multi-file edit.
	 *
	 * @since 0.1.6
	 *
	 * @return list<string>
	 */
	public static function allTransientKeys(): array {
		return array_merge( self::allAvailabilityKeys(), self::allVerifyKeys() );
	}
}
