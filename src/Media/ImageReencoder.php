<?php
/**
 * Image re-encoder.
 *
 * Re-encode half of the ImageAttachmentSaver split: decoding the image and
 * encoding it back out so only decoded pixels survive, with temp-file,
 * editor, and output-identity steps isolated from validation and upload.
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
 * Re-encodes image payloads so stored bytes are decoded pixels only.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */
final class ImageReencoder {
	/**
	 * Maximum decoded pixel count (25 megapixels), mirroring the saver cap.
	 *
	 * @since 0.1.8
	 *
	 * @var int
	 */
	public const MAX_PIXELS = 25000000;

	/**
	 * Re-encode a payload so only decoded image data survives. Fail closed.
	 *
	 * @since 0.1.8
	 *
	 * @param string $image_bytes   Raw image bytes.
	 * @param string $detected_mime MIME type identified from the content.
	 * @return array{bytes:string,mime:string}|\WP_Error Re-encoded bytes plus MIME, or WP_Error.
	 */
	public static function reencode( string $image_bytes, string $detected_mime ): array|\WP_Error {
		if ( ! ImageAttachmentSaver::is_allowed_mime( $detected_mime ) ) {
			return self::decodeError();
		}
		$probe = ImageValidator::headerProbe( $image_bytes );
		if ( null === $probe ) {
			return self::decodeError();
		}
		if ( $probe['width'] * $probe['height'] > ImageAttachmentSaver::MAX_PIXELS ) {
			return self::decodeError();
		}
		$tmp_files = array();
		$extension = ImageMime::extensionFor( $detected_mime );

		try {
			$source = self::writeSourceTemp( $image_bytes, $extension, $tmp_files );
			if ( $source instanceof \WP_Error ) {
				return $source;
			}
			$editor = self::editorForSource( $source );
			if ( $editor instanceof \WP_Error ) {
				return $editor;
			}
			$saved = self::saveViaEditor( $editor, $extension, $detected_mime, $tmp_files );
			if ( $saved instanceof \WP_Error ) {
				return $saved;
			}
			return self::verifyReencodedOutput( $saved['saved'], $saved['actual_target'] );
		} catch ( \Throwable ) {
			return self::decodeError();
		} finally {
			foreach ( $tmp_files as $tmp_file ) {
				if ( is_string( $tmp_file ) && is_file( $tmp_file ) ) {
					unlink( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Scratch file created by wp_tempnam() in this method.
				}
			}
		}
	}

	/**
	 * Write the source buffer to a scratch file with its extension restored.
	 *
	 * @since 0.1.8
	 *
	 * @param string   $image_bytes Raw image bytes.
	 * @param string   $extension   Source extension.
	 * @param string[] $tmp_files   Scratch paths (updated in place).
	 * @return string|\WP_Error Source path, or WP_Error on any failure.
	 */
	private static function writeSourceTemp( string $image_bytes, string $extension, array &$tmp_files ): string|\WP_Error {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			return self::decodeError();
		}
		$source_base = wp_tempnam( 'opencode-image-source' );
		if ( ! is_string( $source_base ) || '' === $source_base ) {
			return self::decodeError();
		}
		$source      = $source_base . '.' . $extension;
		$tmp_files[] = $source_base;
		$tmp_files[] = $source;
		if ( false === file_put_contents( $source, $image_bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Scratch file the image editor must open by path.
			return self::decodeError();
		}
		return $source;
	}

	/**
	 * Resolve an image editor for a source path.
	 *
	 * @since 0.1.8
	 *
	 * @param string $source Source scratch path.
	 * @return mixed Editor instance, or WP_Error when unavailable.
	 */
	private static function editorForSource( string $source ): mixed {
		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			$includes = ABSPATH . 'wp-admin/includes/image.php';
			if ( is_readable( $includes ) ) {
				require_once $includes;
			}
		}
		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			return self::decodeError();
		}
		$editor = wp_get_image_editor( $source );
		if ( ( function_exists( 'is_wp_error' ) && is_wp_error( $editor ) ) || ! is_object( $editor ) ) {
			return self::decodeError();
		}
		return $editor;
	}

	/**
	 * Save the editor output to a scratch target.
	 *
	 * @since 0.1.8
	 *
	 * @param object   $editor        Image editor.
	 * @param string   $extension     Target extension.
	 * @param string   $detected_mime Input MIME type.
	 * @param string[] $tmp_files     Scratch paths (updated in place).
	 * @return array{saved: array, actual_target: string}|\WP_Error Save result, or WP_Error on failure.
	 */
	private static function saveViaEditor( object $editor, string $extension, string $detected_mime, array &$tmp_files ): array|\WP_Error {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			return self::decodeError();
		}
		$target_base = wp_tempnam( 'opencode-image-saved' );
		if ( ! is_string( $target_base ) || '' === $target_base ) {
			return self::decodeError();
		}
		$target      = $target_base . '.' . $extension;
		$tmp_files[] = $target_base;
		$tmp_files[] = $target;
		$saved       = $editor->save( $target, $detected_mime );
		if ( ! is_array( $saved ) ) {
			return self::decodeError();
		}
		$actual_target = $target;
		if ( isset( $saved['path'] ) && is_string( $saved['path'] ) && '' !== $saved['path'] ) {
			$actual_target = $saved['path'];
		}
		if ( ! in_array( $actual_target, $tmp_files, true ) ) {
			$tmp_files[] = $actual_target;
		}
		return array(
			'saved'         => $saved,
			'actual_target' => $actual_target,
		);
	}

	/**
	 * Verify the re-encoded output matches what the editor actually wrote.
	 *
	 * @since 0.1.8
	 *
	 * @param array  $saved         Raw editor save result.
	 * @param string $actual_target Editor's actual output path.
	 * @return array{bytes:string,mime:string}|\WP_Error Re-encoded bytes plus MIME, or WP_Error.
	 */
	private static function verifyReencodedOutput( array $saved, string $actual_target ): array|\WP_Error {
		$raw_encoded = is_readable( $actual_target ) ? file_get_contents( $actual_target ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local scratch file the image editor just wrote, not a URL.
		$encoded     = is_string( $raw_encoded ) ? $raw_encoded : '';
		if ( '' === $encoded ) {
			return self::decodeError();
		}
		if ( ! ImageAttachmentSaver::is_within_size_limit( strlen( $encoded ) ) ) {
			return new \WP_Error(
				'opencode_image_size',
				sprintf(
					/* translators: %d: maximum size in bytes. */
					__( 'The generated image exceeds the %d byte limit.', 'duoport-connect-for-opencode' ),
					ImageAttachmentSaver::MAX_BYTES
				)
			);
		}
		$reported_mime = null;
		if ( isset( $saved['mime-type'] ) && is_string( $saved['mime-type'] ) ) {
			$reported      = strtolower( trim( $saved['mime-type'] ) );
			$reported_mime = '' === $reported ? null : $reported;
		}
		$path_mime    = ImageMime::mimeForExtension( (string) pathinfo( $actual_target, PATHINFO_EXTENSION ) );
		$encoded_mime = ImageValidator::contentMime( $encoded );
		if ( null === $encoded_mime || ! ImageAttachmentSaver::is_allowed_mime( $encoded_mime ) ) {
			return self::decodeError();
		}
		if ( $encoded_mime !== $reported_mime && $encoded_mime !== $path_mime ) {
			return self::decodeError();
		}
		return array(
			'bytes' => $encoded,
			'mime'  => $encoded_mime,
		);
	}

	/**
	 * Shared decode-failure error.
	 *
	 * @since 0.1.8
	 *
	 * @return \WP_Error
	 */
	private static function decodeError(): \WP_Error {
		return new \WP_Error(
			'opencode_image_decode',
			__( 'The generated image could not be decoded, so it was not saved.', 'duoport-connect-for-opencode' )
		);
	}
}
