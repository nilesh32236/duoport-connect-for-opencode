<?php
/**
 * Shared request factory for provider HTTP requests.
 *
 * Single place where provider-class URL joining and Request construction
 * live, so auth headers, request options, or URL joining change once instead
 * of drifting across the three createRequest() copies.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */

declare(strict_types=1);

namespace OpenCodeConnector\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

/**
 * Shared request factory.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */
final class RequestFactory {
	/**
	 * Build a provider request for a path.
	 *
	 * @since 0.1.4
	 *
	 * @param class-string   $provider_class Provider FQCN.
	 * @param HttpMethodEnum $method         HTTP method.
	 * @param string         $path           Request path.
	 * @param array          $headers        Request headers.
	 * @param mixed          $data           Request data.
	 * @param array          $options        Transport options.
	 * @return Request
	 * @throws \InvalidArgumentException When the provider class is unknown.
	 */
	public static function for_provider( string $provider_class, HttpMethodEnum $method, string $path, array $headers = array(), $data = null, array $options = array() ): Request {
		if ( ! class_exists( $provider_class ) || ! method_exists( $provider_class, 'url' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
			throw new \InvalidArgumentException( 'Unknown provider class: ' . $provider_class );
		}
		return new Request( $method, $provider_class::url( $path ), $headers, $data, $options );
	}
}
