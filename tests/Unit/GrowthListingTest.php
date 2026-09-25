<?php
/**
 * Contract for the evidence-based WordPress.org listing baseline.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

/**
 * Verifies that listing conversion remains factual and screenshot-safe.
 */
final class GrowthListingTest extends MonkeyTestCase {

	/**
	 * The listing has useful conversion sections and stable screenshot assets.
	 */
	public function test_listing_sections_and_screenshots_are_present(): void {
		$root    = dirname( __DIR__, 2 );
		$readme  = (string) file_get_contents( $root . '/readme.txt' );
		$growth  = (string) file_get_contents( $root . '/docs/growth/WORDPRESS-ORG.md' );
		$screen1 = (string) file_get_contents( $root . '/screenshot-1.png' );
		$screen2 = (string) file_get_contents( $root . '/screenshot-2.png' );

		self::assertStringContainsString( '== Why DuoPort? ==', $readme );
		self::assertStringContainsString( '== OpenCode Go and Zen ==', $readme );
		self::assertStringContainsString( '== Current OpenCode models ==', $readme );
		self::assertStringContainsString( '== Free OpenCode models ==', $readme );
		self::assertStringContainsString( '== WordPress AI Client integration ==', $readme );
		self::assertStringContainsString( '== Verified model support ==', $readme );
		self::assertStringContainsString( '== Screenshots ==', $readme );
		self::assertStringContainsString( 'Stable tag: 0.1.6', $readme );
		self::assertStringContainsString( 'screenshot-1.png', $growth );
		self::assertStringContainsString( 'screenshot-2.png', $growth );
		self::assertStringNotContainsString( 'connectors_ai_', $readme . $growth );
		self::assertNotSame( '', $screen1 );
		self::assertNotSame( '', $screen2 );
	}

	/**
	 * The two screenshots remain valid PNGs with the recorded dimensions.
	 */
	public function test_screenshots_are_valid_pngs(): void {
		$root = dirname( __DIR__, 2 );
		foreach ( array( 'screenshot-1.png', 'screenshot-2.png' ) as $name ) {
			$size = getimagesize( $root . '/' . $name );
			self::assertIsArray( $size );
			self::assertSame( 'image/png', $size['mime'] );
			self::assertSame( 1440, $size[0] );
			self::assertSame( 868, $size[1] );
		}
	}

	/**
	 * The experiment does not silently change unrelated listing metadata.
	 */
	public function test_experiment_scope_is_explicit(): void {
		$root  = dirname( __DIR__, 2 );
		$readme = (string) file_get_contents( $root . '/readme.txt' );
		$scope  = (string) file_get_contents( $root . '/docs/growth/WORDPRESS-ORG.md' );

		self::assertStringContainsString( 'Tags: ai, artificial-intelligence, connector, opencode, zen', $readme );
		self::assertStringContainsString( 'GROWTH-002', $scope );
		self::assertStringContainsString( 'No fake reviews', $scope );
	}
}
