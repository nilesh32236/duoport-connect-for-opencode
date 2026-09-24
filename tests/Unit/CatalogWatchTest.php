<?php
/**
 * Tests for evidence-only catalog freshness tracking.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use OpenCodeConnector\Metadata\CatalogWatch;

final class CatalogWatchTest extends MonkeyTestCase {

	/**
	 * Unknown discovery IDs require review and are never promoted.
	 */
	public function test_new_model_requires_verification(): void {
		$results = ( new CatalogWatch() )->compare(
			'go',
			array(
				array( 'id' => 'new-model', 'endpoint_family' => 'chat' ),
			)
		);

		$result = array_values( array_filter( $results, static fn( array $row ): bool => 'new-model' === $row['id'] ) )[0];
		self::assertSame( 'verification_required', $result['status'] );
		self::assertContains( 'new', $result['states'] );
		self::assertContains( 'verification_required', $result['states'] );
		self::assertFalse( $result['promotable'] );
	}

	/**
	 * Known IDs remain allowlisted when discovery is unchanged.
	 */
	public function test_unchanged_model_is_allowlisted(): void {
		$results = ( new CatalogWatch() )->compare(
			'go',
			array(
				array(
					'id'             => 'glm-5.3',
					'endpoint_family' => 'chat',
					'display_name'    => 'Glm 5.3',
					'free'            => false,
					'capabilities'    => array( 'text' => true ),
				),
			)
		);

		$result = array_values( array_filter( $results, static fn( array $row ): bool => 'glm-5.3' === $row['id'] ) )[0];
		self::assertSame( 'allowlisted', $result['status'] );
		self::assertTrue( $result['allowlisted'] );
		self::assertFalse( $result['promotable'] );
	}

	/**
	 * Endpoint, capability, and metadata drift each require verification.
	 */
	public function test_changed_fields_require_verification(): void {
		$results = ( new CatalogWatch() )->compare(
			'go',
			array(
				array(
					'id'             => 'glm-5.3',
					'endpoint_family' => 'responses',
					'display_name'    => 'Renamed',
					'free'            => true,
					'capabilities'    => array( 'text' => false ),
				),
			)
		);

		$result = array_values( array_filter( $results, static fn( array $row ): bool => 'glm-5.3' === $row['id'] ) )[0];
		self::assertSame( 'verification_required', $result['status'] );
		self::assertContains( 'endpoint_changed', $result['states'] );
		self::assertContains( 'capability_changed', $result['states'] );
		self::assertContains( 'metadata_changed', $result['states'] );
	}

	/**
	 * Missing reviewed models are retired and never silently re-promoted.
	 */
	public function test_retired_model_is_reported(): void {
		$results = ( new CatalogWatch() )->compare( 'zen', array() );

		self::assertNotEmpty( $results );
		foreach ( $results as $result ) {
			self::assertSame( 'retired', $result['status'] );
			self::assertContains( 'verification_required', $result['states'] );
			self::assertFalse( $result['promotable'] );
		}
	}

	/**
	 * Mixed malformed input does not retire baseline records or crash on numeric IDs.
	 */
	public function test_mixed_malformed_input_is_safe(): void {
		$results = ( new CatalogWatch() )->compare(
			'go',
			array(
				array( 'id' => 'new-model' ),
				array( 'id' => '123' ),
				array( 'id' => '123' ),
				array( 'id' => 'bad-capabilities', 'capabilities' => 'invalid' ),
				array( 'endpoint_family' => 'chat' ),
			)
		);

		foreach ( $results as $result ) {
			self::assertNotSame( 'retired', $result['status'] );
			self::assertContains( 'input_invalid', $result['states'] );
		}
		self::assertCount( 1, $results );
	}

	/**
	 * Duplicate-only discovery input cannot return an empty or green result.
	 */
	public function test_duplicate_only_input_fails_closed(): void {
		$results = ( new CatalogWatch() )->compare(
			'go',
			array(
				array( 'id' => 'glm-5.3' ),
				array( 'id' => 'glm-5.3' ),
			)
		);

		self::assertCount( 1, $results );
		self::assertSame( 'input_invalid', $results[0]['status'] );
		self::assertFalse( $results[0]['promotable'] );
	}

	/**
	 * Non-string numeric IDs are malformed and fail closed.
	 */
	public function test_non_string_numeric_id_fails_closed(): void {
		$results = ( new CatalogWatch() )->compare( 'go', array( array( 'id' => 123 ) ) );

		self::assertCount( 1, $results );
		self::assertSame( 'input_invalid', $results[0]['status'] );
	}

	/**
	 * Unknown capability keys and needs-adapter records require verification.
	 */
	public function test_unknown_capability_and_unverified_record_require_verification(): void {
		$watch = new CatalogWatch();
		$drift = $watch->compare(
			'go',
			array( array( 'id' => 'glm-5.3', 'capabilities' => array( 'embeddings' => true ) ) )
		);
		$unverified = $watch->compare( 'zen', array( array( 'id' => 'minimax-m3' ) ) );

		$changed = array_values( array_filter( $drift, static fn( array $row ): bool => 'glm-5.3' === $row['id'] ) )[0];
		$adapter = array_values( array_filter( $unverified, static fn( array $row ): bool => 'minimax-m3' === $row['id'] ) )[0];
		self::assertSame( 'verification_required', $changed['status'] );
		self::assertContains( 'capability_changed', $changed['states'] );
		self::assertSame( 'verification_required', $adapter['status'] );
		self::assertTrue( $adapter['allowlisted'] );
	}

	/**
	 * The shipped drift script delegates to the comparator.
	 */
	public function test_shipped_drift_script_uses_catalog_watch(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 2 ) . '/.github/scripts/check-catalog-drift.php' );

		self::assertStringContainsString( 'CatalogWatch', $script );
		self::assertStringContainsString( 'fetch_discovery', $script );
		self::assertStringNotContainsString( 'allowlist_ids(', $script );
		self::assertStringNotContainsString( 'connectors_ai_', $script );
	}

	/**
	 * Unknown catalogs and malformed discovery rows are default-deny.
	 */
	public function test_unknown_catalog_and_malformed_rows_are_ignored(): void {
		$watch = new CatalogWatch();

		self::assertSame( array(), $watch->compare( 'other', array( array( 'id' => 'x' ) ) ) );
		$malformed = $watch->compare( 'go', array( array( 'endpoint_family' => 'chat' ) ) );
		self::assertSame( 'input_invalid', $malformed[0]['status'] );
		self::assertFalse( $malformed[0]['promotable'] );
	}
}
