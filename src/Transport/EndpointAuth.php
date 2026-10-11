<?php
/**
 * Per-family credential-header contract for verified endpoint families.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Transport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Documents which credential header each verified endpoint family needs.
 *
 * Single owner for the family-to-credential vocabulary, so the request path
 * cannot route a family whose authentication contract is unknown: every
 * method here fails closed with UnsupportedEndpointFamilyException for an
 * unverified family, before any HTTP is sent. The key material itself always
 * flows through the SDK request authentication layer; this class only names
 * the header the gateway expects per family (per the OpenCode route
 * handlers) and applies the non-credential family headers (Anthropic
 * version). Credential-blind and option-blind by construction.
 */
final class EndpointAuth {

	/**
	 * Anthropic API version the Messages family is served with.
	 *
	 * The server's Anthropic adapter applies this default; the client sends
	 * it explicitly so the contract does not depend on a server default.
	 *
	 * @var string
	 */
	public const ANTHROPIC_VERSION = '2023-06-01';

	/**
	 * Credential header expected per verified endpoint family.
	 *
	 * Bearer serves the OpenAI Responses, OpenAI-compatible chat
	 * completions, and SystemOne families; the Anthropic Messages family is
	 * served with `x-api-key`; the native Gemini family is served with
	 * `x-goog-api-key`.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMES = array(
		'chat'      => 'Authorization: Bearer',
		'responses' => 'Authorization: Bearer',
		'systemone' => 'Authorization: Bearer',
		'messages'  => 'x-api-key',
		'gemini'    => 'x-goog-api-key',
	);

	/**
	 * Name the credential header a verified family is served with.
	 *
	 * @param string $family Endpoint family name.
	 * @return string Credential header description for the family.
	 * @throws UnsupportedEndpointFamilyException When the family is not verified.
	 */
	public static function schemeForFamily( string $family ): string {
		if ( ! EndpointRoute::isVerifiedFamily( $family ) || ! isset( self::SCHEMES[ $family ] ) ) {
			throw new UnsupportedEndpointFamilyException( 'Endpoint family is unsupported; no verified authentication contract.' );
		}

		return self::SCHEMES[ $family ];
	}

	/**
	 * Apply the non-credential family headers for a verified family.
	 *
	 * The Messages family carries the Anthropic version header; every other
	 * verified family needs no extra header because the credential itself is
	 * applied later by the SDK request authentication layer. Fail-open for
	 * header failures, fail-closed for unverified families (which throw
	 * before transport, never misrouted). Never reads options or keys.
	 *
	 * @param array  $headers Request headers.
	 * @param string $family  Endpoint family name.
	 * @return array Headers with the family headers added when applicable.
	 * @throws UnsupportedEndpointFamilyException When the family is not verified.
	 */
	public static function applyFamilyHeaders( array $headers, string $family ): array {
		// Fail-closed first: an unverified family must never reach the send.
		self::schemeForFamily( $family );

		try {
			if ( 'messages' === $family && ! self::has_header( $headers, 'anthropic-version' ) ) {
				$headers['anthropic-version'] = self::ANTHROPIC_VERSION;
			}
		} catch ( \Throwable ) {
			// Fail-open: header failures fall back to a headerless send.
			return $headers;
		}

		return $headers;
	}

	/**
	 * Whether a headers array already carries a header name.
	 *
	 * Compared case-insensitively: HTTP header names are case-insensitive,
	 * so a caller-supplied `Anthropic-Version` must not gain a duplicate.
	 *
	 * @param array  $headers Request headers.
	 * @param string $name    Header name to look for.
	 * @return bool
	 */
	private static function has_header( array $headers, string $name ): bool {
		foreach ( $headers as $key => $ignored ) {
			unset( $ignored );
			if ( is_string( $key ) && strtolower( $key ) === strtolower( $name ) ) {
				return true;
			}
		}

		return false;
	}
}
