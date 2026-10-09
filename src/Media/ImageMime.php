<?php
/**
 * Shared image MIME allowlist and extension map.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */

declare(strict_types=1);

namespace OpenCodeConnector\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for generated-image MIME types.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */
final class ImageMime {
	/**
	 * MIME types accepted for generated images.
	 *
	 * @since 0.1.6
	 *
	 * @var list<string>
	 */
	const ALL = array( 'image/png', 'image/jpeg', 'image/webp' );

	/**
	 * File extension per MIME type.
	 *
	 * @since 0.1.6
	 *
	 * @var array<string, string>
	 */
	private const EXTENSIONS = array(
		'image/png'  => 'png',
		'image/jpeg' => 'jpg',
		'image/webp' => 'webp',
	);

	/**
	 * Output MIME list for image model metadata.
	 *
	 * Returns a copy so callers cannot mutate the shared constant.
	 *
	 * @since 0.1.6
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return self::ALL;
	}

	/**
	 * File extension for a MIME type (fail-open to png).
	 *
	 * @since 0.1.6
	 *
	 * @param string $mime_type MIME type.
	 * @return string
	 */
	public static function extensionFor( string $mime_type ): string {
		return self::EXTENSIONS[ strtolower( trim( $mime_type ) ) ] ?? 'png';
	}

	/**
	 * MIME type for a file extension, restricted to the allowlist.
	 *
	 * Single inverse of EXTENSIONS: adding a format means editing this map
	 * only. Returns null for anything outside the allowlist so an
	 * unexpected output format fails closed downstream.
	 *
	 * @since 0.1.8
	 *
	 * @param string $extension File extension without the dot.
	 * @return string|null Allowlisted MIME type, or null when unmapped.
	 */
	public static function mimeForExtension( string $extension ): ?string {
		$ext = strtolower( trim( $extension ) );
		if ( '' === $ext ) {
			return null;
		}
		if ( 'jpeg' === $ext ) {
			$ext = 'jpg';
		}
		$inverse = array_flip( self::EXTENSIONS );
		return $inverse[ $ext ] ?? null;
	}
}
