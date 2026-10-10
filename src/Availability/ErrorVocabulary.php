<?php
/**
 * Gateway error vocabulary.
 *
 * Vocabulary half of the ConnectionDiagnostics split: the model/credential
 * code and phrase lists plus normalize()/containsAnyOf() live here so
 * vocabulary growth never forces edits inside the classifier file.
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
 * Owns the gateway error vocabulary lists and matching helpers.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */
final class ErrorVocabulary {
	/**
	 * Error type/code values naming a MODEL as the missing thing.
	 *
	 * @var list<string>
	 */
	public const MODEL_SCOPED_ERROR_CODES = array(
		'modelnotfound',
		'unknownmodel',
		'unknownmodelerror',
		'modelnotavailable',
		'modelunavailable',
		'modelretired',
		'retiredmodelerror',
		'invalidmodelerror',
		'deprecatedmodelerror',
		'unsupportedmodel',
		'unsupportedmodelerror',
	);

	/**
	 * Error type/code values naming the CREDENTIAL as rejected.
	 *
	 * @var list<string>
	 */
	public const CREDENTIAL_SCOPED_ERROR_CODES = array(
		'invalidapikey',
		'invalidkey',
		'unauthorized',
		'authenticationerror',
		'authenticationfailed',
		'invalidtoken',
		'expiredtoken',
		'invalidauthorization',
	);

	/**
	 * Message fragments meaning the CREDENTIAL is rejected.
	 *
	 * @var list<string>
	 */
	public const CREDENTIAL_SCOPED_PHRASES = array(
		'invalidapikey',
		'incorrectapikey',
		'unrecognizedapikey',
		'unrecognisedapikey',
		'invalidkey',
		'badapikey',
		'missingapikey',
		'noapikey',
		'apikeynotfound',
		'unauthorized',
		'unauthorised',
		'authenticationfailed',
		'authenticationerror',
		'invalidtoken',
		'expiredtoken',
		'invalidauthorization',
	);

	/**
	 * Message fragments meaning the MODEL is gone.
	 *
	 * @var list<string>
	 */
	public const MODEL_GONE_PHRASES = array(
		'does not exist',
		'not found',
		'no such model',
		'unknown model',
		'is not available',
		'no longer available',
		'not available for this key',
		'do not have access',
		'does not have access',
		'has been deprecated',
		'is deprecated',
		'has been retired',
		'is retired',
	);

	/**
	 * Reduce an error identifier to comparable form.
	 *
	 * @since 0.1.8
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize( string $value ): string {
		return strtolower( (string) preg_replace( '/[^a-zA-Z0-9]/', '', $value ) );
	}

	/**
	 * Whether a normalised string contains any normalised phrase.
	 *
	 * @since 0.1.8
	 *
	 * @param string   $message Normalised message.
	 * @param string[] $phrases Raw phrases, normalised on the fly.
	 * @return bool
	 */
	public static function containsAnyOf( string $message, array $phrases ): bool {
		if ( '' === $message ) {
			return false;
		}
		foreach ( $phrases as $phrase ) {
			if ( str_contains( $message, self::normalize( $phrase ) ) ) {
				return true;
			}
		}
		return false;
	}
}
