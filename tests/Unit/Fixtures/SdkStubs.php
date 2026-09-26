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

namespace WordPress\AiClient\Providers\Models\Contracts {
	if ( ! interface_exists( \WordPress\AiClient\Providers\Models\Contracts\ModelInterface::class ) ) {
		/**
		 * Minimal model contract.
		 */
		interface ModelInterface {
		}
	}
}

namespace WordPress\AiClient\Providers\OpenAiCompatibleImplementation {
	if ( ! class_exists( \WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel::class ) ) {
		/**
		 * Minimal OpenAI-compatible model stub.
		 */
		abstract class AbstractOpenAiCompatibleTextGenerationModel implements \WordPress\AiClient\Providers\Models\Contracts\ModelInterface {
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

namespace WordPress\AiClient\Providers\Http\DTO {
	if ( ! class_exists( \WordPress\AiClient\Providers\Http\DTO\Response::class ) ) {
		/**
		 * Minimal response DTO stub.
		 *
		 * Supports both shapes the suite exercises: the metadata-directory
		 * shape `new Response( array $data )` (defaults to status 200) and
		 * the probe shape `new Response( int $status_code, mixed $data )`.
		 */
		final class Response {
			/**
			 * HTTP status code.
			 *
			 * @var int
			 */
			private int $status_code;

			/**
			 * Decoded response data.
			 *
			 * @var mixed
			 */
			private mixed $data;

			/**
			 * Constructor.
			 *
			 * @param mixed $first  Status code (int) or data (array).
			 * @param mixed $second Decoded data when $first is a status code.
			 */
			public function __construct(
				mixed $first = array(),
				mixed $second = null
			) {
				if ( is_int( $first ) ) {
					$this->status_code = $first;
					$this->data        = $second;
				} else {
					$this->status_code = 200;
					$this->data        = $first;
				}
			}

			/**
			 * HTTP status code.
			 *
			 * @return int
			 */
			public function getStatusCode(): int {
				return $this->status_code;
			}

			/**
			 * Decoded response data.
			 *
			 * @return mixed
			 */
			public function getData(): mixed {
				return $this->data;
			}
		}
	}
}

namespace WordPress\AiClient\Providers\Contracts {
	if ( ! interface_exists( \WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface::class ) ) {
		/**
		 * Minimal provider-availability contract stub.
		 */
		interface ProviderAvailabilityInterface {
			/**
			 * Whether the provider is configured.
			 *
			 * @return bool
			 */
			public function isConfigured(): bool;
		}
	}
}

namespace WordPress\AiClient\Providers\Http\Contracts {
	if ( ! interface_exists( \WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface::class ) ) {
		/**
		 * Minimal HTTP-transporter awareness contract stub.
		 */
		interface WithHttpTransporterInterface {
		}
	}

	if ( ! interface_exists( \WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface::class ) ) {
		/**
		 * Minimal request-authentication awareness contract stub.
		 */
		interface WithRequestAuthenticationInterface {
		}
	}
}

namespace WordPress\AiClient\Providers\Models\Enums {
	if ( ! class_exists( \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::class ) ) {
		/**
		 * Minimal capability enum stub.
		 */
		class CapabilityEnum {
			/**
			 * Capability value.
			 *
			 * @var string
			 */
			private string $value;

			/**
			 * Constructor.
			 *
			 * @param string $value Capability value.
			 */
			private function __construct( string $value ) {
				$this->value = $value;
			}

			/**
			 * Text-generation capability.
			 *
			 * @return self
			 */
			public static function textGeneration(): self {
				return new self( 'text-generation' );
			}

			/**
			 * Image-generation capability.
			 *
			 * @return self
			 */
			public static function imageGeneration(): self {
				return new self( 'image-generation' );
			}

			/**
			 * Chat-history capability.
			 *
			 * @return self
			 */
			public static function chatHistory(): self {
				return new self( 'chat-history' );
			}

			/**
			 * Whether this is the text-generation capability.
			 *
			 * @return bool
			 */
			public function isTextGeneration(): bool {
				return 'text-generation' === $this->value;
			}

			/**
			 * Whether this is the image-generation capability.
			 *
			 * @return bool
			 */
			public function isImageGeneration(): bool {
				return 'image-generation' === $this->value;
			}

			/**
			 * Whether this is the chat-history capability.
			 *
			 * @return bool
			 */
			public function isChatHistory(): bool {
				return in_array( $this->value, array( 'chat-history', 'chat_history' ), true );
			}

			/**
			 * Capability value.
			 *
			 * @return string
			 */
			public function getValue(): string {
				return $this->value;
			}
		}
	}
}

namespace WordPress\AiClient\Providers\Http\Traits {
	if ( ! trait_exists( \WordPress\AiClient\Providers\Http\Traits\WithHttpTransporterTrait::class ) ) {
		/**
		 * Minimal HTTP-transporter trait stub (injectable for probe tests).
		 */
		trait WithHttpTransporterTrait {
			/**
			 * Injected transporter double.
			 *
			 * @var mixed
			 */
			private mixed $http_transporter_stub = null;

			/**
			 * Current transporter.
			 *
			 * @return mixed
			 */
			public function getHttpTransporter(): mixed {
				return $this->http_transporter_stub;
			}

			/**
			 * Inject a transporter double.
			 *
			 * @param mixed $transporter Transporter double.
			 * @return void
			 */
			public function setHttpTransporter( mixed $transporter ): void {
				$this->http_transporter_stub = $transporter;
			}
		}
	}

	if ( ! trait_exists( \WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait::class ) ) {
		/**
		 * Minimal request-authentication trait stub (injectable for probe tests).
		 */
		trait WithRequestAuthenticationTrait {
			/**
			 * Injected authentication double.
			 *
			 * @var mixed
			 */
			private mixed $request_authentication_stub = null;

			/**
			 * Current authentication.
			 *
			 * @return mixed
			 */
			public function getRequestAuthentication(): mixed {
				if ( null === $this->request_authentication_stub ) {
					throw new \RuntimeException( 'No request authentication configured.' );
				}
				return $this->request_authentication_stub;
			}

			/**
			 * Inject an authentication double.
			 *
			 * @param mixed $authentication Authentication double.
			 * @return void
			 */
			public function setRequestAuthentication( mixed $authentication ): void {
				$this->request_authentication_stub = $authentication;
			}
		}
	}
}
