<?php
/**
 * Small, credential-blind compatibility checks.
 *
 * @package OpenCodeConnector
 * @since 0.1.5
 */

declare(strict_types=1);

namespace OpenCodeConnector\Compatibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\AiClient;
use OpenCodeConnector\Providers\OpenCodeGoProvider;
use OpenCodeConnector\Providers\OpenCodeZenProvider;

/**
 * Reports runtime compatibility without reading connector settings.
 */
final class CompatibilityDiagnostics {

	/**
	 * Provider classes inspected by the diagnostic.
	 *
	 * @var string[]
	 */
	private array $provider_classes;

	/**
	 * Minimum supported WordPress version.
	 */
	private const MINIMUM_WORDPRESS_VERSION = '7.0';

	/**
	 * Build a diagnostic with the default provider surface.
	 *
	 * @param string[]|null $provider_classes Optional provider classes for isolated tests.
	 */
	public function __construct( ?array $provider_classes = null ) {
		$this->provider_classes = $provider_classes ?? array( OpenCodeGoProvider::class, OpenCodeZenProvider::class );
	}

	/**
	 * Inspect the current WordPress/AI Client/provider surface.
	 *
	 * The optional arguments are a small test seam; production callers use
	 * no arguments. No connector option or credential API is called.
	 *
	 * @param object|null $registry Optional registry override for tests.
	 * @param string|null $wordpress_version Optional WordPress version override for tests.
	 * @return array<string, mixed>
	 */
	public function inspect( ?object $registry = null, ?string $wordpress_version = null ): array {
		$wordpress_version = $wordpress_version ?? $this->get_wordpress_version();
		$sdk_available     = class_exists( AiClient::class ) || null !== $registry;
		$issues            = array();

		if ( ! preg_match( '/^\d+\.\d+(?:\.\d+)?$/', $wordpress_version ) || version_compare( $wordpress_version, self::MINIMUM_WORDPRESS_VERSION, '<' ) ) {
			$issues[] = 'unsupported-wordpress-version';
		}
		if ( ! $sdk_available ) {
			$issues[] = 'missing-ai-client-sdk';
		}

		$registry_available = false;
		$registry_methods   = array();
		if ( $sdk_available ) {
			if ( null === $registry && class_exists( AiClient::class ) && method_exists( AiClient::class, 'defaultRegistry' ) ) {
				try {
					$registry = AiClient::defaultRegistry();
				} catch ( \Throwable ) {
					$registry = null;
				}
			}
			if ( is_object( $registry ) ) {
				$registry_available = true;
				foreach ( array( 'hasProvider', 'registerProvider' ) as $method ) {
					if ( method_exists( $registry, $method ) ) {
						$registry_methods[] = $method;
					}
				}
				if ( count( $registry_methods ) < 2 ) {
					$issues[] = 'malformed-ai-client-registry';
				}
			} else {
				$issues[] = 'missing-ai-client-registry';
			}
		}

		$providers = array();
		if ( $registry_available && method_exists( $registry, 'hasProvider' ) ) {
			foreach ( $this->provider_classes as $provider_class ) {
				$class_exists = class_exists( $provider_class );
				$registered   = false;
				if ( $class_exists ) {
					try {
						$registered = (bool) $registry->hasProvider( $provider_class );
					} catch ( \Throwable ) {
						$registered = false;
					}
				}
				$providers[ $provider_class ] = array(
					'class_exists' => $class_exists,
					'registered'   => $registered,
				);
				if ( ! $class_exists || ! $registered ) {
					$issues[] = 'provider-not-registered';
				}
			}
		}

		return array(
			'wordpress_version'   => $wordpress_version,
			'wordpress_supported' => version_compare( $wordpress_version, self::MINIMUM_WORDPRESS_VERSION, '>=' ),
			'sdk_available'       => $sdk_available,
			'registry_available'  => $registry_available,
			'registry_methods'    => $registry_methods,
			'providers'           => $providers,
			'issues'              => array_values( array_unique( $issues ) ),
			'ok'                  => array() === $issues,
		);
	}

	/**
	 * Read only the public WordPress version, never connector settings.
	 *
	 * @return string
	 */
	private function get_wordpress_version(): string {
		return function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '0.0.0';
	}
}
