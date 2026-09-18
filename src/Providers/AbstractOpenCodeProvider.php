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
		$caps = $model->getSupportedCapabilities();
		foreach ( $caps as $capability ) {
			if ( $capability->isTextGeneration() ) {
				return 'go' === static::catalogKey()
					? new OpenCodeGoTextGenerationModel( $model, $provider )
					: new OpenCodeZenTextGenerationModel( $model, $provider );
			}
		}
		$cap_names = array_map( static fn( $capability ): string => method_exists( $capability, 'getValue' ) ? (string) $capability->getValue() : (string) $capability, $caps );
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
		throw new \WordPress\AiClient\Common\Exception\RuntimeException( 'Unsupported capability: ' . implode( ', ', $cap_names ) );
	}

	/**
	 * Create provider metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return ProviderMetadata
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		$args = array(
			static::providerId(),
			static::displayName(),
			ProviderTypeEnum::cloud(),
			'https://opencode.ai/auth',
			RequestAuthenticationMethod::apiKey(),
		);
		if ( version_compare( AiClient::VERSION, '1.2.0', '>=' ) ) {
			$args[] = static::description();
		}
		if ( version_compare( AiClient::VERSION, '1.3.0', '>=' ) ) {
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
