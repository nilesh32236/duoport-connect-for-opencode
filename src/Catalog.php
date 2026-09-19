<?php
/**
 * Catalog registry: single source of truth for Go/Zen wiring.
 *
 * Centralizes the catalog slugs, availability-transient keys, and the
 * catalog-to-class maps (providers, model metadata directories, models) so
 * adding a third catalog — or renaming a key — changes one file instead of
 * coordinated edits across Providers, Availability, Metadata, Settings, and
 * the entry file.
 *
 * Credential-blind and option-blind: pure maps only, never reads options.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */

declare(strict_types=1);

namespace OpenCodeConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catalog registry for the Go/Zen catalogs.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */
final class Catalog {
	/**
	 * Go catalog slug.
	 *
	 * @since 0.1.4
	 */
	public const GO = 'go';

	/**
	 * Zen catalog slug.
	 *
	 * @since 0.1.4
	 */
	public const ZEN = 'zen';

	/**
	 * Availability transient key prefix.
	 *
	 * @since 0.1.4
	 */
	public const AVAIL_PREFIX = 'opencode_connector_avail_';

	/**
	 * Catalog slug to provider class.
	 *
	 * @var array<string, class-string>
	 */
	private const PROVIDERS = array(
		self::GO  => \OpenCodeConnector\Providers\OpenCodeGoProvider::class,
		self::ZEN => \OpenCodeConnector\Providers\OpenCodeZenProvider::class,
	);

	/**
	 * Catalog slug to model metadata directory class.
	 *
	 * @var array<string, class-string>
	 */
	private const DIRECTORIES = array(
		self::GO  => \OpenCodeConnector\Metadata\OpenCodeGoModelMetadataDirectory::class,
		self::ZEN => \OpenCodeConnector\Metadata\OpenCodeZenModelMetadataDirectory::class,
	);

	/**
	 * Catalog slug to capability to model class.
	 *
	 * @var array<string, array<string, class-string>>
	 */
	private const MODELS = array(
		self::GO  => array(
			'text-generation'  => \OpenCodeConnector\Models\OpenCodeGoTextGenerationModel::class,
			'image-generation' => \OpenCodeConnector\Models\OpenCodeGoImageGenerationModel::class,
		),
		self::ZEN => array(
			'text-generation'  => \OpenCodeConnector\Models\OpenCodeZenTextGenerationModel::class,
			'image-generation' => \OpenCodeConnector\Models\OpenCodeZenImageGenerationModel::class,
		),
	);

	/**
	 * All known catalog slugs.
	 *
	 * @since 0.1.4
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::GO, self::ZEN );
	}

	/**
	 * Whether a catalog slug is known.
	 *
	 * @since 0.1.4
	 *
	 * @param string $catalog Catalog slug.
	 * @return bool
	 */
	public static function is_valid( string $catalog ): bool {
		return in_array( $catalog, self::all(), true );
	}

	/**
	 * Throw on unknown catalog slugs.
	 *
	 * @since 0.1.4
	 *
	 * @param string $catalog Catalog slug.
	 * @return void
	 * @throws \InvalidArgumentException When the catalog is unknown.
	 */
	public static function require_valid( string $catalog ): void {
		if ( ! self::is_valid( $catalog ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
			throw new \InvalidArgumentException( 'Unknown OpenCode catalog: ' . $catalog );
		}
	}

	/**
	 * Availability transient key for a catalog.
	 *
	 * @since 0.1.4
	 *
	 * @param string $catalog Catalog slug.
	 * @return string
	 * @throws \InvalidArgumentException When the catalog is unknown.
	 */
	public static function transient_key( string $catalog ): string {
		self::require_valid( $catalog );
		return self::AVAIL_PREFIX . $catalog;
	}

	/**
	 * Provider class for a catalog.
	 *
	 * @since 0.1.4
	 *
	 * @param string $catalog Catalog slug.
	 * @return class-string
	 */
	public static function provider_class( string $catalog ): string {
		self::require_valid( $catalog );
		return self::PROVIDERS[ $catalog ];
	}

	/**
	 * Model metadata directory class for a catalog.
	 *
	 * @since 0.1.4
	 *
	 * @param string $catalog Catalog slug.
	 * @return class-string
	 */
	public static function directory_class( string $catalog ): string {
		self::require_valid( $catalog );
		return self::DIRECTORIES[ $catalog ];
	}

	/**
	 * Model class for a catalog + capability.
	 *
	 * @since 0.1.4
	 *
	 * @param string $catalog    Catalog slug.
	 * @param string $capability Capability slug (text-generation|image-generation).
	 * @return class-string
	 * @throws \InvalidArgumentException When the catalog or capability is unknown.
	 */
	public static function model_class( string $catalog, string $capability ): string {
		self::require_valid( $catalog );
		if ( ! isset( self::MODELS[ $catalog ][ $capability ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
			throw new \InvalidArgumentException( 'Unknown OpenCode capability: ' . $capability );
		}
		return self::MODELS[ $catalog ][ $capability ];
	}

	/**
	 * All model metadata directory classes.
	 *
	 * @since 0.1.4
	 *
	 * @return list<class-string>
	 */
	public static function directory_classes(): array {
		return array_values( self::DIRECTORIES );
	}
}
