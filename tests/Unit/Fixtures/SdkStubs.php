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
		 *
		 * Models the shipped SDK shape on purpose: the real
		 * `HttpMethodEnum` extends `AbstractEnum`, declares each verb as a
		 * real backing constant, and exposes `POST()` only as an
		 * `@method static` annotation served by `AbstractEnum::__callStatic()`.
		 * A stub that instead declared `public static function POST()` made
		 * `method_exists( HttpMethodEnum::class, 'POST' )` return true here
		 * while it is false against the real SDK, which hid a production-only
		 * breakage of `OpenCodeProviderAvailability::verify()` from CI.
		 *
		 * @method static self GET()
		 * @method static self POST()
		 * @method static self PUT()
		 * @method static self PATCH()
		 * @method static self DELETE()
		 * @method static self HEAD()
		 * @method static self OPTIONS()
		 */
		final class HttpMethodEnum {
			public const GET     = 'GET';
			public const POST    = 'POST';
			public const PUT     = 'PUT';
			public const PATCH   = 'PATCH';
			public const DELETE  = 'DELETE';
			public const HEAD    = 'HEAD';
			public const OPTIONS = 'OPTIONS';

			/**
			 * Known verbs, keyed by constant name.
			 *
			 * @var array<string, string>
			 */
			private const VERBS = array(
				'GET'     => self::GET,
				'POST'    => self::POST,
				'PUT'     => self::PUT,
				'PATCH'   => self::PATCH,
				'DELETE'  => self::DELETE,
				'HEAD'    => self::HEAD,
				'OPTIONS' => self::OPTIONS,
			);

			/**
			 * Constructor.
			 *
			 * @param string $value Method name.
			 */
			private function __construct( private readonly string $value ) {
			}

			/**
			 * Serve the magic static factories, mirroring AbstractEnum.
			 *
			 * @param string       $name      Factory name.
			 * @param array<mixed> $arguments Factory arguments (unused).
			 * @return self
			 * @throws \BadMethodCallException When the verb is unknown.
			 */
			public static function __callStatic( string $name, array $arguments ): self {
				$constant = strtoupper( $name );
				if ( ! isset( self::VERBS[ $constant ] ) ) {
					throw new \BadMethodCallException(
						sprintf( 'Method %s::%s does not exist', self::class, $name )
					);
				}
				return new self( self::VERBS[ $constant ] );
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

			/**
			 * Transport options.
			 *
			 * @return array
			 */
			public function getOptions(): array {
				return $this->options;
			}
		}
	}

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
		 * Minimal OpenAI-compatible text model stub.
		 */
		abstract class AbstractOpenAiCompatibleTextGenerationModel implements \WordPress\AiClient\Providers\Models\Contracts\ModelInterface {
			/**
			 * Constructor.
			 *
			 * @param mixed $metadata Model metadata (unused).
			 * @param mixed $provider Provider metadata (unused).
			 */
			public function __construct( $metadata = null, $provider = null ) {
				unset( $metadata, $provider );
			}

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

	if ( ! class_exists( \WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel::class ) ) {
		/**
		 * Minimal OpenAI-compatible image model stub.
		 */
		abstract class AbstractOpenAiCompatibleImageGenerationModel implements \WordPress\AiClient\Providers\Models\Contracts\ModelInterface {
			/**
			 * Constructor.
			 *
			 * @param mixed $metadata Model metadata (unused).
			 * @param mixed $provider Provider metadata (unused).
			 */
			public function __construct( $metadata = null, $provider = null ) {
				unset( $metadata, $provider );
			}

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

	if ( ! class_exists( \WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory::class ) ) {
		/**
		 * Minimal OpenAI-compatible metadata directory base stub.
		 */
		abstract class AbstractOpenAiCompatibleModelMetadataDirectory {
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

namespace WordPress\AiClient\Providers\Models\Enums {
	if ( ! class_exists( \WordPress\AiClient\Providers\Models\Enums\ModalityEnum::class ) ) {
		/**
		 * Minimal modality enum stub.
		 */
		class ModalityEnum {
			/**
			 * Modality value.
			 *
			 * @var string
			 */
			private string $value;

			/**
			 * Constructor.
			 *
			 * @param string $value Modality name.
			 */
			private function __construct( string $value ) {
				$this->value = $value;
			}

			/**
			 * Text modality.
			 *
			 * @return self
			 */
			public static function text(): self {
				return new self( 'text' );
			}

			/**
			 * Image modality.
			 *
			 * @return self
			 */
			public static function image(): self {
				return new self( 'image' );
			}

			/**
			 * Modality value.
			 *
			 * @return string
			 */
			public function getValue(): string {
				return $this->value;
			}
		}
	}

	if ( ! class_exists( \WordPress\AiClient\Providers\Models\Enums\OptionEnum::class ) ) {
		/**
		 * Minimal supported-option enum stub (magic factories).
		 */
		class OptionEnum {
			/**
			 * Option value.
			 *
			 * @var string
			 */
			private string $value;

			/**
			 * Constructor.
			 *
			 * @param string $value Option name.
			 */
			private function __construct( string $value ) {
				$this->value = $value;
			}

			/**
			 * Serve the magic static factories.
			 *
			 * @param string $name Factory name.
			 * @param array  $args Factory arguments (unused).
			 * @return self
			 */
			public static function __callStatic( string $name, array $args ): self {
				unset( $args );
				return new self( $name );
			}

			/**
			 * Option value.
			 *
			 * @return string
			 */
			public function getValue(): string {
				return $this->value;
			}
		}
	}
}

namespace WordPress\AiClient\Messages\Enums {
	if ( ! class_exists( \WordPress\AiClient\Messages\Enums\ModalityEnum::class ) ) {
		/**
		 * Minimal messages modality enum stub.
		 */
		class ModalityEnum {
			/**
			 * Modality value.
			 *
			 * @var string
			 */
			private string $value;

			/**
			 * Constructor.
			 *
			 * @param string $value Modality name.
			 */
			private function __construct( string $value ) {
				$this->value = $value;
			}

			/**
			 * Text modality.
			 *
			 * @return self
			 */
			public static function text(): self {
				return new self( 'text' );
			}

			/**
			 * Image modality.
			 *
			 * @return self
			 */
			public static function image(): self {
				return new self( 'image' );
			}

			/**
			 * Modality value.
			 *
			 * @return string
			 */
			public function getValue(): string {
				return $this->value;
			}
		}
	}
}

namespace WordPress\AiClient\Providers\Models\DTO {
	if ( ! class_exists( \WordPress\AiClient\Providers\Models\DTO\SupportedOption::class ) ) {
		/**
		 * Minimal supported-option DTO stub.
		 */
		class SupportedOption {
			/**
			 * Option.
			 *
			 * @var mixed
			 */
			private $option;

			/**
			 * Allowed values.
			 *
			 * @var array
			 */
			private array $allowed;

			/**
			 * Constructor.
			 *
			 * @param mixed $option  Option.
			 * @param array $allowed Allowed values.
			 */
			public function __construct( $option, array $allowed = array() ) {
				$this->option  = $option;
				$this->allowed = $allowed;
			}

			/**
			 * Option.
			 *
			 * @return mixed
			 */
			public function getOption() {
				return $this->option;
			}

			/**
			 * Allowed values.
			 *
			 * @return array
			 */
			public function getAllowedValues(): array {
				return $this->allowed;
			}
		}
	}

	if ( ! class_exists( \WordPress\AiClient\Providers\Models\DTO\ModelMetadata::class ) ) {
		/**
		 * Minimal model-metadata DTO stub.
		 */
		class ModelMetadata {
			/**
			 * Model id.
			 *
			 * @var string
			 */
			private string $id;

			/**
			 * Display name.
			 *
			 * @var string
			 */
			private string $name;

			/**
			 * Capabilities.
			 *
			 * @var array
			 */
			private array $caps;

			/**
			 * Options.
			 *
			 * @var array
			 */
			private array $opts;

			/**
			 * Constructor.
			 *
			 * @param string $id   Model id.
			 * @param string $name Display name.
			 * @param array  $caps Capabilities.
			 * @param array  $opts Options.
			 */
			public function __construct( string $id = 'test-model', string $name = 'Test', array $caps = array(), array $opts = array() ) {
				$this->id   = $id;
				$this->name = $name;
				$this->caps = $caps;
				$this->opts = $opts;
			}

			/**
			 * Model id.
			 *
			 * @return string
			 */
			public function getId(): string {
				return $this->id;
			}

			/**
			 * Display name.
			 *
			 * @return string
			 */
			public function getName(): string {
				return $this->name;
			}

			/**
			 * Supported capabilities.
			 *
			 * @return array
			 */
			public function getSupportedCapabilities(): array {
				return $this->caps;
			}

			/**
			 * Supported options.
			 *
			 * @return array
			 */
			public function getSupportedOptions(): array {
				return $this->opts;
			}
		}
	}
}

namespace WordPress\AiClient\Providers\DTO {
	if ( ! class_exists( \WordPress\AiClient\Providers\DTO\ProviderMetadata::class ) ) {
		/**
		 * Minimal provider-metadata DTO stub.
		 */
		class ProviderMetadata {
		}
	}
}

namespace WordPress\AiClient\Common\Exception {
	if ( ! class_exists( \WordPress\AiClient\Common\Exception\RuntimeException::class ) ) {
		/**
		 * Minimal common runtime exception stub.
		 */
		class RuntimeException extends \RuntimeException {
		}
	}
}

namespace WordPress\AiClient\Providers\Http\Exception {
	if ( ! class_exists( \WordPress\AiClient\Providers\Http\Exception\ResponseException::class ) ) {
		/**
		 * Minimal response exception stub.
		 */
		class ResponseException extends \RuntimeException {
			/**
			 * Build the missing-data exception.
			 *
			 * @param string $provider Provider name.
			 * @param string $key      Missing key.
			 * @return self
			 */
			public static function fromMissingData( string $provider, string $key ): self {
				return new self( sprintf( 'Missing %s from %s.', $key, $provider ) );
			}
		}
	}
}
