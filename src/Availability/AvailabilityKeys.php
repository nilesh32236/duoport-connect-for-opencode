<?php
/**
 * Availability transient key helpers.
 *
 * Single source of truth for the probe cache and stampede-lock transient
 * keys shared by the availability probe and every cache-bust hook, so a
 * prefix rename or a third catalog cannot orphan cached probe results.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */

declare(strict_types=1);

namespace OpenCodeConnector\Availability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Metadata\Catalog;

/**
 * Availability transient key helpers.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */
final class AvailabilityKeys {

	/**
	 * Transient key prefix for cached probe results.
	 *
	 * @since 0.1.6
	 */
	public const PREFIX = 'opencode_connector_avail_';

	/**
	 * Stampede-lock suffix.
	 *
	 * @since 0.1.6
	 */
	public const LOCK_SUFFIX = '_lock';

	/**
	 * Probe-result transient key for a catalog.
	 *
	 * @since 0.1.6
	 *
	 * @param string $catalog Catalog slug.
	 * @return string
	 */
	public static function key( string $catalog ): string {
		return self::PREFIX . $catalog;
	}

	/**
	 * Stampede-lock transient key for a catalog.
	 *
	 * @since 0.1.6
	 *
	 * @param string $catalog Catalog slug.
	 * @return string
	 */
	public static function lockKey( string $catalog ): string {
		return self::PREFIX . $catalog . self::LOCK_SUFFIX;
	}

	/**
	 * Every probe and lock key across all catalogs.
	 *
	 * @since 0.1.6
	 *
	 * @return list<string>
	 */
	public static function allKeys(): array {
		$keys = array();
		foreach ( Catalog::all() as $catalog ) {
			$keys[] = self::key( $catalog );
			$keys[] = self::lockKey( $catalog );
		}
		return $keys;
	}
}
