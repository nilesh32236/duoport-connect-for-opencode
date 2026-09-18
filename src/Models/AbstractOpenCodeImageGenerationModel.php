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
	 * @since 0.1.4
	 *
	 * @param HttpMethodEnum $method  HTTP method.
	 * @param string         $path    Request path.
	 * @param array          $headers Request headers.
	 * @param mixed          $data    Request data.
	 * @return Request
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		$cls = $this->providerClass();
		return new Request( $method, $cls::url( $path ), $headers, $data, $this->getRequestOptions() );
	}
}
