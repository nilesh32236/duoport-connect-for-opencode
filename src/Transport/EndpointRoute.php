<?php
/**
 * Explicit endpoint-family routing for verified model records.
 *
 * @package OpenCodeConnector
 * @since 0.1.5
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
 * @since 0.1.5
 */
final class EndpointRoute {

	/**
	 * The single endpoint family this adapter implements.
	 *
	 * Single owner for the "implemented family" value: ModelRegistry and
	 * CapabilityAwareFallback derive their admission rules from here, so a
	 * future transport cannot leave the router and the selector disagreeing
	 * about what is implemented.
	 *
	 * @since 0.1.9
	 *
	 * @var string
	 */
	public const IMPLEMENTED_FAMILY = 'chat';

	/**
	 * Implemented family-to-path map.
	 *
	 * This chat-only allowlist is the enforcement point: every family that is
	 * not a key here fails closed before transport, whether or not it is
	 * named in `ModelRegistry::isUnsupportedFamily()`. Responses, Messages,
	 * and provider-specific transports remain denied until their payload,
	 * parser, and authentication contracts are complete.
	 *
	 * @since 0.1.5
	 *
	 * @var array<string, string>
	 */
	private const PATHS = array(
		self::IMPLEMENTED_FAMILY => 'chat/completions',
	);

	/**
	 * Families this adapter implements.
	 *
	 * Derived from PATHS keys so the map and the membership predicate cannot
	 * drift apart. ModelRegistry and CapabilityAwareFallback admit only these.
	 *
	 * @since 0.1.9
	 *
	 * @return list<string>
	 */
	public static function implementedFamilies(): array {
		return array_keys( self::PATHS );
	}

	/**
	 * Build the fail-closed message for a rejected endpoint family.
	 *
	 * The registry sentinel means "this model's documented family is not
	 * implemented", which is a different fact from a named family, so it gets
	 * its own message instead of quoting the sentinel back to the caller.
	 *
	 * @since 0.1.5
	 *
	 * @param string $kind Endpoint family name.
	 * @return never
	 * @throws UnsupportedEndpointFamilyException Always.
	 */
	private static function rejectFamily( string $kind ): never {
		if ( ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED === $kind ) {
			$message = 'This model\'s documented endpoint family is not implemented by this adapter; chat/completions only.';
		} elseif ( ModelRegistry::isUnsupportedFamily( $kind ) ) {
			$message = sprintf( 'Endpoint family "%s" is unsupported; chat/completions only.', $kind );
		} else {
			// Unknown family: the chat-only allowlist still fails closed, but do
			// not echo an unrecognised name back to the caller.
			$message = 'Endpoint family is unsupported; chat/completions only.';
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
		throw new UnsupportedEndpointFamilyException( $message );
	}

	/**
	 * Resolve a model record's endpoint kind or fail before transport.
	 *
	 * @since 0.1.5
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
	 * @since 0.1.5
	 *
	 * @param string $model_id Model ID.
	 * @param string $catalog  Catalog slug.
	 * @return string
	 * @throws UnsupportedEndpointFamilyException When the route is unsupported.
	 */
	public static function pathForModel( string $model_id, string $catalog ): string {
		return self::PATHS[ self::endpointKindForModel( $model_id, $catalog ) ];
	}

	/**
	 * Return a path for a known endpoint kind.
	 *
	 * @since 0.1.5
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
