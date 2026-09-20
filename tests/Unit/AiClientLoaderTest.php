<?php
/**
 * Dual-stack AI client loader specs (WP 6.9 bundled SDK + WP 7.0 core).
 *
 * Locks in the core-parity hardening: the core-bundled client wins when
 * present, the Composer-bundled SDK loads only when core classes are absent
 * (and only when its autoload file exists), provider/model creation degrades
 * to catchable exceptions instead of fatals, and no removed AiClient init or
 * prompt APIs are called on either stack.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit\Bootstrap {
	require_once __DIR__ . '/Fixtures/SdkStubs.php';
}

namespace OpenCodeConnector\Tests\Unit {

	use Brain\Monkey\Functions;
	use OpenCodeConnector\Compat\AiClientLoader;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;

	/**
	 * Dual-stack loader specs.
	 */
	final class AiClientLoaderTest extends MonkeyTestCase {

		/**
		 * Load the plugin PSR-4 autoloader once.
		 */
		public static function setUpBeforeClass(): void {
			parent::setUpBeforeClass();
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
		}

		/**
		 * Core-present path: existing AiClient means true without bundled load.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_ensure_prefers_core_when_present(): void {
			if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
				eval( 'namespace WordPress\\AiClient; class AiClient { const VERSION = "1.3.0"; public static function defaultRegistry(): object { throw new \\RuntimeException( "unused" ); } public static function getCache(): object { throw new \\RuntimeException( "unused" ); } }' );
			}
			self::assertTrue( AiClientLoader::ensure_ai_client_loaded() );
			// Core wins: the bundled autoload must not be required for this.
			self::assertTrue( class_exists( \WordPress\AiClient\AiClient::class ) );
		}

		/**
		 * Core-absent path: missing core + missing bundled file degrades to false.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_ensure_returns_false_when_core_and_bundled_absent(): void {
			self::assertFalse( class_exists( \WordPress\AiClient\AiClient::class ) );
			self::assertFalse( AiClientLoader::ensure_ai_client_loaded() );
		}

		/**
		 * Version floor: 6.9 and 7.0 pass, 6.8 fails.
		 */
		public function test_supported_wp_version_floor(): void {
			Functions\when( 'get_bloginfo' )->alias(
				static function ( ...$args ): string {
					return '6.9';
				}
			);
			self::assertTrue( AiClientLoader::is_supported_wp_version() );
		}

		/**
		 * Version floor rejects WP below 6.9.
		 */
		public function test_supported_wp_version_rejects_old_wp(): void {
			Functions\when( 'get_bloginfo' )->alias(
				static function ( ...$args ): string {
					return '6.8';
				}
			);
			self::assertFalse( AiClientLoader::is_supported_wp_version() );
		}

		/**
		 * Loader source must carry every guard family.
		 */
		public function test_loader_source_has_all_guards(): void {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Compat/AiClientLoader.php' );
			self::assertStringContainsString( 'class_exists', $source );
			self::assertStringContainsString( 'function_exists', $source );
			self::assertStringContainsString( 'file_exists', $source );
			self::assertStringContainsString( 'version_compare', $source );
			self::assertStringContainsString( 'has_filter', $source );
			self::assertStringContainsString( 'defined(', $source );
			self::assertStringContainsString( 'require_once', $source );
		}

		/**
		 * Bundled SDK must load conditionally only (never unconditional).
		 */
		public function test_no_unconditional_composer_autoload(): void {
			$root = dirname( __DIR__, 2 );
			$iter = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src' ) );
			foreach ( $iter as $file ) {
				if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
					continue;
				}
				$source = (string) file_get_contents( (string) $file );
				if ( str_contains( $source, 'vendor/wordpress/ai-client' ) ) {
					self::assertStringContainsString( 'file_exists', $source, (string) $file . ': bundled SDK loads must be file_exists-guarded.' );
					self::assertDoesNotMatchRegularExpression( '/^\s*require(_once)?\s+.*vendor\/wordpress\/ai-client/m', $source, (string) $file . ': no top-level unconditional bundled require.' );
				}
				self::assertDoesNotMatchRegularExpression( '/require(_once)?\s+.*vendor\/autoload\.php/', $source, (string) $file . ': production code must not require the top-level Composer autoloader.' );
			}
		}

		/**
		 * No removed AiClient init or prompt APIs on either stack.
		 */
		public function test_no_removed_api_calls(): void {
			$sources = array(
				(string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Providers/AbstractOpenCodeProvider.php' ),
				(string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Models/AbstractOpenCodeTextGenerationModel.php' ),
				(string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Models/AbstractOpenCodeImageGenerationModel.php' ),
				(string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Compat/AiClientLoader.php' ),
				(string) file_get_contents( dirname( __DIR__, 2 ) . '/duoport-connect-for-opencode.php' ),
			);
			foreach ( $sources as $source ) {
				self::assertStringNotContainsString( 'AiClient::init(', $source );
				self::assertStringNotContainsString( '->prompt(', $source );
				self::assertStringNotContainsString( '::prompt(', $source );
			}
		}

		/**
		 * Bootstrap must route through the loader with a 6.9 floor.
		 */
		public function test_bootstrap_uses_loader_with_69_floor(): void {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/duoport-connect-for-opencode.php' );
			self::assertStringContainsString( 'AiClientLoader::ensure_ai_client_loaded', $source );
			self::assertStringContainsString( 'AiClientLoader::is_supported_wp_version', $source );
			self::assertStringContainsString( '6.9', $source );
			self::assertStringContainsString( 'function_exists', (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Compat/AiClientLoader.php' ) );
			self::assertStringContainsString( 'class_exists', $source );
		}

		/**
		 * Provider metadata must fail open when the DTO is missing.
		 */
		public function test_provider_metadata_guards_provider_metadata_class(): void {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Providers/AbstractOpenCodeProvider.php' );
			self::assertStringContainsString( 'class_exists( ProviderMetadata::class )', $source );
		}

		/**
		 * Model request creation must guard the SDK DTO on both stacks.
		 */
		public function test_model_create_request_guards_request_dto(): void {
			foreach ( array( 'AbstractOpenCodeTextGenerationModel.php', 'AbstractOpenCodeImageGenerationModel.php' ) as $leaf ) {
				$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Models/' . $leaf );
				self::assertStringContainsString( 'class_exists( Request::class )', $source, $leaf );
			}
		}

		/**
		 * Plugin header floor must be 6.9 (dual-stack).
		 */
		public function test_plugin_header_floor_is_69(): void {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/duoport-connect-for-opencode.php' );
			self::assertStringContainsString( 'Requires at least: 6.9', $source );
		}
	}
}
