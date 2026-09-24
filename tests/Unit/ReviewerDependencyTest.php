<?php
/**
 * Contract tests for released reviewer/OpenCode automation dependencies.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

/**
 * Guards the traceability and checksum contract used by GitHub workflows.
 */
final class ReviewerDependencyTest extends MonkeyTestCase {

	/**
	 * Run the offline dependency verifier.
	 */
	public function test_reviewer_dependency_contract_is_valid(): void {
		$root = dirname( __DIR__, 2 );
		$output = array();
		$status = 0;
		exec( 'php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );

		self::assertSame( 0, $status, implode( "\n", $output ) );
		$manifest = json_decode( (string) file_get_contents( $root . '/.github/reviewer-dependency.json' ), true );
		self::assertIsArray( $manifest );
		self::assertStringContainsString( (string) $manifest['release_tag'], implode( "\n", $output ) );
	}

	/**
	 * The updater is idempotent for the current release and rejects unsafe tags.
	 */
	public function test_reviewer_updater_is_validated_and_idempotent(): void {
		$root = dirname( __DIR__, 2 );
		$manifest = json_decode( (string) file_get_contents( $root . '/.github/reviewer-dependency.json' ), true );
		self::assertIsArray( $manifest );
		$tag = (string) $manifest['release_tag'];
		$commit = (string) $manifest['release_commit'];
		$published = (string) $manifest['release_published_at'];

		$output = array();
		$status = 0;
		exec(
			'php ' . escapeshellarg( $root . '/.github/scripts/update-opencode-reviewer.php' ) .
			' --tag ' . escapeshellarg( $tag ) .
			' --commit ' . escapeshellarg( $commit ) .
			' --published ' . escapeshellarg( $published ) .
			' --dry-run 2>&1',
			$output,
			$status
		);
		self::assertSame( 0, $status, implode( "\n", $output ) );
		self::assertStringContainsString( '"manifest_changed":false', implode( "\n", $output ) );

		$unused = array();
		exec( 'php ' . escapeshellarg( $root . '/.github/scripts/update-opencode-reviewer.php' ) . ' --tag main --commit bad --published invalid >/dev/null 2>&1', $unused, $unsafe_status );
		self::assertNotSame( 0, $unsafe_status );
	}

	/**
	 * The OpenCode installer must use the reviewed CLI and verify its hash.
	 */
	public function test_opencode_installer_has_pinned_checksum(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/.github/scripts/setup-opencode.sh' );

		self::assertStringContainsString( 'OPENCODE_VERSION:-v1.18.31', $source );
		self::assertStringContainsString( 'e9312be75ed803b7415fc2aeabda1f4fe938912a39673762dc0c38c0e11ebde4', $source );
		self::assertStringContainsString( 'd4e332f46b227448582c0d9fc75f6f826dfe95c9f751bc2011fc4d937a042be6', $source );
		self::assertStringContainsString( 'sha256sum -c', $source );

		$updater = (string) file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/reviewer-update.yml' );
		self::assertStringContainsString( 'Skip already-current dependency branch', $updater );
		self::assertStringContainsString( 'GH_PAT', $updater );
		self::assertStringContainsString( 'proceed=false', $updater );
		self::assertStringContainsString( 'force-with-lease', $updater );
	}
}
