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
	 * MIME types accepted for generated images.
	 *
	 * @var list<string>
	 */
	public const ALLOWED_MIME_TYPES = array( 'image/png', 'image/jpeg', 'image/webp' );

	/**
	 * Maximum accepted payload size in bytes (10 MB).
	 *
	 * @var int
	 */
	public const MAX_BYTES = 10485760;

	/**
	 * Whether a MIME type is accepted.
	 *
	 * @since 0.1.4
	 *
	 * @param string $mime_type MIME type to check.
	 * @return bool
	 */
	public static function is_allowed_mime( string $mime_type ): bool {
		return in_array( strtolower( trim( $mime_type ) ), self::ALLOWED_MIME_TYPES, true );
	}

	/**
	 * Whether a payload size is within the byte cap.
	 *
	 * @since 0.1.4
	 *
	 * @param int $size_bytes Payload size in bytes.
	 * @return bool
	 */
	public static function is_within_size_limit( int $size_bytes ): bool {
		return $size_bytes >= 0 && $size_bytes <= self::MAX_BYTES;
	}

	/**
	 * Validate an image payload against the MIME and size guards.
	 *
	 * @since 0.1.4
	 *
	 * @param string $image_bytes Raw image bytes.
	 * @param string $mime_type   Claimed MIME type.
	 * @return true|\WP_Error True when valid, WP_Error otherwise.
	 */
	public static function validate( string $image_bytes, string $mime_type ) {
		if ( '' === $image_bytes ) {
			return new \WP_Error(
				'opencode_image_empty',
				__( 'The generated image payload is empty.', 'duoport-connect-for-opencode' )
			);
		}
		if ( ! self::is_allowed_mime( $mime_type ) ) {
			return new \WP_Error(
				'opencode_image_mime',
				sprintf(
					/* translators: %s: MIME type. */
					__( 'Unsupported image MIME type: %s.', 'duoport-connect-for-opencode' ),
					$mime_type
				)
			);
		}
		if ( ! self::is_within_size_limit( strlen( $image_bytes ) ) ) {
			return new \WP_Error(
				'opencode_image_size',
				sprintf(
					/* translators: %d: maximum size in bytes. */
					__( 'The generated image exceeds the %d byte limit.', 'duoport-connect-for-opencode' ),
					self::MAX_BYTES
				)
			);
		}
		return true;
	}

	/**
	 * Save image bytes to the Media Library.
	 *
	 * @since 0.1.4
	 *
	 * @param string $image_bytes Raw image bytes.
	 * @param string $mime_type   MIME type (must be in the allowlist).
	 * @param string $filename    Desired file name (extension is normalized to the MIME type).
	 * @return int|\WP_Error Attachment ID on success, WP_Error otherwise.
	 */
	public static function save_to_media_library( string $image_bytes, string $mime_type, string $filename = 'opencode-image' ) {
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

		$extensions = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
		);
		$extension  = $extensions[ strtolower( trim( $mime_type ) ) ] ?? 'png';
		$base       = sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) );
		if ( '' === $base ) {
			$base = 'opencode-image';
		}
		$upload = wp_upload_bits( $base . '.' . $extension, null, $image_bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'opencode_image_upload', (string) $upload['error'] );
		}
		if ( empty( $upload['file'] ) ) {
			return new \WP_Error(
				'opencode_image_upload',
				__( 'The image upload failed.', 'duoport-connect-for-opencode' )
			);
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => strtolower( trim( $mime_type ) ),
				'post_title'     => $base,
				'post_status'    => 'inherit',
			),
			(string) $upload['file']
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
		wp_update_attachment_metadata( (int) $attachment_id, wp_generate_attachment_metadata( (int) $attachment_id, (string) $upload['file'] ) );

		return (int) $attachment_id;
	}
}
