<?php
/**
 * Media Library saver for generated images.
 *
 * Persists image bytes (e.g. from `GenerativeAiResult::toImageFile()`) as a
 * Media Library attachment behind MIME, size, and capability guards.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */

declare(strict_types=1);

namespace OpenCodeConnector\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saves generated image payloads to the Media Library.
 *
 * The MIME allowlist and byte cap bound binary payloads (and therefore cost)
 * before anything touches the uploads directory.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */
final class ImageAttachmentSaver {
	/**
	 * Maximum accepted payload size in bytes (10 MB).
	 *
	 * Backward-compatibility alias of ImageValidator::MAX_BYTES, which is
	 * the single home for the byte cap. Retained so existing callers keep
	 * working; new code reads the validator directly.
	 *
	 * @since 0.1.4
	 *
	 * @var int
	 */
	public const MAX_BYTES = 10485760;

	/**
	 * Maximum decoded pixel count (width x height, 25 megapixels).
	 *
	 * Backward-compatibility alias of ImageValidator::MAX_PIXELS, which is
	 * the single home for the pixel cap. Retained so existing callers keep
	 * working; new code reads the validator directly.
	 *
	 * Bounds the pixel buffer BEFORE anything decodes it: PHP memory
	 * exhaustion is not Throwable, so a decompression bomb (a tiny file
	 * declaring gigapixel dimensions) cannot be caught after the fact.
	 *
	 * @since 0.1.9
	 *
	 * @var int
	 */
	public const MAX_PIXELS = 25000000;

	/**
	 * Whether a MIME type is accepted.
	 *
	 * Backward-compatibility alias of ImageValidator::is_allowed_mime(),
	 * which is the single home for the allowlist check. Retained so
	 * existing callers keep working; new code calls the validator directly.
	 *
	 * @since 0.1.4
	 *
	 * @param string $mime_type MIME type to check.
	 * @return bool
	 */
	public static function is_allowed_mime( string $mime_type ): bool {
		return ImageValidator::is_allowed_mime( $mime_type );
	}

	/**
	 * Whether a payload size is within the byte cap.
	 *
	 * Backward-compatibility alias of
	 * ImageValidator::is_within_size_limit(). Retained so existing callers
	 * keep working; new code calls the validator directly.
	 *
	 * @since 0.1.4
	 *
	 * @param int $size_bytes Payload size in bytes.
	 * @return bool
	 */
	public static function is_within_size_limit( int $size_bytes ): bool {
		return ImageValidator::is_within_size_limit( $size_bytes );
	}

	/**
	 * Validate an image payload against the MIME and size guards.
	 *
	 * Besides the claimed-type allowlist, the bytes themselves are sniffed:
	 * a payload whose detected content type differs from the claim is
	 * rejected, so a non-image masquerading as one never reaches uploads.
	 * Size is checked before sniffing so oversized payloads fail fast.
	 *
	 * Detection is not allowed to be skipped, and the claim is never a gate on
	 * its own. Two content-derived signals stand in front of `wp_upload_bits()`:
	 *
	 * 1. `ImageValidator::structuralMime()` — `getimagesizefromstring()`,
	 *    part of core PHP
	 *    rather than an extension, so it is available on exactly the minimal
	 *    builds where the finfo sniff is not. A buffer it cannot parse as an
	 *    allowlisted image container is rejected outright. Before this, the
	 *    allowlisted *claim* was the only gate on such hosts, and the claim is
	 *    attacker-controlled.
	 * 2. `ImageValidator::detectMime()` — the finfo magic-byte sniff, used for the
	 *    claim-versus-content comparison when it is available.
	 *
	 * Note what these two still cannot do, and why that matters elsewhere: every
	 * signal here reads the HEADER. A well-formed image with a payload appended
	 * after its final chunk is still "image/png" to all of them. The guarantee
	 * that what lands in uploads contains nothing but image data is
	 * `ImageReencoder::reencode()`, which `save_to_media_library()` runs before writing.
	 *
	 * @since 0.1.4
	 *
	 * @param string $image_bytes Raw image bytes.
	 * @param string $mime_type   Claimed MIME type.
	 * @return true|\WP_Error True when valid, WP_Error otherwise.
	 */
	public static function validate( string $image_bytes, string $mime_type ): bool|\WP_Error {
		if ( '' === $image_bytes ) {
			return new \WP_Error(
				'opencode_image_empty',
				__( 'The generated image payload is empty.', 'duoport-connect-for-opencode' )
			);
		}
		if ( ! ImageValidator::is_allowed_mime( $mime_type ) ) {
			return new \WP_Error(
				'opencode_image_mime',
				sprintf(
					/* translators: %s: MIME type. */
					__( 'Unsupported image MIME type: %s.', 'duoport-connect-for-opencode' ),
					$mime_type
				)
			);
		}
		if ( ! ImageValidator::is_within_size_limit( strlen( $image_bytes ) ) ) {
			return new \WP_Error(
				'opencode_image_size',
				sprintf(
					/* translators: %d: maximum size in bytes. */
					__( 'The generated image exceeds the %d byte limit.', 'duoport-connect-for-opencode' ),
					ImageValidator::MAX_BYTES
				)
			);
		}
		$structural = ImageValidator::structuralMime( $image_bytes );
		if ( null === $structural || ! ImageValidator::is_allowed_mime( $structural ) ) {
			return new \WP_Error(
				'opencode_image_unreadable',
				__( 'The generated image could not be read as a supported image.', 'duoport-connect-for-opencode' )
			);
		}
		$detected = ImageValidator::detectMime( $image_bytes ) ?? $structural;
		if ( strtolower( trim( $mime_type ) ) !== $detected ) {
			return new \WP_Error(
				'opencode_image_mime_mismatch',
				sprintf(
					/* translators: 1: claimed MIME type, 2: detected MIME type. */
					__( 'The image content (%2$s) does not match the claimed type %1$s.', 'duoport-connect-for-opencode' ),
					$mime_type,
					$detected
				)
			);
		}
		return true;
	}

