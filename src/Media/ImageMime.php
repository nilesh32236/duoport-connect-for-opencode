<?php
/**
 * Shared image MIME allowlist and extension map.
 *
 * @package OpenCodeConnector
 * @since 0.1.7
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
 * @since 0.1.7
 */
final class ImageMime {
	/**
	 * MIME types accepted for generated images.
	 *
	 * @since 0.1.7
	 *
	 * @var list<string>
	 */
	const ALL = array( 'image/png', 'image/jpeg', 'image/webp' );

	/**
	 * File extension per MIME type.
	 *
	 * @since 0.1.7
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
	 * @since 0.1.7
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return self::ALL;
	}

	/**
	 * File extension for a MIME type (fail-open to png).
	 *
	 * @since 0.1.7
	 *
	 * @param string $mime_type MIME type.
	 * @return string
	 */
	public static function extensionFor( string $mime_type ): string {
		return self::EXTENSIONS[ strtolower( trim( $mime_type ) ) ] ?? 'png';
	}
}
