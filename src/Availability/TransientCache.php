<?php
/**
 * Guarded transient cache with jittered TTL policy.
 *
 * Cache half of the OpenCodeProviderAvailability split: transient
 * get/set/delete plus the jitter policy live here so a cache-policy edit
 * cannot touch probe semantics and vice versa.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */

declare(strict_types=1);

namespace OpenCodeConnector\Availability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guarded transient reads/writes with stampede-safe TTLs.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */
final class TransientCache {
	/**
	 * Guarded jitter source for probe cache windows.
	 *
	 * @since 0.1.8
	 *
	 * @param int $min Minimum spread (inclusive).
	 * @param int $max Maximum spread (inclusive).
	 * @return int Drawn spread, or 0 when wp_rand() is unavailable or throws.
	 */
	public static function randSpread( int $min, int $max ): int {
		if ( ! function_exists( 'wp_rand' ) ) {
			return 0;
		}
		try {
			return wp_rand( $min, $max );
		} catch ( \Throwable $rand_exception ) {
			unset( $rand_exception );
			return 0;
		}
	}

	/**
	 * Add jitter to a cache TTL so probes do not stampede.
	 *
	 * @since 0.1.8
	 *
	 * @param int $base Base TTL in seconds.
	 * @return int Jittered TTL in seconds.
	 */
	public static function jitteredTtl( int $base ): int {
		return max( 60, $base + self::randSpread( -60, 60 ) );
	}

	/**
	 * The could-not-be-checked window for TRANSIENT failures, jittered 45-75s.
	 *
	 * @since 0.1.8
	 *
	 * @return int Jittered short window in seconds.
	 */
	public static function jitteredShortTtl(): int {
		$second = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
		return max( 30, $second + self::randSpread( -15, 15 ) );
	}

	/**
	 * Guarded transient read with a cache-miss fallback.
	 *
	 * @since 0.1.8
	 *
	 * @param string $key Transient key.
	 * @return mixed
	 */
	public static function get( string $key ): mixed {
		if ( ! function_exists( 'get_transient' ) ) {
			return false;
		}
		try {
			return get_transient( $key );
		} catch ( \Throwable $read_exception ) {
			unset( $read_exception );
			return false;
		}
	}

	/**
	 * Guarded transient write.
	 *
	 * @since 0.1.8
	 *
	 * @param string $key   Transient key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Time to live in seconds.
	 * @return bool
	 */
	public static function set( string $key, mixed $value, int $ttl ): bool {
		if ( ! function_exists( 'set_transient' ) ) {
			return false;
		}
		try {
			return (bool) set_transient( $key, $value, $ttl );
		} catch ( \Throwable $write_exception ) {
			unset( $write_exception );
			return false;
		}
	}

	/**
	 * Guarded transient delete.
	 *
	 * @since 0.1.8
	 *
	 * @param string $key Transient key.
	 * @return bool
	 */
	public static function delete( string $key ): bool {
		if ( ! function_exists( 'delete_transient' ) ) {
			return false;
		}
		try {
			return (bool) delete_transient( $key );
		} catch ( \Throwable $delete_exception ) {
			unset( $delete_exception );
			return false;
		}
	}
}
