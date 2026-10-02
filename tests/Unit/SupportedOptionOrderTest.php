<?php
/**
 * Supported-option ordering specs for the text-model metadata directory.
 *
 * `AbstractOpenCodeModelMetadataDirectory` used to splice the JSON-schema
 * option into the shared option list at a hardcoded index (5). That index was
 * only correct because `buildCommonOptions()` happened to place
 * `outputMimeType` there: appending or reordering an option silently
 * relocated the schema option into the wrong slot, producing a subtly wrong
 * supported-option set with no error and no failing test.
 *
 * The options are now keyed by name and the schema option is emitted directly
 * after the named `output_mime_type` entry, so these specs pin the resulting
 * position — including after a deliberate reorder of the shared list, which
 * the hardcoded index could not survive.
 *
 * The WordPress AI Client SDK is not installed in vendor/, so this file
 * declares minimal guarded stubs in their real namespaces. Every stub is
 * guarded with class_exists(), so load order between test files can never
 * swap a weaker stand-in in.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit\Bootstrap {
	require_once __DIR__ . '/Fixtures/SdkStubs.php';
}

namespace WordPress\AiClient\Providers\Models\Enums {
	if ( ! class_exists( __NAMESPACE__ . '\ModalityEnum' ) ) {
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

	if ( ! class_exists( __NAMESPACE__ . '\OptionEnum' ) ) {
		/**
		 * Minimal option enum stub (magic factories, like the real SDK).
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
	if ( ! class_exists( __NAMESPACE__ . '\ModalityEnum' ) ) {
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
	if ( ! class_exists( __NAMESPACE__ . '\SupportedOption' ) ) {
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

	if ( ! class_exists( __NAMESPACE__ . '\ModelMetadata' ) ) {
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
			 * Supported options.
			 *
			 * @return SupportedOption[]
			 */
			public function getSupportedOptions(): array {
				return $this->opts;
			}
		}
	}
}

