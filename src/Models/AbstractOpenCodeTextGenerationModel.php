<?php
/**
 * Shared text generation model.
 *
 * Shared text generation model for chat completions.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

namespace OpenCodeConnector\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Http\SessionHeader;
use OpenCodeConnector\Providers\OpenCodeGoProvider;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Shared text generation model (chat/completions).
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */
abstract class AbstractOpenCodeTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {
	/**
	 * Provider class FQCN.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	abstract protected function providerClass(): string;

	/**
	 * Create a request for the provider.
	 *
	 * @since 0.1.0
	 *
	 * @param HttpMethodEnum $method  HTTP method.
	 * @param string         $path    Request path.
	 * @param array          $headers Request headers.
	 * @param mixed          $data    Request data.
	 * @return Request
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		$cls = $this->providerClass();
		// Apply site-wide generation defaults (default model, temperature,
		// max tokens). Caller-supplied values always win; fail-open on any
		// error so requests never fatal due to settings. Credential-blind:
		// reads only the plugin-owned option, never connectors_ai_* values.
		if ( is_array( $data ) ) {
			try {
				if ( class_exists( \OpenCodeConnector\Settings\Settings::class ) && method_exists( \OpenCodeConnector\Settings\Settings::class, 'apply_to_request_data' ) ) {
					$data = \OpenCodeConnector\Settings\Settings::apply_to_request_data( $data );
				}
			} catch ( \Throwable $e ) {
				// Fail-open: send the request unmodified.
				unset( $e );
			}
			if ( ! is_array( $data ) ) {
				$data = array();
			}
		}
		if ( OpenCodeGoProvider::class === $cls ) {
			try {
				$with_session = SessionHeader::inject_into_headers( $headers, $data );
			} catch ( \Throwable $e ) {
				$with_session = $headers;
			}
			$headers = $with_session;
		}
		return new Request( $method, $cls::url( $path ), $headers, $data, $this->getRequestOptions() );
	}

	/**
	 * OpenCode expects json_schema with name wrapper; SDK sends bare schema.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed>|null $output_schema Output schema.
	 * @return array<string, mixed>
	 */
	protected function prepareResponseFormatParam( ?array $output_schema ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		if ( is_array( $output_schema ) ) {
			return array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'response',
					'schema' => $output_schema,
					'strict' => true,
				),
			);
		}
		return array( 'type' => 'json_object' );
	}
}
