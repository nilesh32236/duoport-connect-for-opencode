<?php
/**
 * Image payload validation.
 *
 * Validation half of the ImageAttachmentSaver split: MIME, size, and
 * content-sniff guards live here so security-critical re-encode edits in
 * ImageReencoder share no file with display-layer upload code.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */

declare(strict_types=1);

namespace OpenCodeConnector\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates generated image payloads against MIME and size guards.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */
final class ImageValidator {
	/**
	 * Maximum accepted payload size in bytes (10 MB).
	 *
	 * Single home for the byte cap: both the saver and the re-encoder
	 * read it from here, so the policy has one owner instead of two
	 * halves depending on each other cyclically.
	 *
	 * @since 0.1.9
	 *
	 * @var int
	 */
	public const MAX_BYTES = 10485760;

	/**
	 * Maximum decoded pixel count (width x height, 25 megapixels).
	 *
	 * Bounds the pixel buffer BEFORE anything decodes it: PHP memory
	 * exhaustion is not Throwable, so a decompression bomb (a tiny file
	 * declaring gigapixel dimensions) cannot be caught after the fact.
	 * Lives here with the byte cap so the re-encoder never reaches back
	 * into the saver for policy.
	 *
	 * @since 0.1.9
	 *
	 * @var int
	 */
	public const MAX_PIXELS = 25000000;

	/**
	 * Whether a MIME type is accepted.
	 *
	 * Single home for the allowlist check: ImageMime owns the list, this
	 * owns the predicate, and both the saver and the re-encoder call it
	 * instead of each other's statics.
	 *
	 * @since 0.1.9
	 *
	 * @param string $mime_type MIME type to check.
	 * @return bool
	 */
	public static function is_allowed_mime( string $mime_type ): bool {
		return in_array( strtolower( trim( $mime_type ) ), ImageMime::ALL, true );
	}

	/**
	 * Whether a payload size is within the byte cap.
	 *
	 * @since 0.1.9
	 *
	 * @param int $size_bytes Payload size in bytes.
	 * @return bool
	 */
	public static function is_within_size_limit( int $size_bytes ): bool {
		return $size_bytes >= 0 && $size_bytes <= self::MAX_BYTES;
	}

	/**
	 * Single header probe returning MIME plus dimensions.
	 *
	 * One `getimagesizefromstring()` parse shared by every guard instead of
	 * independent parses that could disagree. Null when unparseable.
	 * Never throws.
	 *
	 * @since 0.1.8
	 *
	 * @param string $image_bytes Raw bytes.
	 * @return array{mime: string, width: int, height: int}|null Header data, or null when unknown.
	 */
	public static function headerProbe( string $image_bytes ): ?array {
		try {
			if ( ! function_exists( 'getimagesizefromstring' ) ) {
				return null;
			}
			$info = @getimagesizefromstring( $image_bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unparseable buffer is an answer, not a fault.
			if ( ! is_array( $info ) || ! isset( $info['mime'], $info[0], $info[1] ) ) {
				return null;
			}
			if ( ! is_string( $info['mime'] ) || ! is_int( $info[0] ) || ! is_int( $info[1] ) ) {
				return null;
			}
			$mime = strtolower( trim( $info['mime'] ) );
			if ( '' === $mime || $info[0] <= 0 || $info[1] <= 0 ) {
				return null;
			}
			return array(
				'mime'   => $mime,
				'width'  => $info[0],
				'height' => $info[1],
			);
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Sniff the real content type of a payload via finfo.
	 *
	 * Null when unavailable or inconclusive. Never throws.
	 *
	 * @since 0.1.8
	 *
	 * @param string $image_bytes Raw bytes.
	 * @return string|null Lowercase detected MIME type, or null when unknown.
	 */
	public static function detectMime( string $image_bytes ): ?string {
		try {
			if ( ! class_exists( \finfo::class ) ) {
				return null;
			}
			$finfo    = new \finfo( FILEINFO_MIME_TYPE );
			$detected = $finfo->buffer( $image_bytes );
			if ( ! is_string( $detected ) || '' === $detected ) {
				return null;
			}
			return strtolower( trim( $detected ) );
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Structural MIME via the shared header probe.
	 *
	 * @since 0.1.8
	 *
	 * @param string $image_bytes Raw bytes.
	 * @return string|null Lowercase MIME, or null when unknown.
	 */
	public static function structuralMime( string $image_bytes ): ?string {
		$probe = self::headerProbe( $image_bytes );
		return null === $probe ? null : $probe['mime'];
	}

	/**
	 * Content-derived MIME preferring finfo, falling back to the header probe.
	 *
	 * @since 0.1.8
	 *
	 * @param string $image_bytes Raw bytes.
	 * @return string|null Lowercase MIME, or null when unknown.
	 */
	public static function contentMime( string $image_bytes ): ?string {
		return self::detectMime( $image_bytes ) ?? self::structuralMime( $image_bytes );
	}
}
