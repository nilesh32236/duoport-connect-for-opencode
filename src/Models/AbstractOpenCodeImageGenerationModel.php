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

use OpenCodeConnector\Http\SessionHeader;
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
	 * Client User-Agent prefix identifying this plugin.
	 *
	 * @since 0.1.5
	 */
	const CLIENT_USER_AGENT_PREFIX = 'duoport-connect-for-opencode/';

	/**
	 * Fallback plugin version when the VERSION constant is unavailable.
	 *
	 * Mirrors the plugin header; used only when `OpenCodeConnector\VERSION`
	 * is not defined (e.g. partial bootstrap). Never read from options.
	 *
	 * @since 0.1.5
	 */
	const CLIENT_USER_AGENT_FALLBACK_VERSION = '0.1.4';

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
	 * User-Agent. Zen requests are sent unchanged (Zen ignores the header).
	 * Header failures always fall back to a headerless send; never fatal.
	 *
	 * @since 0.1.4
	 * @since 0.1.5 Added Go session header and client User-Agent.
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
			} catch ( \Throwable $e ) {
				$with_session = $headers;
			}
			$headers = $with_session;
			try {
				$with_agent = self::inject_client_user_agent( $headers );
			} catch ( \Throwable $e ) {
				$with_agent = $headers;
			}
			$headers = $with_agent;
		}
		return new Request( $method, $cls::url( $path ), $headers, $data, $this->getRequestOptions() );
	}

	/**
	 * Inject the client User-Agent into a headers array.
	 *
	 * Adds the header only when no value was explicitly provided (compared
	 * case-insensitively). Never throws; never reads options.
	 *
	 * @since 0.1.5
	 *
	 * @param array $headers Request headers.
	 * @return array Headers with the client User-Agent added when applicable.
	 */
	private static function inject_client_user_agent( array $headers ): array {
		foreach ( $headers as $name => $existing ) {
			if ( is_string( $name ) && 0 === strcasecmp( $name, 'User-Agent' ) ) {
				return $headers;
			}
		}
		$headers['User-Agent'] = self::client_user_agent_value();
		return $headers;
	}

	/**
	 * Build the client User-Agent value.
	 *
	 * Tracks the plugin VERSION constant with a literal fallback so the
	 * value stays correct across version bumps. Never throws.
	 *
	 * @since 0.1.5
	 *
	 * @return string Client User-Agent value.
	 */
	private static function client_user_agent_value(): string {
		try {
			$version = self::CLIENT_USER_AGENT_FALLBACK_VERSION;
			if ( defined( 'OpenCodeConnector\\VERSION' ) ) {
				$defined = constant( 'OpenCodeConnector\\VERSION' );
				if ( is_string( $defined ) && '' !== $defined ) {
					$version = $defined;
				}
			}
			return self::CLIENT_USER_AGENT_PREFIX . $version;
		} catch ( \Throwable ) {
			return self::CLIENT_USER_AGENT_PREFIX . self::CLIENT_USER_AGENT_FALLBACK_VERSION;
		}
	}
}
