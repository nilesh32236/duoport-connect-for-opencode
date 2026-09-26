<?php
/**
 * WP-aware JSON encoding helper that never throws.
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
 * Shared JSON encoder (wp_json_encode with plain fallback).
 *
 * @package OpenCodeConnector
 * @since 0.1.7
 */
final class Json {
	/**
	 * Encode a value to JSON without throwing.
	 *
	 * Uses `wp_json_encode()` when available, plain `json_encode()` outside
	 * a WP context (e.g. unit tests). Returns null when unencodable.
	 *
	 * @since 0.1.7
	 *
	 * @param mixed $value Value to encode.
	 * @return string|null Encoded JSON, or null when unencodable.
	 */
	public static function encode( $value ): ?string {
		try {
			if ( function_exists( 'wp_json_encode' ) ) {
				$encoded = wp_json_encode( $value );
			} else {
				// No WP context (e.g. unit tests): plain encoding is sufficient.
				$encoded = json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
			return is_string( $encoded ) && '' !== $encoded ? $encoded : null;
		} catch ( \Throwable ) {
			return null;
		}
	}
}
