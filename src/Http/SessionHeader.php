<?php
/**
 * Stable session header for Go chat requests.
 *
 * Derives a stable opaque session value for routing and prompt-cache affinity.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */

declare(strict_types=1);

namespace OpenCodeConnector\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Providers\OpenCodeGoProvider;

/**
 * Stable session header helper.
 *
 * Credential-blind and option-blind: derivation only looks at the request
 * payload (model + messages) and never reads options, users, or globals.
 *
 * Session affinity is payload-canonical sensitive: array content is
 * recursively key-sorted before hashing so reordered keys hash alike, while
 * roles are lowercased (protocol-fixed values). Anything beyond that
 * (whitespace, casing of free text) intentionally yields a new session.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */
final class SessionHeader {
	/**
	 * Header name sent on Go requests.
	 *
	 * @since 0.1.4
	 */
	const HEADER_NAME = 'x-opencode-session';

	/**
	 * Maximum header value length (derived values are clamped to this).
	 *
	 * @since 0.1.4
	 */
	const VALUE_MAX_LENGTH = 64;

	/**
	 * Whether the session header should be sent for a provider class.
	 *
	 * Single policy consulted by both the text model and the availability
	 * probe: only the Go catalog requires x-opencode-session (it rejects
	 * headerless requests); Zen ignores the extra header. Subclasses of the Go provider count as Go so extended providers keep the header.
	 *
	 * @since 0.1.4
	 *
	 * @param string $provider_class Provider FQCN.
	 * @return bool
	 */
	public static function should_send_for( string $provider_class ): bool {
		return is_a( $provider_class, OpenCodeGoProvider::class, true );
	}

	/**
	 * Derive a stable opaque session value from request data.
	 *
	 * Returns null when there is no usable conversation context so callers
	 * can omit the header (fail-open, behavior unchanged). Never throws.
	 *
	 * @since 0.1.4
	 *
	 * @param mixed $data Request data.
	 * @return string|null Opaque hex session value, or null when omitted.
	 */
	public static function derive_from_data( $data ): ?string {
		try {
			if ( ! is_array( $data ) ) {
				return null;
			}
			if ( empty( $data['messages'] ) || ! is_array( $data['messages'] ) ) {
				return null;
			}
			$messages = array();
			foreach ( $data['messages'] as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}
				$role    = isset( $message['role'] ) && is_string( $message['role'] ) ? strtolower( $message['role'] ) : '';
				$content = self::normalize_content( $message['content'] ?? '' );
				if ( '' === $role && '' === $content ) {
					continue;
				}
				$messages[] = array(
					'role'    => $role,
					'content' => $content,
				);
			}
			if ( array() === $messages ) {
				return null;
			}
			$model     = isset( $data['model'] ) && is_string( $data['model'] ) ? $data['model'] : '';
			$canonical = array(
				'model'    => $model,
				'messages' => $messages,
			);
			if ( function_exists( 'wp_json_encode' ) ) {
				$encoded = wp_json_encode( $canonical );
			} else {
				// No WP context (e.g. unit tests): plain encoding is sufficient for hashing.
				$encoded = json_encode( $canonical ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
			if ( ! is_string( $encoded ) || '' === $encoded ) {
				return null;
			}
			$hash = hash( 'sha256', $encoded );
			if ( ! is_string( $hash ) || '' === $hash ) {
				return null;
			}
			return substr( $hash, 0, 32 );
		} catch ( \Throwable ) {
			// Fail-open: derivation must never be fatal.
			return null;
		}
	}

	/**
	 * Inject the session header into a headers array.
	 *
	 * Adds the header only when conversation context exists and no value was
	 * explicitly provided (compared case-insensitively). Never throws.
	 *
	 * @since 0.1.4
	 *
	 * @param array $headers Request headers.
	 * @param mixed $data    Request data.
	 * @return array Headers with the session value added when applicable.
	 */
	public static function inject_into_headers( array $headers, $data ): array {
		try {
			$session = self::derive_from_data( $data );
		} catch ( \Throwable ) {
			// Fail-open: leave headers unchanged when derivation fails.
			return $headers;
		}
		if ( null === $session ) {
			return $headers;
		}
		foreach ( $headers as $name => $existing ) {
			if ( is_string( $name ) && 0 === strcasecmp( $name, self::HEADER_NAME ) ) {
				return $headers;
			}
		}
		$headers[ self::HEADER_NAME ] = substr( $session, 0, self::VALUE_MAX_LENGTH );
		return $headers;
	}

	/**
	 * Normalize a message content value to a canonical string.
	 *
	 * @since 0.1.4
	 *
	 * @param mixed $content Raw content value.
	 * @return string Canonical string form.
	 */
	private static function normalize_content( $content ): string {
		if ( is_string( $content ) ) {
			return $content;
		}
		if ( is_int( $content ) || is_float( $content ) || is_bool( $content ) ) {
			return (string) $content;
		}
		if ( is_array( $content ) ) {
			try {
				$content = self::canonicalize_value( $content );
				if ( function_exists( 'wp_json_encode' ) ) {
					$encoded = wp_json_encode( $content );
				} else {
					$encoded = json_encode( $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				}
				return is_string( $encoded ) ? $encoded : '';
			} catch ( \Throwable ) {
				return '';
			}
		}
		return '';
	}

	/**
	 * Recursively key-sort an array value for canonical hashing.
	 *
	 * Sequential lists are unaffected (keys 0..n already sort); maps hash
	 * alike regardless of insertion order. Never throws.
	 *
	 * @since 0.1.4
	 *
	 * @param mixed $value Raw value.
	 * @return mixed Canonicalized value.
	 */
	private static function canonicalize_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		// Fast path: sequential lists keep order (no ksort); only recurse
		// into items so large multimodal content blocks skip the sort.
		if ( array_is_list( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::canonicalize_value( $item );
			}
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize_value( $item );
		}
		ksort( $value );
		return $value;
	}
}
