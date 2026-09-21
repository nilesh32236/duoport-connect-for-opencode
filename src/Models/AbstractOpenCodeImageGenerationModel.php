<?php
/**
 * Shared image generation model.
 *
 * Shared image generation model for images/generations.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */

declare(strict_types=1);

namespace OpenCodeConnector\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Http\GoRequestHeaders;
use OpenCodeConnector\Providers\OpenCodeGoProvider;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;

/**
 * Shared image generation model (images/generations).
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */
abstract class AbstractOpenCodeImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel {
	/**
	 * Provider class FQCN.
	 *
	 * @since 0.1.4
	 *
	 * @return string
	 */
	abstract protected function providerClass(): string;

	/**
	 * Create a request for the provider.
	 *
	 * Go requests carry the same stable `x-opencode-session` value as the
	 * text path (derived from the image `prompt` payload) plus the client
	 * User-Agent (shared fingerprint with the Go text path). Zen requests
	 * are sent unchanged (Zen ignores both headers).
	 * Header failures always fall back to a headerless send; never fatal.
	 *
	 * @since 0.1.4
	 * @since 0.1.4 Added Go session header and client User-Agent.
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
			$headers = GoRequestHeaders::for_go( $headers, $data );
		}
		return new Request( $method, $cls::url( $path ), $headers, $data, $this->getRequestOptions() );
	}
}
