<?php
/**
 * Model identity resolution.
 *
 * Identity half of the text-model split: owns SDK model-ID resolution
 * (accessors, reflection) and the catalog-key reverse map so the model
 * class keeps request routing and tools mapping only.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */

declare(strict_types=1);

namespace OpenCodeConnector\Models;

use OpenCodeConnector\Metadata\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the model ID and catalog key for the tool-capability gate.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */
final class ModelIdentityResolver {
	/**
	 * Resolve the model ID for the tool-capability gate.
	 *
	 * @since 0.1.8
	 *
	 * @param object $model Model instance.
	 * @return string Empty string when unresolvable. Never throws.
	 */
	public static function modelIdForToolGate( object $model ): string {
		try {
			$via_accessors = self::resolveViaAccessors( $model );
			if ( '' !== $via_accessors ) {
				return $via_accessors;
			}
			return self::resolveViaReflection( $model );
		} catch ( \Throwable ) {
			return '';
		}
	}

	/**
	 * Resolve the model ID via SDK metadata accessors.
	 *
	 * @since 0.1.8
	 *
	 * @param object $model Model instance.
	 * @return string Empty string when unresolvable. Never throws.
	 */
	public static function resolveViaAccessors( object $model ): string {
		foreach ( array( 'metadata', 'getModelMetadata', 'getMetadata', 'getModel', 'model' ) as $accessor ) {
			if ( ! method_exists( $model, $accessor ) ) {
				continue;
			}
			try {
				$metadata = $model->{$accessor}();
			} catch ( \Throwable ) {
				continue;
			}
			if ( ! is_object( $metadata ) || ! method_exists( $metadata, 'getId' ) ) {
				continue;
			}
			try {
				$id = (string) $metadata->getId();
			} catch ( \Throwable ) {
				continue;
			}
			if ( '' !== $id ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * Last-resort model ID resolution via reflection.
	 *
	 * @since 0.1.8
	 *
	 * @param object $model Model instance.
	 * @return string Empty string when unresolvable. Never throws.
	 */
	public static function resolveViaReflection( object $model ): string {
		try {
			$reflection = new \ReflectionObject( $model );
			foreach ( $reflection->getProperties() as $prop ) {
				try {
					$candidate = $prop->getValue( $model );
				} catch ( \Throwable ) {
					continue;
				}
				if ( is_object( $candidate ) && method_exists( $candidate, 'getId' ) ) {
					try {
						$id = (string) $candidate->getId();
					} catch ( \Throwable ) {
						continue;
					}
					if ( '' !== $id ) {
						return $id;
					}
				}
			}
		} catch ( \Throwable ) {
			return '';
		}
		return '';
	}

	/**
	 * Resolve the catalog key for the tool-capability gate.
	 *
	 * @since 0.1.8
	 *
	 * @param string $provider_class Provider class FQCN.
	 * @return string Catalog slug, or empty string when unresolvable.
	 */
	public static function catalogKeyForToolGate( string $provider_class ): string {
		try {
			if ( '' === $provider_class ) {
				return '';
			}
			return Catalog::catalogForProviderClass( $provider_class );
		} catch ( \Throwable ) {
			return '';
		}
	}
}
