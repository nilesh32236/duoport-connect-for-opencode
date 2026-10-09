<?php
/**
 * Malformed-row regression specs for the metadata directory id extraction.
 *
 * `AbstractOpenCodeModelMetadataDirectory::metadataForRow()` read
 * `$row['id']` straight out of a decoded `/models` payload and passed it into
 * `ModelRegistry::record( string $id, string $catalog )` under
 * `declare(strict_types=1)`. A row whose `id` is an array or object is
 * non-empty, so the `! $id` guard let it through and record() raised an
 * uncaught TypeError. `parseResponseToModelMetadataList()` has no try/catch,
 * so a single malformed upstream row turned the whole directory read into a
 * 500 for every user.
 *
 * The fix rejects non-scalar ids and treats them exactly like absent ids:
 * the row is filtered out, never stringified into a garbage model id. These
 * specs drive the public parse entry point with a malformed row and assert the
 * well-formed rows in the same payload still come through.
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
	 * Malformed metadata-row id regression specs.
	 *
	 * @package OpenCodeConnector
	 */
	final class MetadataRowIdTypeTest extends MonkeyTestCase {

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
		 * A row whose id is an array is filtered out, not stringified.
		 *
		 * This is the behavioural regression: with the raw `$row['id']` read the
		 * non-empty array passed the `! $id` guard and
		 * `ModelRegistry::record( string $id, ... )` raised an uncaught
		 * TypeError under strict_types, turning one malformed upstream row
		 * into a 500 for the whole directory read.
		 */
		public function test_array_row_id_is_filtered_instead_of_throwing(): void {
			Functions\when( 'get_option' )->justReturn( array( 'show_all_models' => false ) );

			$rows = array(
				array( 'id' => array( 'kimi-k3' ) ),
				array( 'id' => 'kimi-k3' ),
			);

			foreach ( array( new OpenCodeGoModelMetadataDirectory(), new OpenCodeZenModelMetadataDirectory() ) as $directory ) {
				$ids = self::ids( $this->parse_rows( $directory, $rows ) );

				self::assertSame(
					array( 'kimi-k3' ),
					$ids,
					'A non-scalar id must be treated like an absent one: the malformed row is filtered and the valid row survives.'
				);
			}
		}

		/**
		 * An object row id is filtered too, not stringified into a garbage id.
		 */
		public function test_object_row_id_is_filtered_instead_of_throwing(): void {
			Functions\when( 'get_option' )->justReturn( array( 'show_all_models' => false ) );

			$rows = array(
				(object) array( 'id' => 'glm-5' ),
				array( 'id' => array( 'nested' => array( 'deeper' => 'value' ) ) ),
				array( 'id' => 12345 ),
			);

			$ids = self::ids( $this->parse_rows( new OpenCodeGoModelMetadataDirectory(), $rows ) );

			self::assertSame(
				array(),
				$ids,
				'A non-array row and an array id must both be dropped; no "Array" id may be produced.'
			);
			self::assertNotContains( 'Array', $ids );
		}

		/**
		 * show-all mode still refuses to stringify a non-scalar id.
		 *
		 * show-all lists unreviewed models, so it is the mode most likely to
		 * hand a malformed id straight through if the guard is missing.
		 */
		public function test_show_all_mode_still_filters_non_scalar_row_id(): void {
			Functions\when( 'get_option' )->justReturn( array( 'show_all_models' => true ) );

			$ids = self::ids(
				$this->parse_rows(
					new OpenCodeZenModelMetadataDirectory(),
					array(
						array( 'id' => array( 'mystery-model' ) ),
						array( 'id' => 'mystery-model' ),
					)
				)
			);

			self::assertSame(
				array( 'mystery-model' ),
				$ids,
				'show-all must list the valid id and still drop the array id.'
			);
		}

		/**
		 * The extraction itself must reject non-scalars.
		 *
		 * Source-level companion to the behavioural specs above, so a future
		 * refactor cannot drop the guard and keep the suite green by accident.
		 */
		public function test_row_id_extraction_rejects_non_scalars(): void {
			$source = (string) file_get_contents(
				dirname( __DIR__, 2 ) . '/src/Metadata/AbstractOpenCodeModelMetadataDirectory.php'
			);

			self::assertStringContainsString(
				'is_scalar( $raw_id )',
				$source,
				'The row id must be filtered through is_scalar() before it reaches the string-typed ModelRegistry::record().'
			);
			self::assertStringNotContainsString(
				"\$id = is_array( \$row ) ? ( \$row['id'] ?? '' ) : '';",
				$source,
				'A raw $row[\'id\'] read lets an array id reach record() and throw a TypeError.'
			);
			self::assertStringContainsString(
				'ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED === ( $record[\'endpoint_family\'] ?? \'\' )',
				$source,
				'The unsupported-family filter in the consumer of ModelRegistry::record() must use the shared constant, not a bare literal, or it silently stops filtering when the constant changes.'
			);
			self::assertStringNotContainsString(
				"'unsupported' === ( \$record['endpoint_family']",
				$source,
				'A bare literal in the unsupported-family filter goes stale the moment ENDPOINT_FAMILY_UNSUPPORTED changes, and the filter fails OPEN.'
			);
		}

		/**
		 * Every consumer of ModelRegistry::record() must share the constant.
		 *
		 * The guard above reads a single file, so a bare literal reintroduced
		 * in any other endpoint-family consumer is invisible to it. ModelRadar
		 * compares the same `$record['endpoint_family']` field in two places —
		 * a positive match in buildChangeRow() and a negative match in
		 * is_supported() — and both must track the constant, or they keep
		 * matching the old value after the constant changes and the
		 * unsupported-family counters fail OPEN.
		 *
		 * The remaining 'unsupported' tokens in that file are array keys and a
		 * summary counter, not endpoint-family comparisons, so the patterns
		 * below are anchored on the comparison to leave them alone.
		 */
		public function test_model_radar_endpoint_family_comparisons_use_shared_constant(): void {
			$source = (string) file_get_contents(
				dirname( __DIR__, 2 ) . '/src/Metadata/ModelRadar.php'
			);

			self::assertStringContainsString(
				'ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED === ( $record[\'endpoint_family\'] ?? \'\' )',
				$source,
				'The positive endpoint-family comparison in ModelRadar must use the shared constant, or it silently stops matching when the constant changes.'
			);
			self::assertStringContainsString(
				'ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED !== ( $record[\'endpoint_family\'] ?? \'\' )',
				$source,
				'The negative endpoint-family comparison in ModelRadar must use the shared constant, or is_supported() fails OPEN when the constant changes.'
			);
			self::assertStringNotContainsString(
				"'unsupported' === ( \$record['endpoint_family']",
				$source,
				'A bare literal in ModelRadar\'s positive endpoint-family comparison goes stale the moment ENDPOINT_FAMILY_UNSUPPORTED changes.'
			);
			self::assertStringNotContainsString(
				"'unsupported' !== ( \$record['endpoint_family']",
				$source,
				'A bare literal in ModelRadar\'s negative endpoint-family comparison goes stale the moment ENDPOINT_FAMILY_UNSUPPORTED changes.'
			);
		}
	}
}
