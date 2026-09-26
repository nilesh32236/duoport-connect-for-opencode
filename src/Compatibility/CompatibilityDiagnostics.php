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

		$issues = array_merge( $issues, $this->checkWordPress( $wordpress_version ) );
		if ( ! $sdk_available ) {
			$issues[] = 'missing-ai-client-sdk';
		}

		$registry_available = false;
		$registry_methods   = array();
		if ( $sdk_available ) {
			$checked            = $this->checkRegistry( $registry );
			$registry           = $checked['registry'];
			$registry_available = $checked['available'];
			$registry_methods   = $checked['methods'];
			$issues             = array_merge( $issues, $checked['issues'] );
		}

		$providers = array();
		if ( $registry_available && is_object( $registry ) && method_exists( $registry, 'hasProvider' ) ) {
			$checked   = $this->checkProviders( $registry );
			$providers = $checked['providers'];
			$issues    = array_merge( $issues, $checked['issues'] );
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
	 * Check the WordPress version surface.
	 *
	 * @since 0.1.7
	 *
	 * @param string $wordpress_version WordPress version string.
	 * @return list<string> Issue codes.
	 */
	private function checkWordPress( string $wordpress_version ): array {
		if ( ! preg_match( '/^\d+\.\d+(?:\.\d+)?$/', $wordpress_version ) || version_compare( $wordpress_version, self::MINIMUM_WORDPRESS_VERSION, '<' ) ) {
			return array( 'unsupported-wordpress-version' );
		}
		return array();
	}

	/**
	 * Resolve the registry and check its method surface.
	 *
	 * @since 0.1.7
	 *
	 * @param object|null $registry Optional registry override for tests.
	 * @return array{registry: object|null, available: bool, methods: list<string>, issues: list<string>}
	 */
	private function checkRegistry( ?object $registry ): array {
		$issues = array();
		if ( null === $registry && class_exists( AiClient::class ) && method_exists( AiClient::class, 'defaultRegistry' ) ) {
			try {
				$registry = AiClient::defaultRegistry();
			} catch ( \Throwable ) {
				$registry = null;
			}
		}
		$methods = array();
		if ( is_object( $registry ) ) {
			foreach ( array( 'hasProvider', 'registerProvider' ) as $method ) {
				if ( method_exists( $registry, $method ) ) {
					$methods[] = $method;
				}
			}
			if ( count( $methods ) < 2 ) {
				$issues[] = 'malformed-ai-client-registry';
			}
			return array(
				'registry'  => $registry,
				'available' => true,
				'methods'   => $methods,
				'issues'    => $issues,
			);
		}
		$issues[] = 'missing-ai-client-registry';
		return array(
			'registry'  => null,
			'available' => false,
			'methods'   => $methods,
			'issues'    => $issues,
		);
	}

	/**
	 * Check provider class existence and registration.
	 *
	 * @since 0.1.7
	 *
	 * @param object $registry Registry instance.
	 * @return array{providers: array<string, array<string, bool>>, issues: list<string>}
	 */
	private function checkProviders( object $registry ): array {
		$providers = array();
		$issues    = array();
		foreach ( $this->provider_classes as $provider_class ) {
			try {
				$class_exists = class_exists( $provider_class );
			} catch ( \Throwable ) {
				$class_exists = false;
			}
			$registered = false;
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
		return array(
			'providers' => $providers,
			'issues'    => $issues,
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
