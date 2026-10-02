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

use OpenCodeConnector\Media\ImageMime;
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
	/**
	 * Shared option name the JSON schema is anchored to.
	 *
	 * @since 0.1.8
	 *
	 * @var string
	 */
	private const OPTION_OUTPUT_MIME_TYPE = 'output_mime_type';

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
	 * @since 0.1.0
	 *
	 * @param HttpMethodEnum $method HTTP method.
	 * @param string         $path   Request path.
	 * @param array          $headers Request headers.
	 * @param mixed          $data   Request data.
	 * @return Request
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		$cls = $this->providerClass();
		return new Request( $method, $cls::url( $path ), $headers, $data );
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
		$show_all    = (bool) ( get_option( \OpenCodeConnector\OPTION_NAME, array() )['show_all_models'] ?? false );
		$common_opts = $this->buildCommonOptions();

		$list = array();
		foreach ( $data['data'] as $row ) {
			$metadata = $this->metadataForRow( $row, $show_all, $common_opts );
			if ( null !== $metadata ) {
				$list[] = $metadata;
			}
		}
		$this->sortByFreeFirst( $list );
		return $list;
	}

	/**
	 * Build the shared text-model option set, keyed by option name.
	 *
	 * Keys make the set addressable by name, so an option that has to sit next
	 * to a specific entry (the JSON schema next to the output MIME type) can
	 * be placed relative to it instead of at a hardcoded index.
	 *
	 * @since 0.1.6
	 *
	 * @return array<string, SupportedOption>
	 */
	private function buildCommonOptions(): array {
		return array(
			'system_instruction' => new SupportedOption( OptionEnum::systemInstruction() ),
			'max_tokens'         => new SupportedOption( OptionEnum::maxTokens() ),
			'temperature'        => new SupportedOption( OptionEnum::temperature() ),
			'top_p'              => new SupportedOption( OptionEnum::topP() ),
			'stop_sequences'     => new SupportedOption( OptionEnum::stopSequences() ),
			'output_mime_type'   => new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
			'custom_options'     => new SupportedOption( OptionEnum::customOptions() ),
			'input_modalities'   => new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
			'output_modalities'  => new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
		);
	}

	/**
	 * Flatten the shared options into one model's list.
	 *
	 * The JSON-schema option is emitted directly after the named
	 * `output_mime_type` entry — the pair that describes the response shape —
	 * so adding, dropping, or reordering entries in buildCommonOptions() can
	 * never silently relocate it into the wrong slot.
	 *
	 * @since 0.1.8
	 *
	 * @param array<string, SupportedOption> $common_opts  Shared options keyed by name.
	 * @param bool                           $json_capable Whether the model can honor a strict schema.
	 * @return list<SupportedOption>
	 */
	private function buildTextOptions( array $common_opts, bool $json_capable ): array {
		$opts = array();
		foreach ( $common_opts as $name => $option ) {
			$opts[] = $option;
			if ( self::OPTION_OUTPUT_MIME_TYPE === $name && $json_capable ) {
				$opts[] = new SupportedOption( OptionEnum::outputSchema() );
			}
		}
		return $opts;
	}

	/**
	 * Build metadata for one API row, or null when filtered out.
	 *
	 * @since 0.1.6
	 *
	 * @param mixed $row Raw API row.
	 * @param bool  $show_all Whether show-all mode is enabled.
	 * @param array $common_opts Shared text-model options.
	 * @return ModelMetadata|null
	 */
	private function metadataForRow( $row, bool $show_all, array $common_opts ): ?ModelMetadata {
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
		$name = (string) ( $record['display_name'] ?? ModelAllowlist::displayName( $id ) );
		if ( (bool) ( $record['free'] ?? ModelAllowlist::isFree( $id ) ) ) {
			$name .= ' ' . __( '(Free)', 'duoport-connect-for-opencode' );
		}
		if ( $is_image ) {
			return new ModelMetadata(
				$id,
				$name,
				array( CapabilityEnum::imageGeneration() ),
				array(
					new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
					new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::image() ) ) ),
					new SupportedOption( OptionEnum::outputMimeType(), ImageMime::all() ),
					new SupportedOption( OptionEnum::customOptions() ),
				)
			);
		}
		// DeepSeek models return malformed JSON for strict schema; hide outputSchema so JSON tasks pick a capable model.
		$is_json_capable = ! str_starts_with( $id, 'deepseek' );
		$opts            = $this->buildTextOptions( $common_opts, $is_json_capable );
		// Function-calling transport is inherited from the OpenAI-compatible
		// base model (tools param + tool_calls response parsing), so
		// tool-verified models advertise it and stop being filtered out of
		// Abilities-API tool tasks. Web search stays gated until the
		// chat/completions payload is gateway-verified (fail-open default).
		if ( ModelRegistry::supports( $id, $this->catalogKey(), 'tools' ) ) {
			$opts[] = new SupportedOption( OptionEnum::functionDeclarations() );
		}
		if ( ModelRegistry::supports( $id, $this->catalogKey(), 'web_search' ) ) {
			$opts[] = new SupportedOption( OptionEnum::webSearch() );
		}
		return new ModelMetadata( $id, $name, array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ), $opts );
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
