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
 */
final class EndpointRoute {

	/**
	 * Implemented family-to-path map.
	 *
	 * Responses, Messages, and provider-specific transports remain denied
	 * until their payload, parser, and authentication contracts are complete.
	 *
	 * @var array<string, string>
	 */
	private const PATHS = array(
		'chat' => 'chat/completions',
	);

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
			throw new UnsupportedEndpointFamilyException( 'Model endpoint kind is unsupported.' );
		}
		return $kind;
	}

	/**
	 * Resolve a path for a model.
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
	 * @param string $kind Endpoint kind.
	 * @return string
	 * @throws UnsupportedEndpointFamilyException When the endpoint kind is unsupported.
	 */
	public static function pathForEndpointKind( string $kind ): string {
		if ( ! isset( self::PATHS[ $kind ] ) ) {
			throw new UnsupportedEndpointFamilyException( 'Endpoint kind is unsupported.' );
		}
		return self::PATHS[ $kind ];
	}
}
