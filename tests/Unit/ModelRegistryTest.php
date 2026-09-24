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
	}
}
