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

use OpenCodeConnector\Http\ClientUserAgent;
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
	 * Go requests carry the stable `x-opencode-session` header plus the
	 * shared client User-Agent (same fingerprint as the Go image path, so
	 * gateway observability sees one client). Zen requests are sent
	 * unchanged. Header failures always fall back to a headerless send;
	 * never fatal.
	 *
	 * @since 0.1.0
	 * @since 0.1.5 Added shared client User-Agent on the Go path.
	 *
	 * @param HttpMethodEnum $method  HTTP method.
	 * @param string         $path    Request path.
	 * @param array          $headers Request headers.
	 * @param mixed          $data    Request data.
	 * @return Request
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		$cls = $this->providerClass();
		if ( OpenCodeGoProvider::class === $cls ) {
			try {
				$with_session = SessionHeader::inject_into_headers( $headers, $data );
			} catch ( \Throwable ) {
				$with_session = $headers;
			}
			$headers = $with_session;
			try {
				$with_agent = ClientUserAgent::inject_into_headers( $headers );
			} catch ( \Throwable ) {
				$with_agent = $headers;
			}
			$headers = $with_agent;
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
