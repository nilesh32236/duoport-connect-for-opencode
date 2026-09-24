<?php
/**
 * Focused regression specs for the release build script.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

/**
 * Guards the shell pipeline used to verify release ZIP contents.
 */
final class ReleaseScriptTest extends MonkeyTestCase {

	/**
	 * ZIP verification must not use short-circuit pipes under pipefail.
	 */
	public function test_release_verification_uses_a_complete_zip_list(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/scripts/build-release.sh' );

		self::assertStringContainsString( 'ZIP_LIST="${BUILD_DIR}/zip-list.txt"', $source );
		self::assertStringContainsString( 'unzip -Z1 "${ORIG_PWD}/${ZIP_NAME}" > "$ZIP_LIST"', $source );
		self::assertStringContainsString( 'sed -n \'1,40p\' "$ZIP_LIST"', $source );
		self::assertStringContainsString( 'grep -Fq "$required" "$ZIP_LIST"', $source );
		self::assertStringContainsString( 'grep -Fq "${PLUGIN_SLUG}/.firecrawl/" "$ZIP_LIST"', $source );
		self::assertStringNotContainsString( 'unzip -l "${ORIG_PWD}/${ZIP_NAME}" | head -n 40', $source );
		self::assertStringNotContainsString( 'unzip -l "${ORIG_PWD}/${ZIP_NAME}" | grep -q', $source );

		$distignore = (string) file_get_contents( dirname( __DIR__, 2 ) . '/.distignore' );
		self::assertStringContainsString( '/.firecrawl/', $distignore, 'Local research artifacts must not ship in the release ZIP.' );
	}
}