	/**
	 * Save image bytes to the Media Library.
	 *
	 * The bytes written are the re-encoding of the decoded image, never the
	 * transmitted buffer — see `ImageReencoder::reencode()` — and both the file
	 * extension and the registered `post_mime_type` follow what the editor
	 * actually wrote rather than the caller's claim, so a site filtering the
	 * editor's output format still gets a consistently-named attachment.
	 *
	 * @since 0.1.4
	 *
	 * @param string $image_bytes Raw image bytes.
	 * @param string $mime_type   MIME type (must be in the allowlist).
	 * @param string $filename    Desired file name (extension is normalized to the MIME type).
	 * @return int|\WP_Error Attachment ID on success, WP_Error otherwise.
	 */
	public static function save_to_media_library( string $image_bytes, string $mime_type, string $filename = 'opencode-image' ): int|\WP_Error {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error(
				'opencode_image_capability',
				__( 'You do not have permission to upload images.', 'duoport-connect-for-opencode' )
			);
		}

		$valid = self::validate( $image_bytes, $mime_type );
		if ( true !== $valid ) {
			return $valid;
		}

		// Content wins over the claim from here on: the decoded format is the
		// one the extension and the attachment's MIME type are taken from.
		// validate() above has already established that this resolves to an
		// allowlisted type and agrees with the claim; the re-guard only keeps
		// that invariant local to the write path.
		$decoded_mime = ImageValidator::contentMime( $image_bytes );
		if ( null === $decoded_mime || ! ImageValidator::is_allowed_mime( $decoded_mime ) ) {
			return new \WP_Error(
				'opencode_image_unreadable',
				__( 'The generated image could not be read as a supported image.', 'duoport-connect-for-opencode' )
			);
		}

		// The re-encode is what guarantees the stored file contains only
		// decoded image data: everything above reads a header, so a
		// well-formed image with bytes appended after its final chunk still
		// reports its container MIME here. Decoding and encoding back out
		// drops whatever followed the image data. Fail closed when no
		// editor exists or the decode fails — nothing is written.
		$encoded = ImageReencoder::reencode( $image_bytes, $decoded_mime );
		if ( $encoded instanceof \WP_Error ) {
			return $encoded;
		}

		// Content wins over the claim to the end: extension and attachment
		// MIME follow the re-encoded output, which under an output-format
		// filter can legitimately differ from the input.
		$encoded_bytes = $encoded['bytes'];
		$encoded_mime  = $encoded['mime'];

		$upload = self::uploadReencoded( $encoded_bytes, $encoded_mime, $filename );
		if ( $upload instanceof \WP_Error ) {
			return $upload;
		}
		return self::createAttachment( $upload['file'], $upload['base'], $encoded_mime );
	}

	/**
	 * Upload re-encoded bytes via wp_upload_bits().
	 *
	 * @since 0.1.8
	 *
	 * @param string $encoded_bytes Re-encoded image bytes.
	 * @param string $encoded_mime  Re-encoded MIME type.
	 * @param string $filename      Desired file name.
	 * @return array{file: string, base: string}|\WP_Error Upload file path plus base name, or WP_Error.
	 */
	private static function uploadReencoded( string $encoded_bytes, string $encoded_mime, string $filename ): array|\WP_Error {
		$extension = ImageMime::extensionFor( $encoded_mime );
		$base      = sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) );
		if ( '' === $base ) {
			$base = 'opencode-image';
		}
		$upload = wp_upload_bits( $base . '.' . $extension, null, $encoded_bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'opencode_image_upload', (string) $upload['error'] );
		}
		if ( empty( $upload['file'] ) ) {
			return new \WP_Error(
				'opencode_image_upload',
				__( 'The image upload failed.', 'duoport-connect-for-opencode' )
			);
		}
		return array(
			'file' => (string) $upload['file'],
			'base' => $base,
		);
	}

	/**
	 * Insert the uploaded file as an attachment with generated metadata.
	 *
	 * @since 0.1.8
	 *
	 * @param string $file         Uploaded file path.
	 * @param string $base         Sanitized base name for the title.
	 * @param string $encoded_mime Re-encoded MIME type.
	 * @return int|\WP_Error Attachment ID on success, WP_Error otherwise.
	 */
	private static function createAttachment( string $file, string $base, string $encoded_mime ): int|\WP_Error {
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $encoded_mime,
				'post_title'     => $base,
				'post_status'    => 'inherit',
			),
			$file
		);
		if ( ! $attachment_id || $attachment_id instanceof \WP_Error ) {
			return $attachment_id instanceof \WP_Error
				? $attachment_id
				: new \WP_Error(
					'opencode_image_attachment',
					__( 'The image attachment could not be created.', 'duoport-connect-for-opencode' )
				);
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		wp_update_attachment_metadata( (int) $attachment_id, wp_generate_attachment_metadata( (int) $attachment_id, $file ) );

		return (int) $attachment_id;
	}
}
