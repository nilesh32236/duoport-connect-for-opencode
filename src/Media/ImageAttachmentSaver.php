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
	 * Single source of truth lives in ImageMime::ALL; this alias is kept
	 * for backward compatibility.
	 *
	 * @since 0.1.4
	 *
	 * @var list<string>
	 */
	public const ALLOWED_MIME_TYPES = ImageMime::ALL;

	/**
	 * Maximum accepted payload size in bytes (10 MB).
	 *
	 * @since 0.1.4
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
	 *
	 * @since 0.1.9
	 *
	 * @var int
	 */
	public const MAX_PIXELS = 25000000;

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
	 * Besides the claimed-type allowlist, the bytes themselves are sniffed:
	 * a payload whose detected content type differs from the claim is
	 * rejected, so a non-image masquerading as one never reaches uploads.
	 * Size is checked before sniffing so oversized payloads fail fast.
	 *
	 * Detection is not allowed to be skipped, and the claim is never a gate on
	 * its own. Two content-derived signals stand in front of `wp_upload_bits()`:
	 *
	 * 1. `structural_mime()` — `getimagesizefromstring()`, part of core PHP
	 *    rather than an extension, so it is available on exactly the minimal
	 *    builds where the finfo sniff is not. A buffer it cannot parse as an
	 *    allowlisted image container is rejected outright. Before this, the
	 *    allowlisted *claim* was the only gate on such hosts, and the claim is
	 *    attacker-controlled.
	 * 2. `detect_mime()` — the finfo magic-byte sniff, used for the
	 *    claim-versus-content comparison when it is available.
	 *
	 * Note what these two still cannot do, and why that matters elsewhere: every
	 * signal here reads the HEADER. A well-formed image with a payload appended
	 * after its final chunk is still "image/png" to all of them. The guarantee
	 * that what lands in uploads contains nothing but image data is
	 * `reencode_image()`, which `save_to_media_library()` runs before writing.
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
		$structural = self::structural_mime( $image_bytes );
		if ( null === $structural || ! self::is_allowed_mime( $structural ) ) {
			return new \WP_Error(
				'opencode_image_unreadable',
				__( 'The generated image could not be read as a supported image.', 'duoport-connect-for-opencode' )
			);
		}
		$detected = self::detect_mime( $image_bytes ) ?? $structural;
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
	 * Sniff the real content type of a payload.
	 *
	 * Returns null when detection is unavailable (finfo missing) or
	 * inconclusive — callers then fall back to `structural_mime()` rather than
	 * to the claimed type. Never throws.
	 *
	 * @since 0.1.4
	 *
	 * @param string $image_bytes Raw bytes.
	 * @return string|null Lowercase detected MIME type, or null when unknown.
	 */
	private static function detect_mime( string $image_bytes ): ?string {
		return ImageValidator::detectMime( $image_bytes );
	}

	/**
	 * Single header probe returning MIME plus dimensions.
	 *
	 * One `getimagesizefromstring()` parse shared by validate(),
	 * reencode_image(), and save_to_media_library() instead of three
	 * independent parses that could disagree. Returns null when the buffer
	 * has no parseable image header — callers fail closed on null.
	 * Never throws.
	 *
	 * @since 0.1.8
	 *
	 * @param string $image_bytes Raw bytes.
	 * @return array{mime: string, width: int, height: int}|null Header data, or null when unknown.
	 */
	private static function header_probe( string $image_bytes ): ?array {
		return ImageValidator::headerProbe( $image_bytes );
	}

	/**
	 * Second-opinion content read, independent of ext/fileinfo.
	 *
	 * `getimagesizefromstring()` lives in core PHP rather than in an
	 * extension, so it is available on exactly the minimal builds where
	 * `detect_mime()` is not. It parses the container header and reports the
	 * format it found, which is a content-derived signal rather than the
	 * caller's claim. It reads only the header, exactly like the finfo sniff,
	 * so it is a fallback for UNAVAILABILITY — never a replacement for the
	 * re-encode.
	 *
	 * Never throws.
	 *
	 * @since 0.1.9
	 *
	 * @param string $image_bytes Raw bytes.
	 * @return string|null Lowercase detected MIME type, or null when unknown.
	 */
	private static function structural_mime( string $image_bytes ): ?string {
		$probe = self::header_probe( $image_bytes );
		if ( null === $probe ) {
			return null;
		}
		return $probe['mime'];
	}

	/**
	 * Header-only pixel dimensions of a payload.
	 *
	 * Reads the container header via `getimagesizefromstring()` without
	 * decoding any pixels, so calling it cannot exhaust memory no matter
	 * what dimensions the header declares. Returns null when the buffer
	 * has no parseable image header — callers fail closed on null.
	 *
	 * Never throws.
	 *
	 * @since 0.1.9
	 *
	 * @param string $image_bytes Raw bytes.
	 * @return array{0:int,1:int}|null Width and height, or null when unknown.
	 */
	private static function image_dimensions( string $image_bytes ): ?array {
		$probe = self::header_probe( $image_bytes );
		if ( null === $probe ) {
			return null;
		}
		return array( $probe['width'], $probe['height'] );
	}

	/**
	 * MIME type for a file extension, restricted to the allowlist.
	 *
	 * Inverse of `ImageMime::extensionFor()` for the output-identity check:
	 * maps what the editor actually wrote (its output path's extension) back
	 * to a MIME type. Returns null for anything outside the allowlist, so an
	 * unexpected output format fails closed downstream.
	 *
	 * @since 0.1.9
	 *
	 * @param string $extension File extension without the dot.
	 * @return string|null Allowlisted MIME type, or null when unmapped.
	 */
	private static function mime_for_extension( string $extension ): ?string {
		return ImageMime::mimeForExtension( $extension );
	}

	/**
	 * Re-encode a payload so only decoded image data survives.
	 *
	 * The reason this exists. Everything upstream of it — the allowlist, the
	 * finfo sniff, `getimagesize()` — reads a header, so a well-formed PNG
	 * with `<?php … ?>` appended after the final IEND chunk is reported as
	 * `image/png` by all of them and passes every check this class had. Those
	 * bytes were then written verbatim into a web-served uploads directory and
	 * registered as an attachment with the claimed MIME type. Decoding the
	 * image and encoding it back out is the only step that necessarily drops
	 * whatever followed the image data, so the stored file becomes a
	 * re-encoding of what the pixels were rather than a copy of the
	 * transmitted buffer.
	 *
	 * FAIL CLOSED. If no image editor is available (no GD, no Imagick), or the
	 * decode fails, or the editor refuses to save, this returns a WP_Error and
	 * nothing is written. The alternative — storing the buffer as-is when no
	 * editor exists — reinstates the exact hole this closes on precisely the
	 * hosts least likely to be running an editor, which is the wrong way round:
	 * it makes the guard's strength depend on a detail of the environment.
	 *
	 * Every temporary file created here is removed on every path, including
	 * the editor's actual output file when `save()` rewrites the destination
	 * extension under output-format filters.
	 *
	 * @since 0.1.9
	 *
	 * @param string $image_bytes    Raw image bytes.
	 * @param string $detected_mime  MIME type identified from the content.
	 * @return array{bytes:string,mime:string}|\WP_Error Re-encoded bytes plus the MIME type of what the editor actually wrote, or WP_Error on any failure.
	 */
	private static function reencode_image( string $image_bytes, string $detected_mime ): array|\WP_Error {
		return ImageReencoder::reencode( $image_bytes, $detected_mime );
	}

	/**
	 * Shared decode-failure error.
	 *
	 * @since 0.1.9
	 *
	 * @return \WP_Error
	 */
	private static function decode_error(): \WP_Error {
		return new \WP_Error(
			'opencode_image_decode',
			__( 'The generated image could not be decoded, so it was not saved.', 'duoport-connect-for-opencode' )
		);
	}

	/**
	 * Save image bytes to the Media Library.
	 *
	 * The bytes written are the re-encoding of the decoded image, never the
	 * transmitted buffer — see `reencode_image()` — and both the file
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
		$decoded_mime = self::detect_mime( $image_bytes ) ?? self::structural_mime( $image_bytes );
		if ( null === $decoded_mime || ! self::is_allowed_mime( $decoded_mime ) ) {
			return new \WP_Error(
				'opencode_image_unreadable',
				__( 'The generated image could not be read as a supported image.', 'duoport-connect-for-opencode' )
			);
		}

		$encoded = self::reencode_image( $image_bytes, $decoded_mime );
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
