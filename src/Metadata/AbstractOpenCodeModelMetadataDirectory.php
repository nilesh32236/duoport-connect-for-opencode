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
		$show_all = (bool) ( get_option( \OpenCodeConnector\OPTION_NAME, array() )['show_all_models'] ?? false );

		$common_opts = array(
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

		$list = array();
		foreach ( (array) $data['data'] as $row ) {
			$id = $row['id'] ?? '';
			if ( ! $id ) {
				continue;
			}
			$record   = ModelRegistry::record( $id, $this->catalogKey() );
			$is_image = (bool) ( $record['capabilities']['image'] ?? false );
			if ( ! $is_image && ! $show_all && null === $record ) {
				continue;
			}
			$name = (string) ( $record['display_name'] ?? ModelAllowlist::displayName( $id ) );
			if ( (bool) ( $record['free'] ?? ModelAllowlist::isFree( $id ) ) ) {
				$name .= ' ' . __( '(Free)', 'duoport-connect-for-opencode' );
			}
			if ( $is_image ) {
				$list[] = new ModelMetadata(
					$id,
					$name,
					array( CapabilityEnum::imageGeneration() ),
					array(
						new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
						new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::image() ) ) ),
						new SupportedOption( OptionEnum::outputMimeType(), array( 'image/png', 'image/jpeg', 'image/webp' ) ),
						new SupportedOption( OptionEnum::customOptions() ),
					)
				);
				continue;
			}
			// DeepSeek models return malformed JSON for strict schema; hide outputSchema so JSON tasks pick a capable model.
			$is_json_capable = ! str_starts_with( $id, 'deepseek' );
			$opts            = $common_opts;
			if ( $is_json_capable ) {
				array_splice( $opts, 5, 0, array( new SupportedOption( OptionEnum::outputSchema() ) ) );
			}
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
			$list[] = new ModelMetadata( $id, $name, array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ), $opts );
		}
		usort(
			$list,
			static function ( ModelMetadata $a, ModelMetadata $b ): int {
				$af = ModelAllowlist::isFree( $a->getId() ) ? 0 : 1;
				$bf = ModelAllowlist::isFree( $b->getId() ) ? 0 : 1;
				if ( $af !== $bf ) {
					return $af <=> $bf;
				}
				return strcmp( $a->getId(), $b->getId() );
			}
		);
		return $list;
	}
}
