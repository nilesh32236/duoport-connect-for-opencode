<?php
/**
 * Image-generation path specs.
 *
 * The WordPress AI Client SDK is a runtime dependency (WordPress 7.0+) and is
 * not installed in vendor/, so this file defines minimal guarded SDK stubs in
 * their real namespaces before exercising the plugin classes. Each stub only
 * implements the surface the plugin code touches.
 *
 * Covered:
 * - ModelAllowlist::isImageCapable() fail-open (empty IMAGE set: inert path,
 *   text generation unaffected).
 * - Image model classes exist behind the capability check (provider routing).
 * - Metadata directory never advertises imageGeneration without an
 *   image-allowlisted ID.
 * - ImageAttachmentSaver MIME/size/capability guards + Media Library save.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace WordPress\AiClient\Providers\Models\Enums {
	if ( ! class_exists( __NAMESPACE__ . '\CapabilityEnum' ) ) {
		class CapabilityEnum {
			private string $value;
			private function __construct( string $value ) {
				$this->value = $value;
			}
			public static function textGeneration(): self {
				return new self( 'text-generation' );
			}
			public static function imageGeneration(): self {
				return new self( 'image-generation' );
			}
			public static function chatHistory(): self {
				return new self( 'chat-history' );
			}
			public function isTextGeneration(): bool {
				return 'text-generation' === $this->value;
			}
			public function isImageGeneration(): bool {
				return 'image-generation' === $this->value;
			}
			public function getValue(): string {
				return $this->value;
			}
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\ModalityEnum' ) ) {
		class ModalityEnum {
			private string $value;
			private function __construct( string $value ) {
				$this->value = $value;
			}
			public static function text(): self {
				return new self( 'text' );
			}
			public static function image(): self {
				return new self( 'image' );
			}
			public function getValue(): string {
				return $this->value;
			}
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\OptionEnum' ) ) {
		class OptionEnum {
			private string $value;
			private function __construct( string $value ) {
				$this->value = $value;
			}
			public static function __callStatic( string $name, array $args ): self {
				return new self( $name );
			}
			public function getValue(): string {
				return $this->value;
			}
		}
	}
}

namespace WordPress\AiClient\Messages\Enums {
	if ( ! class_exists( __NAMESPACE__ . '\ModalityEnum' ) ) {
		class ModalityEnum {
			private string $value;
			private function __construct( string $value ) {
				$this->value = $value;
			}
			public static function text(): self {
				return new self( 'text' );
			}
			public static function image(): self {
				return new self( 'image' );
			}
			public function getValue(): string {
				return $this->value;
			}
		}
	}
}

namespace WordPress\AiClient\Providers\Models\DTO {
	use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

	if ( ! class_exists( __NAMESPACE__ . '\SupportedOption' ) ) {
		class SupportedOption {
			private $option;
			private array $allowed;
			public function __construct( $option, array $allowed = array() ) {
				$this->option  = $option;
				$this->allowed = $allowed;
			}
			public function getOption() {
				return $this->option;
			}
			public function getAllowedValues(): array {
				return $this->allowed;
			}
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\ModelMetadata' ) ) {
		class ModelMetadata {
			private string $id;
			private string $name;
			private array $caps;
			private array $opts;
			public function __construct( string $id = 'test-model', string $name = 'Test', array $caps = array(), array $opts = array() ) {
				$this->id   = $id;
				$this->name = $name;
				$this->caps = $caps;
				$this->opts = $opts;
			}
			public function getId(): string {
				return $this->id;
			}
			public function getName(): string {
				return $this->name;
			}
			/**
			 * @return CapabilityEnum[]
			 */
			public function getSupportedCapabilities(): array {
				return $this->caps;
			}
			public function getSupportedOptions(): array {
				return $this->opts;
			}
		}
	}
}

namespace WordPress\AiClient\Providers\Models\Contracts {
	if ( ! interface_exists( __NAMESPACE__ . '\ModelInterface' ) ) {
		interface ModelInterface {
		}
	}
}

