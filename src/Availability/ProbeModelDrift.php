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
		$code    = ErrorVocabulary::normalize( (string) ( $error['code'] ?? '' ) );
		$type    = ErrorVocabulary::normalize( (string) ( $error['type'] ?? '' ) );
		$message = ErrorVocabulary::normalize( (string) ( $error['message'] ?? '' ) );

		if ( in_array( $code, ErrorVocabulary::CREDENTIAL_SCOPED_ERROR_CODES, true )
			|| in_array( $type, ErrorVocabulary::CREDENTIAL_SCOPED_ERROR_CODES, true )
			|| ErrorVocabulary::containsAnyOf( $message, ErrorVocabulary::CREDENTIAL_SCOPED_PHRASES ) ) {
			return false;
		}

		if ( 'model' === ErrorVocabulary::normalize( (string) ( $error['param'] ?? '' ) ) ) {
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
}
