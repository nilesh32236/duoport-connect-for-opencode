<?php
/**
 * Base model metadata directory.
 *
 * Filters API models through the allowlist.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

namespace OpenCodeConnector\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Http\ClientUserAgent;
use OpenCodeConnector\Transport\BuildsProviderRequest;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

/**
 * Base directory that filters API models through the allowlist.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */
abstract class AbstractOpenCodeModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory {
	use BuildsProviderRequest;

	/**
	 * Provider class FQCN.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	abstract protected function providerClass(): string;

	/**
	 * Catalog key.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	abstract protected function catalogKey(): string;

	/**
	 * Create a request for the provider.
	 *
	 * Go `/models` requests carry the plugin User-Agent so they no longer
	 * appear as the default WordPress UA. No session header is sent here:
	 * the listing is a headerless GET with no chat or image context to
	 * derive a session from, and inventing one would be a fake identity.
	 * Zen requests are sent unchanged. Header failures fall back to a
	 * headerless send; never fatal.
	 *
	 * @since 0.1.0
	 * @since 0.1.9 Added client User-Agent on the Go path.
	 *
	 * @param HttpMethodEnum $method HTTP method.
	 * @param string         $path   Request path.
	 * @param array          $headers Request headers.
	 * @param mixed          $data   Request data.
	 * @return Request
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		$cls = $this->providerClass();
		if ( Catalog::GO === $this->catalogKey() && class_exists( ClientUserAgent::class ) && method_exists( ClientUserAgent::class, 'inject_into_headers' ) ) {
			try {
				$headers = ClientUserAgent::inject_into_headers( $headers );
			} catch ( \Throwable $user_agent_exception ) {
				unset( $user_agent_exception );
				// Fail-open: leave headers unchanged when User-Agent injection fails.
			}
		}
		return $this->buildProviderRequest( $cls, $method, $path, $headers, $data );
	}

	/**
	 * Parse response data into model metadata list.
	 *
	 * @since 0.1.0
	 *
	 * @param Response $response HTTP response.
	 * @return array
	 * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException When data is missing.
	 */
	protected function parseResponseToModelMetadataList( Response $response ): array {
		$data = $response->getData();
		if ( ! isset( $data['data'] ) ) {
			throw ResponseException::fromMissingData( 'OpenCode', 'data' );
		}
		if ( ! is_array( $data['data'] ) || array() === $data['data'] ) {
			return array();
		}
		$show_all = (bool) ( get_option( \OpenCodeConnector\OPTION_NAME, array() )['show_all_models'] ?? false );

		$list = array();
		foreach ( (array) $data['data'] as $row ) {
			$metadata = $this->metadataForRow( $row, $show_all );
			if ( null !== $metadata ) {
				$list[] = $metadata;
			}
		}
		$this->sortByFreeFirst( $list );
		return $list;
	}

