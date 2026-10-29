<?php
/**
 * Probe-model drift detector.
 *
 * Detector half of the ConnectionDiagnostics split: owns isProbeModelDrift()
 * so the classifier file keeps classify() plus factories and bucket
 * predicates only.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */

declare(strict_types=1);

namespace OpenCodeConnector\Availability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether a response names the probe model as the unavailable thing.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */
final class ProbeModelDrift {
	/**
	 * Whether a response is attributable to the probe model being unavailable.
	 *
	 * Every rule needs POSITIVE model evidence (model-scoped code, param
	 * model, or probe-model name plus a gone phrase) and is vetoed by a
	 * credential-scoped code or credential phrase in the message.
	 *
	 * @since 0.1.8
	 *
	 * @param array<string, mixed>|null $data        Response data.
	 * @param string|null               $probe_model Model the probe asked for, or null for no probe context.
	 * @return bool
	 */
	public static function isDrift( ?array $data, ?string $probe_model ): bool {
		if ( null === $probe_model || '' === trim( $probe_model ) || ! is_array( $data ) || ! isset( $data['error'] ) || ! is_array( $data['error'] ) ) {
			return false;
		}
		$error   = $data['error'];
		$code    = ErrorVocabulary::normalize( self::stringField( $error, 'code' ) );
		$type    = ErrorVocabulary::normalize( self::stringField( $error, 'type' ) );
		$message = ErrorVocabulary::normalize( self::stringField( $error, 'message' ) );

		if ( in_array( $code, ErrorVocabulary::CREDENTIAL_SCOPED_ERROR_CODES, true )
			|| in_array( $type, ErrorVocabulary::CREDENTIAL_SCOPED_ERROR_CODES, true )
			|| ErrorVocabulary::containsAnyOf( $message, ErrorVocabulary::CREDENTIAL_SCOPED_PHRASES ) ) {
			return false;
		}

		if ( 'model' === ErrorVocabulary::normalize( self::stringField( $error, 'param' ) ) ) {
			return true;
		}

		if ( in_array( $code, ErrorVocabulary::MODEL_SCOPED_ERROR_CODES, true ) || in_array( $type, ErrorVocabulary::MODEL_SCOPED_ERROR_CODES, true ) ) {
			return true;
		}

		if ( '' === $message || ! str_contains( $message, ErrorVocabulary::normalize( $probe_model ) ) ) {
			return false;
		}
		return ErrorVocabulary::containsAnyOf( $message, ErrorVocabulary::MODEL_GONE_PHRASES );
	}

	/**
	 * Read one error field only when it is a string.
	 *
	 * A decoded gateway body is untrusted input: `error.type`, `code`,
	 * `message` and `param` may be arrays or objects. Casting those directly
	 * emits an "Array to string conversion" warning (normalising to the
	 * literal "Array") or throws for an object without `__toString`, which
	 * aborts the credential classifier. Non-string values are ignored so
	 * malformed data cannot alter the verdict.
	 *
	 * @param array<string, mixed> $error Error object.
	 * @param string               $key   Field name.
	 * @return string Field value, or an empty string when absent or non-string.
	 */
	private static function stringField( array $error, string $key ): string {
		$value = $error[ $key ] ?? '';
		return is_string( $value ) ? $value : '';
	}
}
