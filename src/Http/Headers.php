<?php
/**
 * Case-insensitive HTTP header helpers.
 *
 * @package OpenCodeConnector
 * @since 0.1.7
 */

declare(strict_types=1);

namespace OpenCodeConnector\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared header-name comparison helper.
 *
 * @package OpenCodeConnector
 * @since 0.1.7
 */
final class Headers {
	/**
	 * Whether a headers array already carries a header (case-insensitive).
	 *
	 * Never throws; non-string keys are ignored.
	 *
	 * @since 0.1.7
	 *
	 * @param array  $headers Request headers.
	 * @param string $name    Header name to look for.
	 * @return bool
	 */
	public static function has( array $headers, string $name ): bool {
		foreach ( $headers as $key => $existing ) {
			unset( $existing );
			if ( is_string( $key ) && 0 === strcasecmp( $key, $name ) ) {
				return true;
			}
		}
		return false;
	}
}
