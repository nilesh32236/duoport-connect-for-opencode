<?php
/**
 * Tests for the catalog/transient key single source of truth.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use OpenCodeConnector\Metadata\Catalog;

final class CatalogKeysTest extends MonkeyTestCase {

	/**
	 * The transient key surface is owned in one place.
	 *
	 * Every transient this plugin writes must appear here, so the settings
	 * bust hooks, the key-rotation hook, and uninstall cannot drift apart and
	 * leave a stale verdict behind after a key change.
	 */
	public function test_all_transient_keys_covers_every_owned_family(): void {
		$expected = array(
			'opencode_connector_avail_go',
			'opencode_connector_avail_go_lock',
			'opencode_connector_avail_go_last_good',
			'opencode_connector_avail_zen',
			'opencode_connector_avail_zen_lock',
			'opencode_connector_avail_zen_last_good',
			'opencode_connector_verify_go',
			'opencode_connector_verify_go_lock',
			'opencode_connector_verify_zen',
			'opencode_connector_verify_zen_lock',
		);

		$actual = Catalog::allTransientKeys();

		sort( $expected );
		sort( $actual );
		self::assertSame( $expected, $actual );
	}

	/**
	 * Availability keys cover the result, its lock, and last-known-good.
	 */
	public function test_availability_keys_include_last_good(): void {
		self::assertSame(
			array(
				'opencode_connector_avail_go',
				'opencode_connector_avail_go_lock',
				'opencode_connector_avail_go_last_good',
			),
			Catalog::availabilityKeys( 'go' )
		);
	}

	/**
	 * Verification keys cover the verdict and its stampede lock.
	 */
	public function test_verify_keys_cover_verdict_and_lock(): void {
		self::assertSame(
			array( 'opencode_connector_verify_zen', 'opencode_connector_verify_zen_lock' ),
			Catalog::verifyKeys( 'zen' )
		);
	}

	/**
	 * A key rotation must never be able to reuse the previous key's verdict.
	 *
	 * The last-known-good flag is the one piece of availability state that
	 * survives a probe, so it must be part of the invalidation set.
	 */
	public function test_last_good_is_invalidated_by_the_shared_key_set(): void {
		self::assertContains(
			'opencode_connector_avail_go_last_good',
			Catalog::allTransientKeys(),
			'A stale last-known-good flag must never outlive a key rotation.'
		);
	}

	/**
	 * Catalog slugs and base URLs stay in sync with the allowlists.
	 */
	public function test_catalog_slugs_are_known(): void {
		self::assertTrue( Catalog::isValid( Catalog::GO ) );
		self::assertTrue( Catalog::isValid( Catalog::ZEN ) );
		self::assertFalse( Catalog::isValid( 'unknown' ) );
		self::assertSame( '', Catalog::baseUrl( 'unknown' ), 'Unknown catalogs fail open to an empty base.' );
		self::assertSame( '', Catalog::modelsUrl( 'unknown' ) );
	}
}
