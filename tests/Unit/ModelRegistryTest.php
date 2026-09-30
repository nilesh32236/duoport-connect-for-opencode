<?php
/**
 * Tests for the curated capability-aware model registry.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use OpenCodeConnector\Metadata\Catalog;
use OpenCodeConnector\Metadata\ModelAllowlist;
use OpenCodeConnector\Metadata\ModelRegistry;
use OpenCodeConnector\Transport\EndpointRoute;

final class ModelRegistryTest extends MonkeyTestCase {

	/**
	 * Curated records expose provenance and conservative capabilities.
	 */
	public function test_record_contains_reviewed_registry_fields(): void {
		$record = ModelRegistry::record( 'glm-5.3', 'go' );

		self::assertIsArray( $record );
		self::assertSame( 'glm-5.3', $record['id'] );
		self::assertSame( 'go', $record['catalog'] );
		self::assertSame( 'chat', $record['endpoint_family'] );
		self::assertSame( 'legacy-verified', $record['verification_status'] );
		self::assertSame( '2026-09-24', $record['last_verified'] );
		self::assertTrue( $record['capabilities']['text'] );
		self::assertFalse( $record['capabilities']['image'] );
		self::assertFalse( $record['capabilities']['web_search'] );
		self::assertTrue( ModelRegistry::isReviewed( $record ) );
	}

	/**
	 * The Zen MiniMax records are documented on the Zen chat/completions
	 * endpoint, so they are routable and visible instead of allowlisted-but-
	 * unroutable (which hid them from the picker and then threw at generation).
	 */
	public function test_documented_zen_chat_routes_are_routable(): void {
		foreach ( array( 'minimax-m3', 'minimax-m2.7', 'minimax-m2.5' ) as $id ) {
			$record = ModelRegistry::record( $id, 'zen' );
			self::assertIsArray( $record );
			self::assertSame( 'chat', $record['endpoint_family'] );
			self::assertSame( 'legacy-verified', $record['verification_status'] );
			self::assertTrue( $record['capabilities']['text'] );
			self::assertSame( 'chat/completions', EndpointRoute::pathForModel( $id, 'zen' ) );
		}
	}

	/**
	 * Allowlisted IDs whose family is not re-confirmed stay routable but are
	 * reported as verification-required instead of inheriting a review date.
	 */
	public function test_pending_families_are_routable_but_not_reviewed(): void {
		foreach ( array( 'glm-5.1', 'glm-5', 'kimi-k2.5', 'mimo-v2-pro', 'mimo-v2-omni', 'hy3-preview' ) as $id ) {
			$record = ModelRegistry::record( $id, 'go' );
			self::assertIsArray( $record, $id );
			self::assertSame( 'chat', $record['endpoint_family'], $id );
			self::assertSame( 'verification-required', $record['verification_status'], $id );
			self::assertSame( '', $record['last_verified'], $id . ' must not inherit a review date.' );
			self::assertTrue( $record['capabilities']['text'], $id );
			self::assertTrue( $record['capabilities']['tools'], $id );
			self::assertFalse( ModelRegistry::isReviewed( $record ), $id );
			// Still routable: an undocumented table entry is not evidence of a
			// different family, and hiding a working model would break callers.
			self::assertSame( 'chat/completions', EndpointRoute::pathForModel( $id, 'go' ) );
		}
	}

	/**
	 * No allowlisted model may be allowlisted-but-unroutable.
	 *
	 * Every ALLOW entry must have a recorded family (reviewed or explicitly
	 * pending), so adding an ID without the evidence fails this ratchet instead
	 * of shipping a model that is hidden from the picker and throws at
	 * generation.
	 */
	public function test_every_allowlisted_id_has_a_recorded_family(): void {
		foreach ( Catalog::ALL as $catalog ) {
			$ids = ModelAllowlist::allowedIds( $catalog );
			self::assertNotEmpty( $ids );
			foreach ( $ids as $id ) {
				$record = ModelRegistry::record( $id, $catalog );
				self::assertIsArray( $record, $catalog . '/' . $id );
				self::assertNotSame(
					ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED,
					$record['endpoint_family'],
					$catalog . '/' . $id . ' has no recorded endpoint family.'
				);
				self::assertNotSame(
					ModelRegistry::VERIFICATION_STATUS_NEEDS_ADAPTER,
					$record['verification_status'],
					$catalog . '/' . $id . ' is allowlisted but unroutable.'
				);
			}
		}
	}

	/**
	 * A reviewed ID must never also be listed as pending.
	 *
	 * ENDPOINT_FAMILIES membership is the evidence, and it wins the lookup, so
	 * an ID recorded in both maps would keep routing but lose its review date.
	 * This ratchet catches the overlap at the source table instead of letting a
	 * future edit silently demote a reviewed model to verification-required.
	 */
	public function test_reviewed_and_pending_family_tables_do_not_overlap(): void {
		$reviewed = self::private_const( 'ENDPOINT_FAMILIES' );
		$pending  = self::private_const( 'PENDING_FAMILIES' );
		self::assertNotEmpty( $reviewed );
		self::assertNotEmpty( $pending );

		foreach ( Catalog::ALL as $catalog ) {
			$ids          = ModelAllowlist::allowedIds( $catalog );
			$reviewed_ids = array_keys( $reviewed[ $catalog ] ?? array() );
			$pending_ids  = $pending[ $catalog ] ?? array();
			foreach ( array( $reviewed_ids, $pending_ids ) as $recorded ) {
				self::assertSame(
					array(),
					array_values( array_diff( $recorded, $ids ) ),
					$catalog . ' records a family for a non-allowlisted ID.'
				);
			}
			$overlap = array_values( array_intersect( $reviewed_ids, $pending_ids ) );
			self::assertSame(
				array(),
				$overlap,
				$catalog . ' lists ' . implode( ',', $overlap ) . ' as both reviewed and pending.'
			);
		}
	}

	/**
	 * Read one of the registry's private family tables.
	 *
	 * @param string $name Constant name.
	 * @return array<string, mixed>
	 */
	private static function private_const( string $name ): array {
		$constant = new \ReflectionClassConstant( ModelRegistry::class, $name );
		$value    = $constant->getValue();
		self::assertIsArray( $value );
		return $value;
	}

	/**
	 * Unknown IDs and unsupported capabilities are default-deny.
	 */
	public function test_unknown_models_and_capabilities_are_denied(): void {
		self::assertNull( ModelRegistry::record( 'unreviewed-model', 'go' ) );
		self::assertFalse( ModelRegistry::supports( 'unreviewed-model', 'go', 'tools' ) );
		self::assertFalse( ModelRegistry::supports( 'deepseek-v4-pro', 'go', 'tools' ) );
		self::assertFalse( ModelRegistry::supports( 'glm-5.3', 'go', 'unknown-capability' ) );
	}

	/**
	 * Catalog records are separated and free labels are per-catalog.
	 */
	public function test_catalogs_and_free_labels_are_explicit(): void {
		$go_records  = ModelRegistry::records( 'go' );
		$zen_records = ModelRegistry::records( 'zen' );
		$zen_free    = ModelRegistry::record( 'big-pickle', 'zen' );

		self::assertNotEmpty( $go_records );
		self::assertNotEmpty( $zen_records );
		self::assertIsArray( $zen_free );
		self::assertTrue( $zen_free['free'] );
		self::assertSame( 'zen', $zen_free['catalog'] );
		self::assertTrue( ModelAllowlist::isFree( 'big-pickle', 'zen' ) );
		self::assertFalse( ModelAllowlist::isFree( 'hy3-free', 'zen' ) );
		self::assertFalse( ModelAllowlist::isFree( 'laguna-s-2.1-free', 'zen' ) );
	}

	/**
	 * Free status is a per-catalog fact, never a flat ID lookup.
	 *
	 * The Go catalog already serves free-named models; a shared list would
	 * label them `(Free)`, sort them to the top of the Go picker, and silently
	 * drop their function-calling support.
	 */
	public function test_free_labels_never_cross_catalogs(): void {
		foreach ( array( 'big-pickle', 'mimo-v2.5-free', 'nemotron-3-ultra-free', 'nemotron-3.5-lightning-free' ) as $id ) {
			self::assertFalse( ModelAllowlist::isFree( $id, 'go' ), $id );
		}
		// A free-named ID OpenCode serves on Go stays a free-name candidate.
		self::assertFalse( ModelAllowlist::isFree( 'space-bunny-free', 'go' ) );
		self::assertFalse( ModelAllowlist::isFree( 'longcat-2.5-preview-free', 'go' ) );
	}

	/**
	 * Every reviewed free record is a routable chat record.
	 *
	 * A `(Free)` label is a promise, so it may only sit on a model that is
	 * allowlisted for the catalog, routable, and individually reviewed. Free
	 * evidence itself comes from OpenCode's published Zen pricing table (the
	 * live `/models` payload has no free or pricing field), cited on the
	 * ModelAllowlist::FREE table.
	 */
	public function test_free_records_are_reviewed_and_routable(): void {
		foreach ( Catalog::ALL as $catalog ) {
			foreach ( ModelRegistry::records( $catalog ) as $record ) {
				if ( true !== $record['free'] ) {
					continue;
				}
				self::assertSame( 'chat', $record['endpoint_family'], $record['id'] );
				self::assertTrue( ModelRegistry::isReviewed( $record ), $record['id'] );
				self::assertTrue( $record['capabilities']['text'], $record['id'] );
			}
		}
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Metadata/ModelAllowlist.php' );
		self::assertStringContainsString( 'https://opencode.ai/docs/zen/', $source, 'Free evidence must cite a source.' );
		self::assertMatchesRegularExpression( '/accessed 20[0-9]{2}-[0-9]{2}-[0-9]{2}/', $source, 'Free evidence must carry an access date.' );
	}

	/**
	 * The published deny table names exactly the known non-chat families.
	 *
	 * @param string $family   Endpoint family under test.
	 * @param bool   $expected Whether the family should be reported denied.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unsupportedFamilyProvider' )]
	public function test_is_unsupported_family_covers_the_published_table( string $family, bool $expected ): void {
		self::assertSame( $expected, ModelRegistry::isUnsupportedFamily( $family ) );
	}

	/**
	 * Deny-table fixtures.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function unsupportedFamilyProvider(): array {
		return array(
			'responses'         => array( 'responses', true ),
			'messages'          => array( 'messages', true ),
			'systemone'         => array( 'systemone', true ),
			'provider-specific' => array( 'provider-specific', true ),
			'chat'              => array( 'chat', false ),
			'unsupported'       => array( 'unsupported', false ),
			'unknown'           => array( 'some-future-family', false ),
			'empty'             => array( '', false ),
		);
	}

	/**
	 * A model id that merely looks like a family name is still chat-routed.
	 *
	 * Guards against comparing a model id against the family deny table.
	 */
	public function test_model_id_matching_a_family_name_is_not_treated_as_a_family(): void {
		$record = ModelRegistry::record( 'responses', 'go' );

		self::assertNull( $record, 'A family name is not an allowlisted model id.' );
		self::assertFalse( ModelRegistry::isUnsupportedFamily( 'responses' ) === false );
	}

	/**
	 * The route and verification guards must read the shared constant.
	 *
	 * These comparisons decide whether a model is routed or denied, and
	 * EndpointRoute.php already compares against
	 * ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED. A bare 'unsupported' literal
	 * here would keep matching the old value if the constant ever changed, so
	 * a model whose family is not implemented would be routed anyway and the
	 * fail-closed guarantee this class documents would silently disappear.
	 */
	public function test_unsupported_family_guards_use_the_shared_constant(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Metadata/ModelRegistry.php' );

		self::assertStringContainsString(
			'self::ENDPOINT_FAMILY_UNSUPPORTED !== $endpoint_family',
			$source,
			'The route guard must compare against ENDPOINT_FAMILY_UNSUPPORTED.'
		);
		self::assertStringContainsString(
			'return array( self::ENDPOINT_FAMILY_UNSUPPORTED, false );',
			$source,
			'An unclassified model must fail closed to the shared sentinel.'
		);
		self::assertStringContainsString(
			'self::ENDPOINT_FAMILY_UNSUPPORTED !== ( $record[\'endpoint_family\'] ?? \'\' )',
			$source,
			'The reviewed-support guard must compare against ENDPOINT_FAMILY_UNSUPPORTED.'
		);
		self::assertStringNotContainsString(
			"'unsupported' !== \$endpoint_family",
			$source,
			'A bare literal in the route guard goes stale the moment the constant changes.'
		);
		self::assertStringNotContainsString(
			"'unsupported' === \$endpoint_family",
			$source,
			'A bare literal in the verification guard goes stale the moment the constant changes.'
		);
		self::assertStringNotContainsString(
			"'unsupported' === ( \$record['endpoint_family']",
			$source,
			'A bare literal in the reviewed-support guard goes stale the moment the constant changes.'
		);
	}

	/**
	 * Documented model counts are derived from the routable registry.
	 *
	 * The published counts are the reviewed allowlist, not the number of IDs
	 * that happen to be routable today; deriving them here keeps README.md and
	 * readme.txt from drifting away from the registry again.
	 */
	public function test_documented_model_counts_match_the_routable_registry(): void {
		$counts = array();
		foreach ( Catalog::ALL as $catalog ) {
			$counts[ $catalog ] = count(
				array_filter(
					ModelRegistry::records( $catalog ),
					static function ( array $record ): bool {
						return ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED !== ( $record['endpoint_family'] ?? '' );
					}
				)
			);
		}
		$readme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/README.md' );

		self::assertStringContainsString(
			sprintf( 'Go: %d, Zen: %d', $counts[ Catalog::GO ], $counts[ Catalog::ZEN ] ),
			$readme,
			'README.md must state the routable model counts from ModelRegistry.'
		);
	}
}
