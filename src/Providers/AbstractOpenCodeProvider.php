<?php
/**
 * Shared base for OpenCode providers.
 *
 * Provides shared base for OpenCode Go and Zen providers.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

namespace OpenCodeConnector\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Availability\OpenCodeProviderAvailability;
use OpenCodeConnector\Catalog;
use OpenCodeConnector\Metadata\OpenCodeGoModelMetadataDirectory;
use OpenCodeConnector\Metadata\OpenCodeZenModelMetadataDirectory;
use OpenCodeConnector\Models\OpenCodeGoImageGenerationModel;
use OpenCodeConnector\Models\OpenCodeGoTextGenerationModel;
use OpenCodeConnector\Models\OpenCodeZenImageGenerationModel;
use OpenCodeConnector\Models\OpenCodeZenTextGenerationModel;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Shared base for OpenCode Go and Zen providers.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */
abstract class AbstractOpenCodeProvider extends AbstractApiProvider {
	/**
	 * Provider ID.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	abstract protected static function providerId(): string;

	/**
	 * Catalog key.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	abstract protected static function catalogKey(): string;

	/**
	 * Display name.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	abstract protected static function displayName(): string;

	/**
	 * Description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	abstract protected static function description(): string;

	/**
	 * Catalog-to-class map consulted by the factories.
	 *
	 * Single wiring point for Go/Zen routing: subclasses/tests override this
	 * to substitute fakes instead of editing the base class or stubbing the
	 * whole SDK. Factories fall back to Catalog defaults for catalogs absent
	 * from the override.
	 *
	 * Shape: array( 'go' => array( 'text-generation' => class, ...,
	 * 'directory' => class ), 'zen' => array( ... ) ).
	 *
	 * @since 0.1.5
	 *
	 * @return array<string, array<string, class-string>>
	 */
	protected static function class_map(): array {
		return array(
			'go'  => array(
				'text-generation'  => OpenCodeGoTextGenerationModel::class,
				'image-generation' => OpenCodeGoImageGenerationModel::class,
				'directory'        => OpenCodeGoModelMetadataDirectory::class,
			),
			'zen' => array(
				'text-generation'  => OpenCodeZenTextGenerationModel::class,
				'image-generation' => OpenCodeZenImageGenerationModel::class,
				'directory'        => OpenCodeZenModelMetadataDirectory::class,
			),
		);
	}

	/**
	 * Resolve a model class for the current catalog + capability.
	 *
	 * @since 0.1.5
	 *
	 * @param string $capability Capability slug (text-generation|image-generation).
	 * @return class-string
	 */
	protected static function model_class_for( string $capability ): string {
		$map     = static::class_map();
		$catalog = static::catalogKey();
		if ( isset( $map[ $catalog ][ $capability ] ) ) {
			return $map[ $catalog ][ $capability ];
		}
		return Catalog::model_class( $catalog, $capability );
	}

