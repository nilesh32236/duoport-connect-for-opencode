<?php
/**
 * Free-tier models that require the OpenCode client/harness.
 *
 * OpenCode serves some free-tier chat IDs only through its own client, so
 * offering them as ordinary WordPress chat models leads users into a 403
 * `FreeUsageLimitError` they cannot resolve. These specs pin the picker
 * behaviour: hidden by default, and labeled explicitly under show-all.
 *
 * The WordPress AI Client SDK is not installed in vendor/, so the shared
 * Fixtures/SdkStubs.php stand-ins cover the DTOs and enums the directory
 * touches. No stubs are declared inline.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit\Bootstrap {
	require_once __DIR__ . '/Fixtures/SdkStubs.php';
}

namespace OpenCodeConnector\Tests\Unit {
	use Brain\Monkey\Functions;
	use OpenCodeConnector\Metadata\OpenCodeGoModelMetadataDirectory;
	use OpenCodeConnector\Metadata\OpenCodeZenModelMetadataDirectory;
	use WordPress\AiClient\Providers\Http\DTO\Response;
	use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

	/**
	 * Client-required free-tier picker specs.
	 *
	 * @package OpenCodeConnector
	 */
	final class ClientRequiredModelsTest extends MonkeyTestCase {

		/**
		 * Client-required IDs covered by this spec.
		 *
		 * @var list<string>
		 */
		private const IDS = array( 'nemotron-3-ultra-free', 'nemotron-3.5-lightning-free', 'big-pickle' );

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
		}

		/**
		 * Parse rows through a directory's parse entry point.
		 *
		 * @param object $directory Directory instance.
		 * @param array  $rows      API model rows.
		 * @return ModelMetadata[]
		 */
		private function parse_rows( object $directory, array $rows ): array {
			$method = new \ReflectionMethod( $directory, 'parseResponseToModelMetadataList' );
			$method->setAccessible( true );
			return $method->invoke( $directory, new Response( array( 'data' => $rows ) ) );
		}

		/**
		 * Ids produced by a directory parse.
		 *
		 * @param ModelMetadata[] $list Parsed metadata.
		 * @return list<string>
		 */
		private static function ids( array $list ): array {
			return array_map(
				static fn( ModelMetadata $metadata ): string => $metadata->getId(),
				$list
			);
		}

		/**
		 * Display names keyed by model id.
		 *
		 * @param ModelMetadata[] $list Parsed metadata.
		 * @return array<string, string>
		 */
		private static function names_by_id( array $list ): array {
			$names = array();
			foreach ( $list as $metadata ) {
				$names[ $metadata->getId() ] = $metadata->getName();
			}
			return $names;
		}

		/**
		 * Client-required free-tier models are not offered by default.
		 */
		public function test_client_required_models_are_hidden_by_default(): void {
			Functions\when( 'get_option' )->justReturn( array( 'show_all_models' => false ) );

			$rows = array(
				array( 'id' => 'nemotron-3-ultra-free' ),
				array( 'id' => 'nemotron-3.5-lightning-free' ),
				array( 'id' => 'big-pickle' ),
				array( 'id' => 'glm-5.2' ),
			);

			$ids = self::ids( $this->parse_rows( new OpenCodeZenModelMetadataDirectory(), $rows ) );

			foreach ( self::IDS as $id ) {
				self::assertNotContains( $id, $ids, $id . ' requires the OpenCode app and must not be offered by default.' );
			}
			self::assertContains( 'glm-5.2', $ids, 'Ordinary reviewed models stay visible.' );
		}

		/**
		 * Show-all exposes client-required models with the explicit label.
		 */
		public function test_show_all_labels_client_required_models(): void {
			Functions\when( 'get_option' )->justReturn( array( 'show_all_models' => true ) );

			$rows = array(
				array( 'id' => 'nemotron-3-ultra-free' ),
				array( 'id' => 'nemotron-3.5-lightning-free' ),
				array( 'id' => 'big-pickle' ),
				array( 'id' => 'glm-5.2' ),
			);

			$names = self::names_by_id( $this->parse_rows( new OpenCodeZenModelMetadataDirectory(), $rows ) );

			foreach ( self::IDS as $id ) {
				self::assertArrayHasKey( $id, $names, 'Show-all must still expose ' . $id . '.' );
				self::assertStringContainsString(
					'(Requires OpenCode app)',
					$names[ $id ],
					$id . ' must be labeled as requiring the OpenCode app.'
				);
				self::assertStringNotContainsString(
					'(Free)',
					$names[ $id ],
					$id . ' must not carry the plain free label while it cannot run from WordPress.'
				);
			}
			self::assertStringNotContainsString( '(Requires OpenCode app)', $names['glm-5.2'] );
		}

		/**
		 * The client-required label never crosses catalogs.
		 */
		public function test_client_required_label_is_go_isolated(): void {
			Functions\when( 'get_option' )->justReturn( array( 'show_all_models' => true ) );

			$rows = array();
			foreach ( self::IDS as $id ) {
				$rows[] = array( 'id' => $id );
			}

			$names = self::names_by_id( $this->parse_rows( new OpenCodeGoModelMetadataDirectory(), $rows ) );

			foreach ( self::IDS as $id ) {
				self::assertArrayHasKey( $id, $names, 'Show-all lists the live Go row.' );
				self::assertStringNotContainsString(
					'(Requires OpenCode app)',
					$names[ $id ],
					'The Zen client-required set must not relabel the Go picker.'
				);
			}
		}
	}
}
