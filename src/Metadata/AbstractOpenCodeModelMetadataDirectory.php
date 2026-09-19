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

use OpenCodeConnector\Http\RequestFactory;
use OpenCodeConnector\Media\ImageAttachmentSaver;
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
	 * Show-all override for tests.
	 *
	 * When null the flag is read once from the WP option; tests inject via
	 * set_show_all_models() so the pure mapping logic stays decoupled from
	 * global state.
	 *
	 * @since 0.1.5
	 *
	 * @var bool|null
	 */
	private ?bool $show_all_override = null;

	/**
	 * Inject the show-all-models flag (test seam).
	 *
	 * @since 0.1.5
	 *
	 * @param bool $show_all Whether to list every API model.
	 * @return void
	 */
	public function set_show_all_models( bool $show_all ): void {
		$this->show_all_override = $show_all;
	}

	/**
	 * Resolve the show-all-models flag.
	 *
	 * Thin WP-wired reader: the option is read once here, parsing helpers
	 * below stay pure. A corrupted non-array option safely reads as false.
	 *
	 * @since 0.1.5
	 *
	 * @return bool
	 */
	protected function resolve_show_all_models(): bool {
		if ( null !== $this->show_all_override ) {
			return $this->show_all_override;
		}
		$opts = get_option( \OpenCodeConnector\OPTION_NAME, array() );
		if ( ! is_array( $opts ) ) {
			return false;
		}
		return (bool) ( $opts['show_all_models'] ?? false );
	}

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
		return RequestFactory::for_provider( $this->providerClass(), $method, $path, $headers, $data );
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
		$show_all    = $this->resolve_show_all_models();
		$common_opts = $this->build_common_options();

		$list = array();
		foreach ( (array) $data['data'] as $row ) {
			$id = is_array( $row ) ? ( $row['id'] ?? '' ) : '';
			if ( ! is_string( $id ) || '' === $id ) {
				continue;
			}
			$is_image = ModelAllowlist::isImageCapable( $id, $this->catalogKey() );
			if ( ! $is_image && ! $show_all && ! ModelAllowlist::isAllowed( $id, $this->catalogKey() ) ) {
				continue;
			}
			$name = ModelAllowlist::displayName( $id );
			if ( ModelAllowlist::isFree( $id ) ) {
				$name .= ' ' . __( '(Free)', 'duoport-connect-for-opencode' );
			}
			$list[] = $is_image ? $this->build_image_metadata( $id, $name ) : $this->build_text_metadata( $id, $name, $common_opts );
		}
		$this->sort_by_free_first( $list );
		return $list;
	}

	/**
	 * Options shared by every text-capable model.
	 *
	 * @since 0.1.5
	 *
	 * @return SupportedOption[]
	 */
	protected function build_common_options(): array {
		return array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
			new SupportedOption( OptionEnum::customOptions() ),
			new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
			new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
		);
	}

	/**
	 * Build image-generation metadata for an image-capable model.
	 *
	 * Advertised MIME types come from ImageAttachmentSaver::ALLOWED_MIME_TYPES
	 * so the options never desync from what the saver accepts.
	 *
	 * @since 0.1.5
	 *
	 * @param string $id   Model ID.
	 * @param string $name Display name.
	 * @return ModelMetadata
	 */
	protected function build_image_metadata( string $id, string $name ): ModelMetadata {
		return new ModelMetadata(
			$id,
			$name,
			array( CapabilityEnum::imageGeneration() ),
			array(
				new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
				new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::image() ) ) ),
				new SupportedOption( OptionEnum::outputMimeType(), ImageAttachmentSaver::ALLOWED_MIME_TYPES ),
				new SupportedOption( OptionEnum::customOptions() ),
			)
		);
	}

	/**
	 * Build text-generation metadata with capability gates.
	 *
	 * @since 0.1.5
	 *
	 * @param string            $id          Model ID.
	 * @param string            $name        Display name.
	 * @param SupportedOption[] $common_opts Shared options template.
	 * @return ModelMetadata
	 */
	protected function build_text_metadata( string $id, string $name, array $common_opts ): ModelMetadata {
		// DeepSeek models return malformed JSON for strict schema; hide outputSchema so JSON tasks pick a capable model.
		$opts = $common_opts;
		if ( ModelAllowlist::hasReliableJson( $id ) ) {
			array_splice( $opts, 5, 0, array( new SupportedOption( OptionEnum::outputSchema() ) ) );
		}
		// Function-calling transport is inherited from the OpenAI-compatible
		// base model (tools param + tool_calls response parsing), so
		// tool-verified models advertise it and stop being filtered out of
		// Abilities-API tool tasks. Web search stays gated until the
		// chat/completions payload is gateway-verified (fail-open default).
		if ( ModelAllowlist::isToolCapable( $id, $this->catalogKey() ) ) {
			$opts[] = new SupportedOption( OptionEnum::functionDeclarations() );
		}
		if ( ModelAllowlist::isWebSearchCapable( $id, $this->catalogKey() ) ) {
			$opts[] = new SupportedOption( OptionEnum::webSearch() );
		}
		return new ModelMetadata( $id, $name, array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ), $opts );
	}

	/**
	 * Sort free models first, then by ID.
	 *
	 * @since 0.1.5
	 *
	 * @param ModelMetadata[] $metadata_list Metadata list (sorted in place).
	 * @return void
	 */
	protected function sort_by_free_first( array &$metadata_list ): void {
		usort(
			$metadata_list,
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
