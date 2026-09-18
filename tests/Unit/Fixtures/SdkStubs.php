<?php
/**
 * Minimal AI Client SDK stubs for unit tests.
 *
 * The WordPress AI Client SDK is not installed in dev dependencies, so tests
 * that exercise model request construction declare these lightweight stand-ins
 * (only when the real SDK classes are absent).
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace WordPress\AiClient\Providers\Http\Enums {
	if ( ! class_exists( \WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum::class ) ) {
		/**
		 * Minimal HTTP method stub.
		 */
		final class HttpMethodEnum {
			/**
			 * Constructor.
			 *
			 * @param string $value Method name.
			 */
			private function __construct( private readonly string $value ) {
			}

			/**
			 * POST method.
			 *
			 * @return self
			 */
			public static function POST(): self {
				return new self( 'POST' );
			}

			/**
			 * Method value.
			 *
			 * @return string
			 */
			public function getValue(): string {
				return $this->value;
			}
		}
	}
}

namespace WordPress\AiClient\Providers\Http\DTO {
	use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

	if ( ! class_exists( \WordPress\AiClient\Providers\Http\DTO\Request::class ) ) {
		/**
		 * Minimal request DTO stub capturing constructor args.
		 */
		final class Request {
			/**
			 * Constructor.
			 *
			 * @param HttpMethodEnum $method  HTTP method.
			 * @param string         $url     Request URL.
			 * @param array          $headers Request headers.
			 * @param mixed          $data    Request data.
			 * @param array          $options Transport options.
			 */
			public function __construct(
				private readonly HttpMethodEnum $method,
				private readonly string $url,
				private readonly array $headers = array(),
				private readonly mixed $data = null,
				private readonly array $options = array()
			) {
			}

			/**
			 * Request headers.
			 *
			 * @return array
			 */
			public function getHeaders(): array {
				return $this->headers;
			}

			/**
			 * Request data.
			 *
			 * @return mixed
			 */
			public function getData(): mixed {
				return $this->data;
			}

			/**
			 * Request URL.
			 *
			 * @return string
			 */
			public function getUrl(): string {
				return $this->url;
			}
		}
	}
}

namespace WordPress\AiClient\Providers\OpenAiCompatibleImplementation {
	if ( ! class_exists( \WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel::class ) ) {
		/**
		 * Minimal OpenAI-compatible model stub.
		 */
		abstract class AbstractOpenAiCompatibleTextGenerationModel {
			/**
			 * Transport options.
			 *
			 * @return array
			 */
			protected function getRequestOptions(): array {
				return array();
			}
		}
	}
}

namespace WordPress\AiClient\Providers\ApiBasedImplementation {
	if ( ! class_exists( \WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider::class ) ) {
		/**
		 * Minimal API provider stub.
		 */
		abstract class AbstractApiProvider {
			/**
			 * Build an endpoint URL.
			 *
			 * @param string $path Request path.
			 * @return string
			 */
			public static function url( string $path ): string {
				return 'https://example.test/' . ltrim( $path, '/' );
			}
		}
	}
}
