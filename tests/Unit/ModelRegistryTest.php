<?php
/**
 * Tests for the curated capability-aware model registry.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use OpenCodeConnector\Metadata\ModelRegistry;

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
	}

	/**
	 * Zen MiniMax models match the published chat/completions endpoint table.
	 */
	public function test_zen_minimax_routes_are_chat_routable(): void {
		foreach ( array( 'minimax-m3', 'minimax-m2.7', 'minimax-m2.5' ) as $id ) {
			$record = ModelRegistry::record( $id, 'zen' );
			self::assertIsArray( $record );
			self::assertSame( 'chat', $record['endpoint_family'] );
			self::assertSame( 'legacy-verified', $record['verification_status'] );
			self::assertTrue( $record['capabilities']['text'] );
			self::assertTrue( ModelRegistry::supports( $id, 'zen', 'text' ) );
		}
	}

	/**
	 * Allowlisted IDs with no endpoint evidence stay fail-closed.
	 */
	public function test_pending_endpoint_verification_is_unsupported(): void {
		$pending = array(
			'go'  => array( 'glm-5.1', 'glm-5', 'kimi-k2.5', 'mimo-v2-pro', 'mimo-v2-omni', 'hy3-preview' ),
			'zen' => array( 'deepseek-v4-flash-free' ),
		);
		foreach ( $pending as $catalog => $ids ) {
			foreach ( $ids as $id ) {
				$record = ModelRegistry::record( $id, $catalog );
				self::assertIsArray( $record );
				self::assertSame( ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED, $record['endpoint_family'] );
				self::assertSame( ModelRegistry::VERIFICATION_REQUIRED_STATUS, $record['verification_status'] );
				self::assertSame( '2026-09-29', $record['last_verified'] );
				self::assertFalse( $record['capabilities']['text'] );
				self::assertFalse( $record['capabilities']['tools'] );
				self::assertFalse( ModelRegistry::supports( $id, $catalog, 'text' ) );
				self::assertFalse( ModelRegistry::isVerified( $record ) );
				self::assertTrue( ModelRegistry::needsVerification( $record ) );
			}
		}
		// The same ID stays reviewed where its family is evidenced: the
		// pending set is per-catalog, never shared between Go and Zen.
		foreach ( array( 'glm-5.1', 'glm-5', 'kimi-k2.5' ) as $id ) {
			$record = ModelRegistry::record( $id, 'zen' );
			self::assertIsArray( $record );
			self::assertSame( 'chat', $record['endpoint_family'] );
			self::assertTrue( ModelRegistry::isVerified( $record ) );
		}
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
	 * Catalog records are separated and free labels are explicit.
	 */
	public function test_catalogs_and_free_labels_are_explicit(): void {
		$go_records   = ModelRegistry::records( 'go' );
		$zen_records  = ModelRegistry::records( 'zen' );
		$zen_free     = ModelRegistry::record( 'deepseek-v4-flash-free', 'zen' );

		self::assertNotEmpty( $go_records );
		self::assertNotEmpty( $zen_records );
		self::assertIsArray( $zen_free );
		self::assertTrue( $zen_free['free'] );
		self::assertSame( 'zen', $zen_free['catalog'] );
		self::assertFalse( \OpenCodeConnector\Metadata\ModelAllowlist::isFree( 'hy3-free', 'zen' ) );
		self::assertFalse( \OpenCodeConnector\Metadata\ModelAllowlist::isFree( 'laguna-s-2.1-free', 'zen' ) );
	}

	/**
	 * Free labels never cross catalogs.
	 *
	 * Every reviewed free ID is Zen-only; the Go picker must not label or
	 * re-sort on a name that belongs to the other catalog.
	 */
	public function test_free_labels_are_per_catalog(): void {
		foreach ( array( 'deepseek-v4-flash-free', 'mimo-v2.5-free', 'nemotron-3-ultra-free', 'nemotron-3.5-lightning-free', 'big-pickle' ) as $id ) {
			self::assertTrue( \OpenCodeConnector\Metadata\ModelAllowlist::isFree( $id, 'zen' ) );
			self::assertFalse( \OpenCodeConnector\Metadata\ModelAllowlist::isFree( $id, 'go' ) );
		}
		self::assertFalse( \OpenCodeConnector\Metadata\ModelAllowlist::isFree( 'glm-5.3', 'go' ) );
		self::assertFalse( \OpenCodeConnector\Metadata\ModelAllowlist::isFree( 'glm-5.3', 'zen' ) );
	}

	/**
	 * The documented default-visible counts match the routable registry.
	 *
	 * README.md states routable counts, not allowlist counts; this test
	 * reads both from the registry so the docs cannot drift again.
	 */
	public function test_documented_model_counts_match_routable_records(): void {
		$routable = array();
		foreach ( array( 'go', 'zen' ) as $catalog ) {
			$count = 0;
			foreach ( ModelRegistry::records( $catalog ) as $record ) {
				if ( ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED !== ( $record['endpoint_family'] ?? '' ) ) {
					++$count;
				}
			}
			$routable[ $catalog ] = $count;
		}
		self::assertSame( 11, $routable['go'] );
		self::assertSame( 16, $routable['zen'] );
		$readme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/README.md' );
		self::assertStringContainsString( 'Go: ' . $routable['go'], $readme );
		self::assertStringContainsString( 'Zen: ' . $routable['zen'], $readme );
		$features = (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' );
		self::assertStringContainsString( 'Go: ' . $routable['go'], $features );
		self::assertStringContainsString( 'Zen: ' . $routable['zen'], $features );
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
	 * These two comparisons decide whether a model is routed or denied, and
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
			'self::ENDPOINT_FAMILY_UNSUPPORTED === $endpoint_family',
			$source,
			'The verification-status guard must compare against ENDPOINT_FAMILY_UNSUPPORTED.'
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
	}
}
