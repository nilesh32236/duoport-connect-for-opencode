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
	 * ZIP verification must use exact entries and reject forbidden path components.
	 */
	public function test_release_verification_uses_exact_zip_entries(): void {
		$root = dirname( __DIR__, 2 );
		$source = (string) file_get_contents( $root . '/scripts/build-release.sh' );

		self::assertStringContainsString( 'grep -Fxq -- "$required" "$zip_list"', $source );
		self::assertStringContainsString( "grep -Eq '(^|/)(vendor|tests|\\.firecrawl)(/|$)'", $source );
		self::assertStringContainsString( 'if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then', $source );
		self::assertStringNotContainsString( 'unzip -l "${ORIG_PWD}/${ZIP_NAME}" | head -n 40', $source );

		$distignore = (string) file_get_contents( $root . '/.distignore' );
		self::assertStringContainsString( '/.firecrawl/', $distignore, 'Local research artifacts must not ship in the release ZIP.' );
	}

	/**
	 * The release metadata is synchronized before tagging.
	 */
	public function test_release_metadata_is_prepared_for_0_1_6(): void {
		$root = dirname( __DIR__, 2 );
		$main = (string) file_get_contents( $root . '/duoport-connect-for-opencode.php' );
		$readme = (string) file_get_contents( $root . '/readme.txt' );

		self::assertStringContainsString( 'Version:           0.1.6', $main );
		self::assertStringContainsString( "const VERSION     = '0.1.6';", $main );
		self::assertStringContainsString( 'Stable tag: 0.1.6', $readme );
		self::assertMatchesRegularExpression( '/^= 0\.1\.6 =/m', $readme );
		self::assertStringContainsString( '= 0.1.6 =', substr( $readme, strpos( $readme, '== Upgrade Notice ==' ) ) );
	}

	/**
	 * Campaign evidence stays bounded to the active release item and release-only scope.
	 */
	public function test_release_evidence_artifacts_are_scoped(): void {
		$root = dirname( __DIR__, 2 );
		$queue = (string) file_get_contents( $root . '/docs/architecture/refactor-queue.yaml' );
		$scope = (string) file_get_contents( $root . '/docs/architecture/PRODUCT-SCOPE.md' );
		$final = (string) file_get_contents( $root . '/docs/architecture/ARCHITECTURE-FINAL.md' );
		$readme = (string) file_get_contents( $root . '/readme.txt' );

		self::assertStringContainsString( 'active_item: RELEASE-003', $queue );
		self::assertStringContainsString( 'github_issue: 98', $queue );
		self::assertStringContainsString( 'github_pr: 99', $queue );
		self::assertStringContainsString( '    status: in_progress', $queue );
		self::assertStringContainsString( 'PHPUnit: 148 tests, 698 assertions', $final );
		self::assertStringContainsString( '## MODEL-001 result', $final );
		self::assertStringContainsString( '## GROWTH-001 result', $final );
		self::assertStringContainsString( '== Screenshots ==', $readme );
		self::assertStringContainsString( '## Release boundary', $scope );
		self::assertStringContainsString( 'packaging and evidence milestone', $scope );
	}

	/**
	 * The behavioral fixture exercises good, missing, and forbidden archives.
	 */
	public function test_release_zip_behavior_fixture(): void {
		$fixture = dirname( __DIR__ ) . '/Unit/release-build-contract.sh';
		$output = array();
		$status = 0;
		exec( 'bash ' . escapeshellarg( $fixture ) . ' 2>&1', $output, $status );

		self::assertSame( 0, $status, implode( "\n", $output ) );
	}
}
