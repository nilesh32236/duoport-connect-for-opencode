<?php
/**
 * Shared provider request construction.
 *
 * Single home for the `new Request( $method, $cls::url( $path ), ... )`
 * shape the text model, the image model, and the metadata directory each
 * built by hand: the copies had already drifted (the directory omitted the
 * transport options the models pass), so all three go through here.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */

declare(strict_types=1);

namespace OpenCodeConnector\Transport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Http\GoRequestHeaders;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

/**
 * Shared provider request construction for models and directories.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */
trait BuildsProviderRequest {
	/**
	 * Build a request for a provider class.
	 *
	 * Passes the transport options through when the using class offers
	 * them (the model bases do; a directory base without
	 * `getRequestOptions()` sends an empty set instead of fataling), so
	 * the /models discovery call carries the same options the model calls
	 * set instead of silently omitting them.
	 *
	 * @since 0.1.8
	 *
	 * @param string         $provider_class Provider class FQCN.
	 * @param HttpMethodEnum $method         HTTP method.
	 * @param string         $path           Request path.
	 * @param array          $headers        Request headers.
	 * @param mixed          $data           Request data.
	 * @return Request
	 */
	protected function buildProviderRequest( string $provider_class, HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		$options = array();
		if ( method_exists( $this, 'getRequestOptions' ) ) {
			try {
				$resolved = $this->getRequestOptions();
			} catch ( \Throwable $options_exception ) {
				unset( $options_exception );
				$resolved = array();
			}
			if ( is_array( $resolved ) ) {
				$options = $resolved;
			}
		}
		return new Request( $method, $provider_class::url( $path ), $headers, $data, $options );
	}

	/**
	 * Apply the shared Go header pair, fail-open.
	 *
	 * Single seam for the `GoRequestHeaders::for_go()` call the text and
	 * image paths share, so the next header change lands in both. A
	 * missing helper degrades to the caller's headers, never a fatal.
	 *
	 * @since 0.1.8
	 *
	 * @param array $headers Request headers.
	 * @param mixed $data    Request data the session is derived from.
	 * @return array Headers with the Go pair added when applicable.
	 */
	protected function goHeaders( array $headers, $data ): array {
		try {
			if ( class_exists( GoRequestHeaders::class ) && method_exists( GoRequestHeaders::class, 'for_go' ) ) {
				return GoRequestHeaders::for_go( $headers, $data );
			}
		} catch ( \Throwable $header_exception ) {
			unset( $header_exception );
		}
		return $headers;
	}
}
