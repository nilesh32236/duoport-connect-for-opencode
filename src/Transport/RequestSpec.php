<?php
/**
 * Provider request specification value object.
 *
 * Replaces the 5-parameter buildProviderRequest() signature drift: future
 * fields (e.g. timeout) extend this object, not the signature at all three
 * call sites.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */

declare(strict_types=1);

namespace OpenCodeConnector\Transport;

use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable provider request specification.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */
final class RequestSpec {
	/**
	 * Build a request specification.
	 *
	 * @since 0.1.8
	 *
	 * @param string         $provider_class Provider class FQCN.
	 * @param HttpMethodEnum $method         HTTP method.
	 * @param string         $path           Request path.
	 * @param array          $headers        Request headers.
	 * @param mixed          $data           Request data.
	 */
	public function __construct(
		public readonly string $provider_class,
		public readonly HttpMethodEnum $method,
		public readonly string $path,
		public readonly array $headers = array(),
		public readonly mixed $data = null
	) {}
}
