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
use OpenCodeConnector\Metadata\OpenCodeGoModelMetadataDirectory;
use OpenCodeConnector\Metadata\OpenCodeZenModelMetadataDirectory;
use OpenCodeConnector\Models\OpenCodeGoTextGenerationModel;
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
			$is_text = false;
			if ( is_object( $capability ) && method_exists( $capability, 'isTextGeneration' ) ) {
				$is_text = (bool) $capability->isTextGeneration();
			} elseif ( is_object( $capability ) && method_exists( $capability, 'getValue' ) ) {
				$is_text = ( 'text-generation' === (string) $capability->getValue() );
			} elseif ( is_string( $capability ) ) {
				$is_text = ( 'text-generation' === $capability );
			}
			if ( $is_text ) {
				return 'go' === static::catalogKey()
					? new OpenCodeGoTextGenerationModel( $model, $provider )
					: new OpenCodeZenTextGenerationModel( $model, $provider );
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
	 * Create provider metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return ProviderMetadata
	 * @throws \RuntimeException When the SDK enum factories are unavailable.
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		if ( ! class_exists( ProviderTypeEnum::class ) || ! method_exists( ProviderTypeEnum::class, 'cloud' ) ) {
			throw new \RuntimeException( 'OpenCode provider requires ProviderTypeEnum::cloud().' );
		}
		if ( ! class_exists( RequestAuthenticationMethod::class ) || ! method_exists( RequestAuthenticationMethod::class, 'apiKey' ) ) {
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
		return 'go' === static::catalogKey()
			? new OpenCodeGoModelMetadataDirectory()
			: new OpenCodeZenModelMetadataDirectory();
	}
}
