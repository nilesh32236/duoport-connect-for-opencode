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
		try {
			if ( ! function_exists( 'getimagesizefromstring' ) ) {
				return null;
			}
			// getimagesizefromstring() raises a warning on a buffer it cannot
			// parse, which for this caller is an expected "not an image" answer
			// rather than a fault: the null return below is the real check.
			$info = @getimagesizefromstring( $image_bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unparseable buffer is an answer, not a fault.
			if ( ! is_array( $info ) || ! isset( $info['mime'] ) || ! is_string( $info['mime'] ) ) {
				return null;
			}
			$mime = strtolower( trim( $info['mime'] ) );
			return '' === $mime ? null : $mime;
		} catch ( \Throwable ) {
			return null;
		}
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
	 * Every temporary file created here is removed on every path.
	 *
	 * @since 0.1.9
	 *
	 * @param string $image_bytes    Raw image bytes.
	 * @param string $detected_mime  MIME type identified from the content.
	 * @return string|\WP_Error Re-encoded bytes, or WP_Error on any failure.
	 */
	private static function reencode_image( string $image_bytes, string $detected_mime ): string|\WP_Error {
		if ( ! self::is_allowed_mime( $detected_mime ) ) {
			return self::decode_error();
		}
		$tmp_files = array();
		$extension = ImageMime::extensionFor( $detected_mime );

		try {
			if ( ! function_exists( 'wp_tempnam' ) ) {
				return self::decode_error();
			}
			// wp_tempnam() hands back an EXTENSION-LESS path: it strips its
			// filename argument down to a prefix before calling tempnam(). The
			// extension has to be put back on both paths, because WordPress's
			// image editors pick the OUTPUT FORMAT from the destination's
			// extension, and an extension-less destination would silently
			// re-encode a PNG as a JPEG.
			$source_base = wp_tempnam( 'opencode-image-source' );
			if ( ! is_string( $source_base ) || '' === $source_base ) {
				return self::decode_error();
			}
			$source      = $source_base . '.' . $extension;
			$tmp_files[] = $source_base;
			$tmp_files[] = $source;
			// wp_get_image_editor() takes a real filesystem path, so the bytes
			// have to be on disk for it; WP_Filesystem is for the site's own
			// content, not for a scratch file this request creates and deletes.
			if ( false === file_put_contents( $source, $image_bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Scratch file the image editor must open by path.
				return self::decode_error();
			}

			if ( ! function_exists( 'wp_get_image_editor' ) ) {
				$includes = ABSPATH . 'wp-admin/includes/image.php';
				if ( is_readable( $includes ) ) {
					require_once $includes;
				}
			}
			if ( ! function_exists( 'wp_get_image_editor' ) ) {
				return self::decode_error();
			}

			$editor = wp_get_image_editor( $source );
			if ( ( function_exists( 'is_wp_error' ) && is_wp_error( $editor ) ) || ! is_object( $editor ) ) {
				return self::decode_error();
			}

			$target_base = wp_tempnam( 'opencode-image-saved' );
			if ( ! is_string( $target_base ) || '' === $target_base ) {
				return self::decode_error();
			}
			$target      = $target_base . '.' . $extension;
			$tmp_files[] = $target_base;
			$tmp_files[] = $target;

			// The MIME type is passed explicitly as well, so the output format
			// does not depend on either an extension or a per-editor default.
			$saved = $editor->save( $target, $detected_mime );
			if ( ! is_array( $saved ) ) {
				return self::decode_error();
			}
			$saved_path  = isset( $saved['path'] ) && is_string( $saved['path'] ) ? $saved['path'] : $target;
			$raw_encoded = is_readable( $saved_path ) ? file_get_contents( $saved_path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local scratch file the image editor just wrote, not a URL.
			$encoded     = is_string( $raw_encoded ) ? $raw_encoded : '';
			if ( '' === $encoded ) {
				return self::decode_error();
			}
			if ( ! self::is_within_size_limit( strlen( $encoded ) ) ) {
				return new \WP_Error(
					'opencode_image_size',
					sprintf(
						/* translators: %d: maximum size in bytes. */
						__( 'The generated image exceeds the %d byte limit.', 'duoport-connect-for-opencode' ),
						self::MAX_BYTES
					)
				);
			}
			// The re-encoded buffer is the new ground truth: re-identify it and
			// require that it is still the format it claims to be, so a decoder
			// that emitted something unexpected cannot be written under a .png
			// name just because the input was a PNG.
			$encoded_mime = self::detect_mime( $encoded ) ?? self::structural_mime( $encoded );
			if ( null === $encoded_mime || $encoded_mime !== $detected_mime ) {
				return self::decode_error();
			}
			return $encoded;
		} catch ( \Throwable ) {
			return self::decode_error();
		} finally {
			foreach ( $tmp_files as $tmp_file ) {
				// Both paths out of the re-encode are scratch files this request
				// created, including on the failure paths; leaving one behind on
				// every failed decode would be its own slow leak.
				if ( is_string( $tmp_file ) && is_file( $tmp_file ) ) {
					unlink( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Scratch file created by wp_tempnam() in this method.
				}
			}
		}
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
	 * extension and the registered `post_mime_type` are derived from the
	 * decoded format rather than from the caller's claim.
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

		$extension = ImageMime::extensionFor( $decoded_mime );
		$base      = sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) );
		if ( '' === $base ) {
			$base = 'opencode-image';
		}
		$upload = wp_upload_bits( $base . '.' . $extension, null, $encoded );
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
				'post_mime_type' => $decoded_mime,
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
