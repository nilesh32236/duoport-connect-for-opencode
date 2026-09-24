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
	 * Unknown catalogs and malformed discovery rows are default-deny.
	 */
	public function test_unknown_catalog_and_malformed_rows_are_ignored(): void {
		$watch = new CatalogWatch();

		self::assertSame( array(), $watch->compare( 'other', array( array( 'id' => 'x' ) ) ) );
		self::assertSame( array(), $watch->compare( 'go', array( array( 'endpoint_family' => 'chat' ) ) ) );
	}
}
