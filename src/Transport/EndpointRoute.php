<?php
/**
 * Explicit endpoint-family routing for verified model records.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Transport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Metadata\ModelRegistry;

/**
 * Resolves only reviewed endpoint families and paths.
 *
 * Each family listed in PATHS has a verified payload parser (see the
 * sibling *PayloadParser classes) and a verified authentication contract
 * (see EndpointAuth): routing a family means its request shape and its
 * credential header are both evidenced. Families outside the map fail
 * closed before transport, whether or not they are named in
 * `ModelRegistry::isUnsupportedFamily()`.
 */
final class EndpointRoute {

	/**
	 * Supported families, in the order they are reported in denials.
	 *
	 * @var list<string>
	 */
	private const SUPPORTED_FAMILIES = array( 'chat', 'responses', 'messages', 'gemini', 'systemone' );

	/**
	 * Implemented family-to-path map.
	 *
	 * This verified allowlist is the enforcement point: every family that
	 * is not a key here fails closed before transport, whether or not it
	 * is named in `ModelRegistry::isUnsupportedFamily()`.
	 * Provider-specific transports remain denied until their payload,
	 * parser, and authentication contracts are complete.
	 *
	 * The Gemini path is model-scoped (`models/{model}:generateContent`):
	 * pathForModel() substitutes the model ID, while pathForEndpointKind()
	 * reports the template.
	 *
	 * @var array<string, string>
	 */
	private const PATHS = array(
		'chat'      => 'chat/completions',
		'responses' => 'responses',
		'messages'  => 'messages',
		'gemini'    => 'models/{model}:generateContent',
		'systemone' => 'systemone',
	);

	/**
	 * Whether an endpoint family has a verified route, parser, and auth contract.
	 *
	 * Single owner for the "routable" vocabulary: EndpointAuth and
	 * CapabilityAwareFallback delegate here, so a family the router can
	 * send cannot be rejected by one of its consumers.
	 *
	 * @param string $family Endpoint family name.
	 * @return bool
	 */
	public static function isVerifiedFamily( string $family ): bool {
		return isset( self::PATHS[ $family ] );
	}

	/**
	 * Build the fail-closed message for a rejected endpoint family.
	 *
	 * The registry sentinel means "this model's documented family is not
	 * implemented", which is a different fact from a named family, so it gets
	 * its own message instead of quoting the sentinel back to the caller.
	 *
	 * @param string $kind Endpoint family name.
	 * @return never
	 * @throws UnsupportedEndpointFamilyException Always.
	 */
	private static function rejectFamily( string $kind ): never {
		$supported = implode( ', ', self::SUPPORTED_FAMILIES );
		if ( ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED === $kind ) {
			$message = sprintf( 'This model\'s documented endpoint family is not implemented by this adapter; supported families: %s.', $supported );
		} elseif ( ModelRegistry::isUnsupportedFamily( $kind ) ) {
			$message = sprintf( 'Endpoint family "%s" is unsupported; supported families: %s.', $kind, $supported );
		} else {
			// Unknown family: the verified allowlist still fails closed, but do
			// not echo an unrecognised name back to the caller.
			$message = sprintf( 'Endpoint family is unsupported; supported families: %s.', $supported );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
		throw new UnsupportedEndpointFamilyException( $message );
	}

	/**
	 * Resolve a model record's endpoint kind or fail before transport.
	 *
	 * @param string $model_id Model ID.
	 * @param string $catalog  Catalog slug.
	 * @return string
	 * @throws UnsupportedEndpointFamilyException When the model or family is not verified.
	 */
	public static function endpointKindForModel( string $model_id, string $catalog ): string {
		$record = ModelRegistry::record( $model_id, $catalog );
		if ( null === $record ) {
			throw new UnsupportedEndpointFamilyException( 'Model is not in the verified registry.' );
		}
		$kind = (string) ( $record['endpoint_family'] ?? '' );
		if ( ! isset( self::PATHS[ $kind ] ) ) {
			self::rejectFamily( $kind );
		}
		return $kind;
	}

	/**
	 * Resolve a path for a model.
	 *
	 * The Gemini path is model-scoped, so the model ID is substituted
	 * into the template there; every other verified family has a fixed
	 * path.
	 *
	 * @param string $model_id Model ID.
	 * @param string $catalog  Catalog slug.
	 * @return string
	 * @throws UnsupportedEndpointFamilyException When the route is unsupported.
	 */
	public static function pathForModel( string $model_id, string $catalog ): string {
		$kind = self::endpointKindForModel( $model_id, $catalog );
		if ( 'gemini' === $kind ) {
			return self::pathForGeminiModel( $model_id );
		}
		return self::PATHS[ $kind ];
	}

	/**
	 * Resolve the model-scoped Gemini generateContent path for a model.
	 *
	 * Factored out of pathForModel() so the substitution is directly
	 * pinnable: no allowlisted model maps to the Gemini family yet, so
	 * this is the only seam that exercises the template.
	 *
	 * @param string $model_id Model ID.
	 * @return string
	 */
	public static function pathForGeminiModel( string $model_id ): string {
		return 'models/' . rawurlencode( $model_id ) . ':generateContent';
	}

	/**
	 * Return a path for a known endpoint kind.
	 *
	 * The Gemini kind reports its model-scoped template
	 * (`models/{model}:generateContent`); use pathForModel() (or
	 * pathForGeminiModel()) for a sendable Gemini path.
	 *
	 * @param string $kind Endpoint kind.
	 * @return string
	 * @throws UnsupportedEndpointFamilyException When the endpoint kind is unsupported.
	 */
	public static function pathForEndpointKind( string $kind ): string {
		if ( ! isset( self::PATHS[ $kind ] ) ) {
			self::rejectFamily( $kind );
		}
		return self::PATHS[ $kind ];
	}
}
