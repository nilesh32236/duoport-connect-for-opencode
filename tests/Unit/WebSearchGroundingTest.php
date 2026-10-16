<?php
/**
 * Verified web-search grounding specs behind the default-deny allowlist.
 *
 * Web search is advertised only for models whose grounding contract is
 * verified: the gate-on path (verified model) carries the `webSearch`
 * option in its directory metadata, while the gate-off path (unverified
 * models, unknown catalogs) and the mis-declared path (a pending-
 * verification ID injected into the web-search list) never do — the
 * mis-declared row is filtered before metadata is even built, so no
 * hallucinated grounding can occur.
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
	use OpenCodeConnector\Metadata\ModelAllowlist;
	use OpenCodeConnector\Metadata\OpenCodeGoModelMetadataDirectory;
	use WordPress\AiClient\Providers\Http\DTO\Response;
	use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

	/**
	 * Web-search grounding gate specs.
	 *
	 * @package OpenCodeConnector
	 */
	final class WebSearchGroundingTest extends MonkeyTestCase {

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
		 * Stub translation and the listing option; reset the test seam.
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
		 * Always reset the web-search test seam so the default-deny gate is
		 * the starting point for every spec.
		 */
		protected function tearDown(): void {
			ModelAllowlist::setWebSearchCapableForTests( null );
			parent::tearDown();
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
		 * Option values advertised by parsed metadata, keyed by model ID.
		 *
		 * @param ModelMetadata[] $list Parsed metadata.
		 * @return array<string, list<string>>
		 */
		private static function option_values_by_id( array $list ): array {
			$by_id = array();
			foreach ( $list as $metadata ) {
				$values = array();
				foreach ( $metadata->getSupportedOptions() as $supported ) {
					$option = $supported->getOption();
					if ( is_object( $option ) && method_exists( $option, 'getValue' ) ) {
						$values[] = (string) $option->getValue();
					}
				}
				$by_id[ $metadata->getId() ] = $values;
			}
			return $by_id;
		}

		/**
		 * A contract-verified model advertises the webSearch option.
		 *
		 * Gate-on path: with `glm-5.3` (routable in Go) verified via the
		 * test seam, its metadata carries `webSearch` while the unverified
		 * `kimi-k3` in the same payload stays text-only.
		 */
		public function test_verified_model_advertises_web_search_option(): void {
			ModelAllowlist::setWebSearchCapableForTests( array( 'go' => array( 'glm-5.3' ), 'zen' => array() ) );

			$by_id = self::option_values_by_id(
				$this->parse_rows(
					new OpenCodeGoModelMetadataDirectory(),
					array( array( 'id' => 'glm-5.3' ), array( 'id' => 'kimi-k3' ) )
				)
			);

			self::assertArrayHasKey( 'glm-5.3', $by_id );
			self::assertContains( 'webSearch', $by_id['glm-5.3'] );
			self::assertArrayHasKey( 'kimi-k3', $by_id );
			self::assertNotContains( 'webSearch', $by_id['kimi-k3'] );
		}

		/**
		 * With the gate off, no model advertises the webSearch option.
		 *
		 * Gate-off path: the shipped allowlist is empty per catalog, so
		 * every routable model degrades to text-only without API keys or
		 * external calls.
		 */
		public function test_gate_off_omits_web_search_for_every_model(): void {
			$by_id = self::option_values_by_id(
				$this->parse_rows(
					new OpenCodeGoModelMetadataDirectory(),
					array( array( 'id' => 'glm-5.3' ), array( 'id' => 'kimi-k3' ) )
				)
			);

			foreach ( $by_id as $id => $values ) {
				self::assertNotContains( 'webSearch', $values, $id . ' must not advertise grounding while unverified.' );
			}
		}

		/**
		 * A mis-declared pending-verification model is filtered, never grounded.
		 *
		 * Even with its ID injected into the web-search list, the
		 * unsupported endpoint family drops the row before metadata is
		 * built: no `webSearch` option is emitted anywhere.
		 */
		public function test_mis_declared_pending_model_is_filtered_without_grounding(): void {
			ModelAllowlist::setWebSearchCapableForTests( array( 'go' => array( 'glm-5.1' ), 'zen' => array() ) );

			$list = $this->parse_rows(
				new OpenCodeGoModelMetadataDirectory(),
				array( array( 'id' => 'glm-5.1' ) )
			);

			self::assertSame( array(), $list, 'A pending-verification row must be filtered, not grounded.' );
		}
	}
}