namespace WordPress\AiClient\Providers\DTO {
	if ( ! class_exists( __NAMESPACE__ . '\ProviderMetadata' ) ) {
		class ProviderMetadata {
		}
	}
}

namespace WordPress\AiClient\Common\Exception {
	if ( ! class_exists( __NAMESPACE__ . '\RuntimeException' ) ) {
		class RuntimeException extends \RuntimeException {
		}
	}
}

namespace WordPress\AiClient\Providers\ApiBasedImplementation {
	if ( ! class_exists( __NAMESPACE__ . '\AbstractApiProvider' ) ) {
		abstract class AbstractApiProvider {
		}
	}
}

namespace WordPress\AiClient\Providers\OpenAiCompatibleImplementation {
	use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;

	if ( ! class_exists( __NAMESPACE__ . '\AbstractOpenAiCompatibleTextGenerationModel' ) ) {
		abstract class AbstractOpenAiCompatibleTextGenerationModel implements ModelInterface {
			public function __construct( $metadata = null, $provider = null ) {
			}
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\AbstractOpenAiCompatibleImageGenerationModel' ) ) {
		abstract class AbstractOpenAiCompatibleImageGenerationModel implements ModelInterface {
			public function __construct( $metadata = null, $provider = null ) {
			}
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\AbstractOpenAiCompatibleModelMetadataDirectory' ) ) {
		abstract class AbstractOpenAiCompatibleModelMetadataDirectory {
		}
	}
}

namespace WordPress\AiClient\Providers\Http\DTO {
	if ( ! class_exists( __NAMESPACE__ . '\Response' ) ) {
		class Response {
			private array $data;
			public function __construct( array $data = array() ) {
				$this->data = $data;
			}
			public function getData(): array {
				return $this->data;
			}
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\Request' ) ) {
		class Request {
			public function __construct( $method = null, $url = null, array $headers = array(), $data = null, $options = null ) {
			}
		}
	}
}

namespace WordPress\AiClient\Providers\Http\Enums {
	if ( ! class_exists( __NAMESPACE__ . '\HttpMethodEnum' ) ) {
		class HttpMethodEnum {
			public static function POST(): self {
				return new self();
			}
		}
	}
}

namespace WordPress\AiClient\Providers\Http\Exception {
	if ( ! class_exists( __NAMESPACE__ . '\ResponseException' ) ) {
		class ResponseException extends \RuntimeException {
			public static function fromMissingData( string $provider, string $key ): self {
				return new self( sprintf( 'Missing %s from %s.', $key, $provider ) );
			}
		}
	}
}

namespace OpenCodeConnector\Tests\Unit {
	use Brain\Monkey\Functions;
	use OpenCodeConnector\Media\ImageAttachmentSaver;
	use OpenCodeConnector\Metadata\ModelAllowlist;
	use OpenCodeConnector\Metadata\OpenCodeGoModelMetadataDirectory;
	use OpenCodeConnector\Metadata\OpenCodeZenModelMetadataDirectory;
	use OpenCodeConnector\Models\AbstractOpenCodeImageGenerationModel;
	use OpenCodeConnector\Models\OpenCodeGoImageGenerationModel;
	use OpenCodeConnector\Models\OpenCodeGoTextGenerationModel;
	use OpenCodeConnector\Models\OpenCodeZenImageGenerationModel;
	use OpenCodeConnector\Models\OpenCodeZenTextGenerationModel;
	use OpenCodeConnector\Providers\OpenCodeGoProvider;
	use OpenCodeConnector\Providers\OpenCodeZenProvider;
	use WordPress\AiClient\Providers\Http\DTO\Response;
	use WordPress\AiClient\Providers\DTO\ProviderMetadata;
	use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
	use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

	class ImageGenerationPathTest_FakeWpError {
		private string $code;
		private string $message;
		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class_alias( ImageGenerationPathTest_FakeWpError::class, 'WP_Error' );
	}

	/**
	 * Image-generation path specs.
	 */
	final class ImageGenerationPathTest extends MonkeyTestCase {

		/**
		 * Load the plugin PSR-4 autoloader once.
		 */
		public static function setUpBeforeClass(): void {
			parent::setUpBeforeClass();
			if ( ! defined( 'OpenCodeConnector\\OPTION_NAME' ) ) {
				define( 'OpenCodeConnector\\OPTION_NAME', 'opencode_connector_settings' );
			}
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
		}

		/**
		 * Stub the translation function for every test.
		 */
		protected function setUp(): void {
			parent::setUp();
			Functions\when( '__' )->alias(
				static function ( ...$args ): string {
					return (string) ( $args[0] ?? '' );
				}
			);
		}

		/**
		 * Empty IMAGE set: nothing is image-capable (fail-open, inert path).
		 */
		public function test_image_allowlist_is_empty_fail_open(): void {
			foreach ( array( 'go', 'zen' ) as $catalog ) {
				self::assertFalse( ModelAllowlist::isImageCapable( 'glm-5', $catalog ) );
				self::assertFalse( ModelAllowlist::isImageCapable( 'deepseek-v4-flash-free', $catalog ) );
				self::assertFalse( ModelAllowlist::isImageCapable( 'no-such-model', $catalog ) );
			}
			self::assertFalse( ModelAllowlist::isImageCapable( 'glm-5', 'no-such-catalog' ) );
		}

		/**
		 * Text allowlist semantics are unchanged by the image path.
		 */
		public function test_text_allowlist_unaffected(): void {
			self::assertTrue( ModelAllowlist::isAllowed( 'glm-5', 'go' ) );
			self::assertTrue( ModelAllowlist::isAllowed( 'deepseek-v4-flash-free', 'zen' ) );
			self::assertFalse( ModelAllowlist::isAllowed( 'no-such-model', 'go' ) );
		}

		/**
		 * Image model classes exist behind the SDK image contract.
		 */
		public function test_image_model_classes_extend_sdk_base(): void {
			self::assertTrue( is_subclass_of( AbstractOpenCodeImageGenerationModel::class, \WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel::class ) );
			self::assertTrue( is_subclass_of( OpenCodeGoImageGenerationModel::class, AbstractOpenCodeImageGenerationModel::class ) );
			self::assertTrue( is_subclass_of( OpenCodeZenImageGenerationModel::class, AbstractOpenCodeImageGenerationModel::class ) );
		}

		/**
		 * Provider routes imageGeneration to the Go/Zen image models.
		 */
		public function test_create_model_routes_image_capability(): void {
			$go_image  = $this->create_model_via( OpenCodeGoProvider::class, array( CapabilityEnum::imageGeneration() ) );
			$zen_image = $this->create_model_via( OpenCodeZenProvider::class, array( CapabilityEnum::imageGeneration() ) );

			self::assertInstanceOf( OpenCodeGoImageGenerationModel::class, $go_image );
			self::assertInstanceOf( OpenCodeZenImageGenerationModel::class, $zen_image );
		}

		/**
		 * Provider still routes textGeneration to the text models.
		 */
		public function test_create_model_still_routes_text_capability(): void {
			$go_text  = $this->create_model_via( OpenCodeGoProvider::class, array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ) );
			$zen_text = $this->create_model_via( OpenCodeZenProvider::class, array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ) );

			self::assertInstanceOf( OpenCodeGoTextGenerationModel::class, $go_text );
			self::assertInstanceOf( OpenCodeZenTextGenerationModel::class, $zen_text );
		}

		/**
		 * Unknown capabilities still throw.
		 */
		public function test_create_model_rejects_unknown_capability(): void {
			$this->expectException( \WordPress\AiClient\Common\Exception\RuntimeException::class );
			$this->create_model_via( OpenCodeGoProvider::class, array( CapabilityEnum::chatHistory() ) );
		}

		/**
		 * Invoke the protected provider factory via reflection.
		 *
		 * @param class-string $provider_class Provider FQCN.
		 * @param array        $caps Capabilities.
		 * @return object
		 */
		private function create_model_via( string $provider_class, array $caps ): object {
			$method = new \ReflectionMethod( $provider_class, 'createModel' );
			$method->setAccessible( true );
			return $method->invoke( null, new ModelMetadata( 'test-model', 'Test', $caps ), new ProviderMetadata() );
		}

		/**
		 * Parse a stub API response through a directory instance.
		 *
		 * @param object $directory Directory instance.
		 * @param array  $rows API model rows.
		 * @return ModelMetadata[]
		 */
		private function parse_rows( object $directory, array $rows ): array {
			$method = new \ReflectionMethod( $directory, 'parseResponseToModelMetadataList' );
			$method->setAccessible( true );
			return $method->invoke( $directory, new Response( array( 'data' => $rows ) ) );
		}

		/**
		 * With an empty IMAGE set no row may advertise imageGeneration.
		 */
		public function test_metadata_parse_stays_text_only_fail_open(): void {
			Functions\when( 'get_option' )->justReturn( array( 'show_all_models' => false ) );

			$rows = array(
				array( 'id' => 'glm-5' ),
				array( 'id' => 'deepseek-v4-flash-free' ),
				array( 'id' => 'no-such-model' ),
				array( 'id' => '' ),
			);

			foreach ( array( new OpenCodeGoModelMetadataDirectory(), new OpenCodeZenModelMetadataDirectory() ) as $directory ) {
				$list = $this->parse_rows( $directory, $rows );
				foreach ( $list as $metadata ) {
					$image_caps = array_filter(
						$metadata->getSupportedCapabilities(),
						static fn( $cap ): bool => $cap->isImageGeneration()
					);
					self::assertSame( array(), $image_caps, 'No imageGeneration capability may leak without an image-allowlisted ID.' );
				}
			}

			$go_list = $this->parse_rows( new OpenCodeGoModelMetadataDirectory(), $rows );
			$go_ids  = array_map( static fn( ModelMetadata $m ): string => $m->getId(), $go_list );
			self::assertContains( 'glm-5', $go_ids );
			self::assertNotContains( 'no-such-model', $go_ids );
		}

		/**
		 * show_all_models keeps working for text and still gates image output.
		 */
		public function test_metadata_parse_show_all_stays_text_only(): void {
			Functions\when( 'get_option' )->justReturn( array( 'show_all_models' => true ) );

			$list = $this->parse_rows( new OpenCodeZenModelMetadataDirectory(), array( array( 'id' => 'mystery-model' ) ) );
			self::assertCount( 1, $list );
			$text_caps = array_filter(
				$list[0]->getSupportedCapabilities(),
				static fn( $cap ): bool => $cap->isTextGeneration()
			);
			self::assertNotEmpty( $text_caps );
			$image_caps = array_filter(
				$list[0]->getSupportedCapabilities(),
				static fn( $cap ): bool => $cap->isImageGeneration()
			);
			self::assertSame( array(), $image_caps );
		}

		/**
		 * MIME guard matrix.
		 */
		public function test_mime_guard_matrix(): void {
			self::assertTrue( ImageAttachmentSaver::is_allowed_mime( 'image/png' ) );
			self::assertTrue( ImageAttachmentSaver::is_allowed_mime( 'image/jpeg' ) );
			self::assertTrue( ImageAttachmentSaver::is_allowed_mime( 'image/webp' ) );
			self::assertTrue( ImageAttachmentSaver::is_allowed_mime( 'IMAGE/PNG' ) );
			self::assertFalse( ImageAttachmentSaver::is_allowed_mime( 'image/gif' ) );
			self::assertFalse( ImageAttachmentSaver::is_allowed_mime( 'image/svg+xml' ) );
			self::assertFalse( ImageAttachmentSaver::is_allowed_mime( 'text/plain' ) );
			self::assertFalse( ImageAttachmentSaver::is_allowed_mime( '' ) );
		}

		/**
		 * Size guard matrix.
		 */
		public function test_size_guard_matrix(): void {
			self::assertTrue( ImageAttachmentSaver::is_within_size_limit( 0 ) );
			self::assertTrue( ImageAttachmentSaver::is_within_size_limit( ImageAttachmentSaver::MAX_BYTES ) );
			self::assertFalse( ImageAttachmentSaver::is_within_size_limit( ImageAttachmentSaver::MAX_BYTES + 1 ) );
			self::assertFalse( ImageAttachmentSaver::is_within_size_limit( -1 ) );
		}

		/**
		 * Validation rejects empty, wrong-MIME, and oversized payloads.
		 */
		public function test_validate_rejects_bad_payloads(): void {
			$empty = ImageAttachmentSaver::validate( '', 'image/png' );
			self::assertInstanceOf( \WP_Error::class, $empty );

			$mime = ImageAttachmentSaver::validate( 'bytes', 'image/gif' );
			self::assertInstanceOf( \WP_Error::class, $mime );
			self::assertSame( 'opencode_image_mime', $mime->get_error_code() );

			$big = ImageAttachmentSaver::validate( str_repeat( 'a', ImageAttachmentSaver::MAX_BYTES + 1 ), 'image/png' );
			self::assertInstanceOf( \WP_Error::class, $big );
			self::assertSame( 'opencode_image_size', $big->get_error_code() );

			self::assertTrue( ImageAttachmentSaver::validate( 'bytes', 'image/png' ) );
		}

		/**
		 * Upload requires the upload_files capability.
		 */
		public function test_save_requires_upload_capability(): void {
			Functions\when( 'current_user_can' )->alias(
				static function ( ...$args ): bool {
					return false;
				}
			);

			$result = ImageAttachmentSaver::save_to_media_library( 'bytes', 'image/png' );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'opencode_image_capability', $result->get_error_code() );
		}

		/**
		 * Upload errors and bad payloads surface as WP_Error without touching uploads.
		 */
		public function test_save_rejects_before_upload(): void {
			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'wp_upload_bits' )->alias(
				static function ( ...$args ): array {
					self::fail( 'wp_upload_bits must not run when guards reject the payload.' );
				}
			);

			$result = ImageAttachmentSaver::save_to_media_library( 'bytes', 'image/gif' );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'opencode_image_mime', $result->get_error_code() );
		}

		/**
		 * Failed uploads surface the uploader error.
		 */
		public function test_save_surfaces_upload_error(): void {
			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'sanitize_file_name' )->alias(
				static function ( ...$args ): string {
					return (string) ( $args[0] ?? '' );
				}
			);
			Functions\when( 'wp_upload_bits' )->justReturn( array( 'error' => 'disk full' ) );

			$result = ImageAttachmentSaver::save_to_media_library( 'bytes', 'image/png', 'sunset' );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'opencode_image_upload', $result->get_error_code() );
		}

		/**
		 * Happy path inserts the attachment and generates metadata.
		 */
		public function test_save_inserts_attachment(): void {
			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'sanitize_file_name' )->alias(
				static function ( ...$args ): string {
					return (string) ( $args[0] ?? '' );
				}
			);
			$seen_upload = null;
			$seen_insert = null;
			Functions\when( 'wp_upload_bits' )->alias(
				static function ( ...$args ) use ( &$seen_upload ): array {
					$seen_upload = $args;
					return array(
						'file'  => '/uploads/sunset.png',
						'url'   => 'https://example.test/uploads/sunset.png',
						'error' => false,
					);
				}
			);
			Functions\when( 'wp_insert_attachment' )->alias(
				static function ( ...$args ) use ( &$seen_insert ): int {
					$seen_insert = $args;
					return 123;
				}
			);
			Functions\when( 'wp_generate_attachment_metadata' )->justReturn( array( 'file' => 'sunset.png' ) );
			$meta_seen = null;
			Functions\when( 'wp_update_attachment_metadata' )->alias(
				static function ( ...$args ) use ( &$meta_seen ): bool {
					$meta_seen = $args;
					return true;
				}
			);

			$result = ImageAttachmentSaver::save_to_media_library( 'bytes', 'image/png', 'sunset' );
			self::assertSame( 123, $result );
			self::assertSame( 'sunset.png', $seen_upload[0] );
			self::assertSame( 'image/png', $seen_insert[0]['post_mime_type'] );
			self::assertSame( '/uploads/sunset.png', $seen_insert[1] );
			self::assertSame( array( 123, array( 'file' => 'sunset.png' ) ), $meta_seen );
		}
	}
}