	/**
	 * Build the shared text-model option set.
	 *
	 * The outputSchema position lives here instead of at a hardcoded
	 * array_splice() index at the call site, so reordering this list cannot
	 * silently move outputSchema to the wrong advertised position.
	 *
	 * @since 0.1.6
	 *
	 * @param bool $with_output_schema Whether to include the outputSchema option.
	 * @return array
	 */
	private function buildCommonOptions( bool $with_output_schema = false ): array {
		$options = array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::stopSequences() ),
		);
		if ( $with_output_schema ) {
			$options[] = new SupportedOption( OptionEnum::outputSchema() );
		}
		return array_merge(
			$options,
			array(
				new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
				new SupportedOption( OptionEnum::customOptions() ),
				new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
				new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
			)
		);
	}

	/**
	 * Build metadata for one API row, or null when filtered out.
	 *
	 * ID coercion, registry gating, and name dispatch only; the image and
	 * text branches build their own option sets.
	 *
	 * @since 0.1.6
	 *
	 * @param mixed $row Raw API row.
	 * @param bool  $show_all Whether show-all mode is enabled.
	 * @return ModelMetadata|null
	 */
	private function metadataForRow( $row, bool $show_all ): ?ModelMetadata {
		// A malformed /models row can carry a non-scalar id (array/object); it is
		// treated exactly like an absent one rather than stringified into a garbage
		// model id that would then fail the string-typed ModelRegistry::record().
		$raw_id = is_array( $row ) ? ( $row['id'] ?? '' ) : '';
		$id     = is_scalar( $raw_id ) ? (string) $raw_id : '';
		if ( ! $id ) {
			return null;
		}
		$record = ModelRegistry::record( $id, $this->catalogKey() );
		if ( null !== $record && ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED === ( $record['endpoint_family'] ?? '' ) ) {
			return null;
		}
		$is_image = (bool) ( $record['capabilities']['image'] ?? false );
		if ( ! $is_image && ! $show_all && null === $record ) {
			return null;
		}
		if ( $is_image ) {
			return $this->imageMetadata( $id, $record );
		}
		return $this->textMetadata( $id, $record );
	}

	/**
	 * Display name for a model, with the free suffix when applicable.
	 *
	 * Returns the finished name so neither branch mutates a shared $name
	 * variable for two responsibilities.
	 *
	 * @since 0.1.8
	 *
	 * @param string               $id     Model ID.
	 * @param array<string, mixed> $record Registry record, if any.
	 * @return string
	 */
	private function displayNameFor( string $id, ?array $record ): string {
		$name = (string) ( null !== $record ? ( $record['display_name'] ?? ModelAllowlist::displayName( $id ) ) : ModelAllowlist::displayName( $id ) );
		if ( (bool) ( null !== $record ? ( $record['free'] ?? ModelAllowlist::isFree( $id ) ) : ModelAllowlist::isFree( $id ) ) ) {
			$name .= ' ' . __( '(Free)', 'duoport-connect-for-opencode' );
		}
		return $name;
	}

	/**
	 * Build metadata for an image-capable model row.
	 *
	 * @since 0.1.8
	 *
	 * @param string                    $id     Model ID.
	 * @param array<string, mixed>|null $record Registry record, if any.
	 * @return ModelMetadata
	 */
	private function imageMetadata( string $id, ?array $record ): ModelMetadata {
		return new ModelMetadata(
			$id,
			$this->displayNameFor( $id, $record ),
			array( CapabilityEnum::imageGeneration() ),
			array(
				new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
				new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::image() ) ) ),
				new SupportedOption( OptionEnum::outputMimeType(), \OpenCodeConnector\Media\ImageMime::all() ),
				new SupportedOption( OptionEnum::customOptions() ),
			)
		);
	}

	/**
	 * Build metadata for a text-model row.
	 *
	 * DeepSeek models return malformed JSON for strict schema, so they hide
	 * outputSchema and JSON tasks pick a capable model. Function-calling
	 * transport is inherited from the OpenAI-compatible base model (tools
	 * param + tool_calls response parsing), so tool-verified models
	 * advertise it and stop being filtered out of Abilities-API tool tasks.
	 * Web search stays gated until the chat/completions payload is
	 * gateway-verified (fail-open default).
	 *
	 * @since 0.1.8
	 *
	 * @param string                    $id     Model ID.
	 * @param array<string, mixed>|null $record Registry record, if any.
	 * @return ModelMetadata
	 */
	private function textMetadata( string $id, ?array $record ): ModelMetadata {
		// DeepSeek models return malformed JSON for strict schema; hide outputSchema so JSON tasks pick a capable model.
		$opts = $this->buildCommonOptions( ! str_starts_with( $id, 'deepseek' ) );
		if ( ModelRegistry::supports( $id, $this->catalogKey(), 'tools' ) ) {
			$opts[] = new SupportedOption( OptionEnum::functionDeclarations() );
		}
		if ( ModelRegistry::supports( $id, $this->catalogKey(), 'web_search' ) ) {
			$opts[] = new SupportedOption( OptionEnum::webSearch() );
		}
		return new ModelMetadata( $id, $this->displayNameFor( $id, $record ), array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ), $opts );
	}

	/**
	 * Sort metadata free-first, then by ID.
	 *
	 * @since 0.1.6
	 *
	 * @param array $metadata Sorted in place.
	 * @return void
	 */
	private function sortByFreeFirst( array &$metadata ): void {
		usort(
			$metadata,
			static function ( ModelMetadata $a, ModelMetadata $b ): int {
				$af = ModelAllowlist::isFree( $a->getId() ) ? 0 : 1;
				$bf = ModelAllowlist::isFree( $b->getId() ) ? 0 : 1;
				if ( $af !== $bf ) {
					return $af <=> $bf;
				}
				return strcmp( $a->getId(), $b->getId() );
			}
		);
	}
}
