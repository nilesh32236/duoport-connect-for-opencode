<?php
/**
 * Case-insensitive HTTP header helpers.
 *
 * Single source of truth for header-exists checks so comparison fixes
 * (e.g. trimming, non-string names) land once instead of once per caller.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */

declare(strict_types=1);

namespace OpenCodeConnector\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Case-insensitive HTTP header helpers.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */
final class Headers {

	/**
	 * Whether a headers array already carries a header (case-insensitive).
	 *
	 * @since 0.1.6
	 *
	 * @param array  $headers Request headers.
	 * @param string $name    Header name to look for.
	 * @return bool
	 */
	public static function has( array $headers, string $name ): bool {
		foreach ( $headers as $existing => $value ) {
			unset( $value );
			if ( is_string( $existing ) && 0 === strcasecmp( $existing, $name ) ) {
				return true;
			}
		}
		return false;
	}
}