	/**
	 * Create a model instance.
	 *
	 * @since 0.1.0
	 *
	 * @param ModelMetadata    $model    Model metadata.
	 * @param ProviderMetadata $provider Provider metadata.
	 * @return ModelInterface
	 * @throws \WordPress\AiClient\Common\Exception\RuntimeException When capability is unsupported.
	 */
	protected static function createModel( ModelMetadata $model, ProviderMetadata $provider ): ModelInterface {
		if ( ! method_exists( $model, 'getSupportedCapabilities' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
			throw new \WordPress\AiClient\Common\Exception\RuntimeException( 'Unsupported capability: unknown' );
		}
		$caps = $model->getSupportedCapabilities();
		foreach ( $caps as $capability ) {
			if ( self::capability_matches( $capability, 'isImageGeneration', 'image-generation' ) ) {
				$cls = static::model_class_for( 'image-generation' );
				return new $cls( $model, $provider );
			}
			if ( self::capability_matches( $capability, 'isTextGeneration', 'text-generation' ) ) {
				$cls = static::model_class_for( 'text-generation' );
				return new $cls( $model, $provider );
			}
		}
		$cap_names = array_map(
			static function ( $capability ): string {
				if ( is_object( $capability ) && method_exists( $capability, 'getValue' ) ) {
					return (string) $capability->getValue();
				}
				if ( is_string( $capability ) ) {
					return $capability;
				}
				return is_object( $capability ) ? get_class( $capability ) : get_debug_type( $capability );
			},
			$caps
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
		throw new \WordPress\AiClient\Common\Exception\RuntimeException( 'Unsupported capability: ' . implode( ', ', $cap_names ) );
	}

	/**
	 * Whether a capability value matches a text/image generation capability.
	 *
	 * The SDK exposes these checks as magic methods (__call/__callStatic on
	 * AbstractEnum), so method_exists() probing cannot see them — call the
	 * checker directly inside try/catch instead, then fall back to legacy
	 * getValue()/string shapes. Never throws.
	 *
	 * @since 0.1.4
	 *
	 * @param mixed  $capability Capability value.
	 * @param string $checker    Checker method name (e.g. isTextGeneration).
	 * @param string $value      Legacy scalar value (e.g. text-generation).
	 * @return bool
	 */
	private static function capability_matches( $capability, string $checker, string $value ): bool {
		if ( is_object( $capability ) ) {
			$direct = self::try_capability_checker( $capability, $checker );
			if ( null !== $direct ) {
				return $direct;
			}
			if ( method_exists( $capability, 'getValue' ) ) {
				try {
					return $value === (string) $capability->getValue();
				} catch ( \Throwable $e ) {
					return false;
				}
			}
		} elseif ( is_string( $capability ) ) {
			return $value === $capability;
		}
		return false;
	}

	/**
	 * Run a capability checker, translating failures to null.
	 *
	 * Unknown shapes and failing checkers yield null so callers fall back to
	 * legacy getValue()/string comparisons. Never throws.
	 *
	 * @since 0.1.4
	 *
	 * @param object $capability Capability object.
	 * @param string $checker    Checker method name (e.g. isTextGeneration).
	 * @return bool|null Match result, or null when unavailable.
	 */
	private static function try_capability_checker( object $capability, string $checker ): ?bool {
		try {
			$result = $capability->{$checker}();
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( null === $result ) {
			return null;
		}
		return (bool) $result;
	}

	/**
	 * Create provider metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return ProviderMetadata
	 * @throws \RuntimeException When the SDK enum factories are unavailable.
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		// NOTE: the factories below are magic (__callStatic on AbstractEnum),
		// so method_exists() probing cannot see them — probe the backing
		// class constants instead, then call the factories directly.
		if ( ! class_exists( ProviderTypeEnum::class ) || ! defined( ProviderTypeEnum::class . '::CLOUD' ) ) {
			throw new \RuntimeException( 'OpenCode provider requires ProviderTypeEnum::cloud().' );
		}
		if ( ! class_exists( RequestAuthenticationMethod::class ) || ! defined( RequestAuthenticationMethod::class . '::API_KEY' ) ) {
			throw new \RuntimeException( 'OpenCode provider requires RequestAuthenticationMethod::apiKey().' );
		}
		$args       = array(
			static::providerId(),
			static::displayName(),
			ProviderTypeEnum::cloud(),
			'https://opencode.ai/auth',
			RequestAuthenticationMethod::apiKey(),
		);
		$ai_version = defined( AiClient::class . '::VERSION' ) ? AiClient::VERSION : '1.0.0';
		if ( version_compare( $ai_version, '1.2.0', '>=' ) ) {
			$args[] = static::description();
		}
		if ( version_compare( $ai_version, '1.3.0', '>=' ) ) {
			$args[] = dirname( __DIR__, 2 ) . '/assets/images/opencode.svg';
		}
		return new ProviderMetadata( ...$args );
	}

	/**
	 * Create provider availability.
	 *
	 * @since 0.1.0
	 *
	 * @return ProviderAvailabilityInterface
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new OpenCodeProviderAvailability( static::catalogKey() );
	}

	/**
	 * Create model metadata directory.
	 *
	 * @since 0.1.0
	 *
	 * @return ModelMetadataDirectoryInterface
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		$map     = static::class_map();
		$catalog = static::catalogKey();
		$cls     = $map[ $catalog ]['directory'] ?? Catalog::directory_class( $catalog );
		return new $cls();
	}
}