namespace WordPress\AiClient\Providers\Http\Exception {
	if ( ! class_exists( __NAMESPACE__ . '\ResponseException' ) ) {
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

namespace WordPress\AiClient\Providers\OpenAiCompatibleImplementation {
	if ( ! class_exists( __NAMESPACE__ . '\AbstractOpenAiCompatibleModelMetadataDirectory' ) ) {
		/**
		 * Minimal OpenAI-compatible metadata directory base stub.
		 */
		abstract class AbstractOpenAiCompatibleModelMetadataDirectory {
		}
	}
}

namespace OpenCodeConnector\Tests\Unit {
	use Brain\Monkey\Functions;
	use OpenCodeConnector\Metadata\AbstractOpenCodeModelMetadataDirectory;
	use OpenCodeConnector\Metadata\OpenCodeGoModelMetadataDirectory;
	use OpenCodeConnector\Metadata\OpenCodeZenModelMetadataDirectory;
	use WordPress\AiClient\Providers\Http\DTO\Response;
	use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
	use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
	use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

	/**
	 * Shared-option ordering regression specs.
	 *
	 * @package OpenCodeConnector
	 */
	final class SupportedOptionOrderTest extends MonkeyTestCase {

		/**
		 * Boot the plugin autoloader and the option-name constant.
		 */
		public static function setUpBeforeClass(): void {
			parent::setUpBeforeClass();
			if ( ! defined( 'OpenCodeConnector\\OPTION_NAME' ) ) {
				define( 'OpenCodeConnector\\OPTION_NAME', 'opencode_connector_settings' );
			}
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
		}

		/**
		 * Stub translation.
		 */
		protected function setUp(): void {
			parent::setUp();
			Functions\when( '__' )->alias(
				static function ( ...$args ): string {
					return (string) ( $args[0] ?? '' );
				}
			);
			Functions\when( 'get_option' )->justReturn( array( 'show_all_models' => false ) );
		}

		/**
		 * Parse rows through a directory's parse entry point.
		 *
		 * @param AbstractOpenCodeModelMetadataDirectory $directory Directory instance.
		 * @param array                                 $rows      API model rows.
		 * @return ModelMetadata[]
		 */
		private function parse_rows( $directory, array $rows ): array {
			$method = new \ReflectionMethod( $directory, 'parseResponseToModelMetadataList' );
			$method->setAccessible( true );
			return $method->invoke( $directory, new Response( array( 'data' => $rows ) ) );
		}

		/**
		 * Option names in the order the directory publishes them.
		 *
		 * @param ModelMetadata[] $list Parsed metadata.
		 * @return list<string>
		 */
		private static function option_names( array $list ): array {
			$names = array();
			foreach ( $list as $metadata ) {
				foreach ( $metadata->getSupportedOptions() as $option ) {
					self::assertInstanceOf( SupportedOption::class, $option );
					$enum     = $option->getOption();
					$names[] = is_object( $enum ) && method_exists( $enum, 'getValue' ) ? (string) $enum->getValue() : '';
				}
			}
			return $names;
		}

		/**
		 * The JSON schema option sits directly after the output MIME type.
		 *
		 * The pairing is what makes a strict-schema request usable: both
		 * options describe the response shape, so they are published as a
		 * block in that order, not at a fixed numeric slot.
		 */
		public function test_json_schema_option_follows_the_output_mime_type(): void {
			$cases = array(
				array( new OpenCodeGoModelMetadataDirectory(), 'glm-5.3' ),
				array( new OpenCodeZenModelMetadataDirectory(), 'kimi-k3' ),
			);

			foreach ( $cases as $case ) {
				list( $directory, $id ) = $case;
				$names                   = self::option_names( $this->parse_rows( $directory, array( array( 'id' => $id ) ) ) );

				$mime = array_search( 'outputMimeType', $names, true );
				self::assertNotFalse( $mime, 'The output MIME type option must be advertised for ' . $id . '.' );
				self::assertSame(
					'outputSchema',
					$names[ $mime + 1 ] ?? '',
					'The JSON schema option must be emitted directly after the output MIME type option.'
				);
			}
		}

		/**
		 * Reordering the shared list cannot relocate the schema option.
		 *
		 * This is the behavioural regression: a hardcoded splice index would
		 * place the schema option in whatever slot the reordered list happens
		 * to occupy, which is still valid-looking and silently wrong.
		 */
		public function test_json_schema_option_survives_a_reordered_shared_list(): void {
			$reordered = $this->reordered_common_options();

			$names = self::option_names( $this->options_for( $reordered, 'glm-5.3' ) );

			$mime = array_search( 'outputMimeType', $names, true );
			self::assertNotFalse( $mime, 'The output MIME type option must be advertised.' );
			self::assertSame(
				'outputSchema',
				$names[ $mime + 1 ] ?? '',
				'The schema option must follow its anchor by name, whatever position the anchor sits at.'
			);
		}

		/**
		 * DeepSeek keeps the schema option hidden, and nothing else moves.
		 */
		public function test_deepseek_still_hides_the_json_schema_option(): void {
			$names = self::option_names( $this->parse_rows( new OpenCodeZenModelMetadataDirectory(), array( array( 'id' => 'deepseek-v4-flash' ) ) ) );

			self::assertContains( 'outputMimeType', $names );
			self::assertNotContains( 'outputSchema', $names, 'DeepSeek models return malformed JSON for strict schemas.' );
		}

		/**
		 * The shared option map is keyed by name, not a positional list.
		 *
		 * @return array<string, SupportedOption>
		 */
		private function reordered_common_options(): array {
			$method = new \ReflectionMethod( OpenCodeGoModelMetadataDirectory::class, 'buildCommonOptions' );
			$method->setAccessible( true );
			$options = $method->invoke( new OpenCodeGoModelMetadataDirectory() );

			self::assertSame(
				array_keys( $options ),
				array(
					'system_instruction',
					'max_tokens',
					'temperature',
					'top_p',
					'stop_sequences',
					'output_mime_type',
					'custom_options',
					'input_modalities',
					'output_modalities',
				),
				'The shared option set must stay addressable by name so insertions can anchor to one.'
			);

			// Prepend a new entry the way a new model feature would, moving the
			// output MIME type off index 5.
			return array_merge( array( 'reasoning_effort' => new SupportedOption( OptionEnum::reasoningEffort() ) ), $options );
		}

		/**
		 * Build one row's published options from an arbitrary shared map.
		 *
		 * @param array<string, SupportedOption> $common_opts Shared option map.
		 * @param string                         $id         Model id.
		 * @return ModelMetadata[]
		 */
		private function options_for( array $common_opts, string $id ): array {
			$directory = new OpenCodeGoModelMetadataDirectory();
			$method    = new \ReflectionMethod( $directory, 'metadataForRow' );
			$method->setAccessible( true );

			return array( $method->invoke( $directory, array( 'id' => $id ), false, $common_opts ) );
		}
	}
}