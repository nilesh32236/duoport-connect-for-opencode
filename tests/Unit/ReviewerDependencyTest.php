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
		self::assertSame( 'main', (string) $manifest['reviewer_ref'] );
		self::assertStringContainsString( (string) $manifest['reviewer_ref'], implode( "\n", $output ) );
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

		$ci = (string) file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/ci.yml' );
		self::assertSame( 2, substr_count( $ci, "'.github/workflows/*.yaml'" ) );
	}

	/**
	 * Hash drift in the manifest must fail the installer contract.
	 */
	public function test_verifier_rejects_manifest_installer_hash_drift(): void {
		$root = $this->copy_automation_fixture();
		try {
			$manifest_path = $root . '/.github/reviewer-dependency.json';
			$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
			self::assertIsArray( $manifest );
			$manifest['opencode_cli']['sha256']['linux-x64'] = $manifest['opencode_cli']['sha256']['linux-arm64'];
			$manifest['opencode_cli']['sha256']['linux-arm64'] = 'e9312be75ed803b7415fc2aeabda1f4fe938912a39673762dc0c38c0e11ebde4';
			file_put_contents( $manifest_path, json_encode( $manifest, JSON_PRETTY_PRINT ) );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertNotSame( 0, $status );
			self::assertStringContainsString( 'does not match setup-opencode.sh architecture assignment', implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * The verifier requires the exact active ruleset identities and check list.
	 */
	public function test_verifier_rejects_ruleset_identity_drift(): void {
		$root = $this->copy_automation_fixture();
		try {
			$manifest_path = $root . '/.github/reviewer-dependency.json';
			$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
			self::assertIsArray( $manifest );
			$manifest['merge_gate']['ruleset_id'] = 1;
			$manifest['merge_gate']['pull_request_ruleset_id'] = 2;
			$manifest['merge_gate']['required_checks'][] = 'unexpected-check';
			file_put_contents( $manifest_path, json_encode( $manifest, JSON_PRETTY_PRINT ) );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertNotSame( 0, $status );
			$text = implode( "\n", $output );
			self::assertStringContainsString( 'ruleset_id must be the active exact-check ruleset 23935160', $text );
			self::assertStringContainsString( 'pull_request_ruleset_id must be the active pull-request ruleset 23935219', $text );
			self::assertStringContainsString( 'required_checks must be exactly', $text );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * Installer architecture mapping and duplicate assignments must fail closed.
	 */
	public function test_verifier_rejects_installer_architecture_drift(): void {
		$root = $this->copy_automation_fixture();
		try {
			$path = $root . '/.github/scripts/setup-opencode.sh';
			$source = (string) file_get_contents( $path );
			$source = str_replace( 'aarch64|arm64) ARCH="linux-arm64"', 'aarch64|arm64) ARCH="linux-x64"', $source );
			file_put_contents( $path, $source );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertNotSame( 0, $status );
			self::assertStringContainsString( 'must map aarch64|arm64 to linux-arm64', implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * A duplicate architecture hash assignment must not be accepted.
	 */
	public function test_verifier_rejects_duplicate_installer_assignments(): void {
		$root = $this->copy_automation_fixture();
		try {
			$path = $root . '/.github/scripts/setup-opencode.sh';
			$source = (string) file_get_contents( $path );
			$source .= "\n[linux-x64]=\"" . str_repeat( 'f', 64 ) . "\"\n";
			file_put_contents( $path, $source );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertNotSame( 0, $status );
			self::assertStringContainsString( 'exactly one SHA-256 assignment for linux-x64', implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * Complete manifest validation rejects omitted provenance and equal hashes.
	 */
	public function test_manifest_validator_rejects_incomplete_or_equal_integrity_data(): void {
		$root = $this->copy_automation_fixture();
		try {
			$manifest_path = $root . '/.github/reviewer-dependency.json';
			$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
			self::assertIsArray( $manifest );
			$manifest['reviewer_repository'] = 'wrong/repo';
			$manifest['release_published_at'] = 'not-a-date';
			$manifest['opencode_cli']['version'] = 'not-a-version';
			$manifest['opencode_cli']['sha256']['linux-x64'] = $manifest['opencode_cli']['sha256']['linux-arm64'];
			$manifest['merge_gate']['ruleset_id'] = 1;
			$manifest['merge_gate']['required_checks'] = array('wrong');
			file_put_contents( $manifest_path, json_encode( $manifest, JSON_PRETTY_PRINT ) );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/validate-reviewer-manifest.php' ) . ' --file ' . escapeshellarg( $manifest_path ) . ' 2>&1', $output, $status );
			self::assertNotSame( 0, $status );
			$text = implode( "\n", $output );
			self::assertStringContainsString( 'reviewer_repository', $text );
			self::assertStringContainsString( 'must be distinct', $text );
			self::assertStringContainsString( 'required_checks', $text );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * A mutable .yaml workflow reference must not bypass the verifier.
	 */
	public function test_verifier_scans_yaml_workflows(): void {
		$root = $this->copy_automation_fixture();
		try {
			file_put_contents( $root . '/.github/workflows/extra.yaml', "name: Extra\\njobs:\\n  review:\\n    steps:\\n      - uses: nilesh32236/opencode-ai-reviewer@main\\n" );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertNotSame( 0, $status );
			self::assertStringContainsString( 'expected four reviewer action references', implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * A workflow that re-pins the reviewer to a SHA must fail verification.
	 *
	 * The manifest records a floating ref, so a SHA-pinned reference is drift:
	 * it would run a different revision than the manifest describes while
	 * still passing a casual read of the workflow.
	 */
	public function test_verifier_rejects_a_reintroduced_sha_pin(): void {
		$root = $this->copy_automation_fixture();
		try {
			$workflow = $root . '/.github/workflows/ai-review.yml';
			file_put_contents( $workflow, preg_replace(
				'#nilesh32236/opencode-ai-reviewer@main#',
				'nilesh32236/opencode-ai-reviewer@' . str_repeat( 'c', 40 ),
				(string) file_get_contents( $workflow ),
				1
			) );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertNotSame( 0, $status );
			self::assertStringContainsString( 'instead of the manifest ref', implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * A stale "# vX.Y.Z" comment must fail verification.
	 *
	 * With a floating ref the comment claims a version that the ref no longer
	 * corresponds to, which is precisely the drift the manifest existed to
	 * prevent. Leaving one behind is how a reader would come to believe the
	 * dependency is pinned when it is not.
	 */
	public function test_verifier_rejects_a_stale_version_comment(): void {
		$root = $this->copy_automation_fixture();
		try {
			$workflow = $root . '/.github/workflows/ai-review.yml';
			file_put_contents( $workflow, str_replace(
				'nilesh32236/opencode-ai-reviewer@main',
				'nilesh32236/opencode-ai-reviewer@main # v1.22.0',
				(string) file_get_contents( $workflow )
			) );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertNotSame( 0, $status );
			self::assertStringContainsString( 'stale version comment', implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * The removed updater must not come back with no driver.
	 *
	 * Its helper scripts wrote to the repository. Half of that machinery
	 * restored, with no workflow to call it, is the confusing state this
	 * check exists to prevent.
	 */
	public function test_verifier_rejects_resurrected_updater_machinery(): void {
		$removed = array(
			'.github/workflows/reviewer-update.yml',
			'.github/scripts/update-opencode-reviewer.php',
			'.github/scripts/push-reviewer-branch.sh',
			'.github/scripts/inspect-reviewer-branch.sh',
			'.github/scripts/reviewer-pr-guard.sh',
		);

		// One fixture, one verifier run. Restoring all five at once still proves
		// each is named in the failure, and avoids copying the automation tree
		// and spawning a verifier five times over.
		$root = $this->copy_automation_fixture();
		try {
			foreach ( $removed as $path ) {
				$full = $root . '/' . $path;
				if ( ! is_dir( dirname( $full ) ) ) {
					mkdir( dirname( $full ), 0777, true );
				}
				file_put_contents( $full, str_ends_with( $path, '.php' ) ? "<?php\n" : "#!/usr/bin/env bash\n" );
				chmod( $full, 0777 );
			}
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			$text = implode( "\n", $output );
			self::assertNotSame( 0, $status, 'restored updater machinery did not fail verification' );
			foreach ( $removed as $path ) {
				self::assertStringContainsString( $path . ' must stay removed', $text );
			}
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * A floating ref is only defensible if the revision in use is observable.
	 *
	 * Every job that runs the reviewer records the resolved commit for that
	 * run. Without it, "the reference floats" means the run log cannot say what
	 * actually executed, which is the property the old pin provided.
	 */
	public function test_every_reviewer_job_records_the_revision_in_use(): void {
		$root = dirname( __DIR__, 2 );
		foreach ( array( 'ai-review.yml' => 3, 'daily-audit.yml' => 1 ) as $name => $expected ) {
			$source = (string) file_get_contents( $root . '/.github/workflows/' . $name );
			$references = substr_count( $source, 'uses: nilesh32236/opencode-ai-reviewer@main' );
			self::assertSame( $expected, $references, $name . ' reviewer reference count' );
			self::assertSame(
				$expected,
				substr_count( $source, 'Record the reviewer revision in use' ),
				$name . ' must record the resolved reviewer revision once per reviewer action'
			);
		}

		// The read-back must be a real resolution, not a placeholder, and it
		// must fail closed rather than record an empty value.
		$review = (string) file_get_contents( $root . '/.github/workflows/ai-review.yml' );
		self::assertStringContainsString( 'commits/main --jq .sha', $review );
		self::assertStringContainsString( '^[a-f0-9]{40}$', $review );
		self::assertStringContainsString( '$GITHUB_STEP_SUMMARY', $review );

		// Scope the fail-closed assertion to the read-back step itself. Other
		// steps legitimately mask errors on best-effort label and PR edits,
		// so a whole-file check would either pass vacuously or force those
		// unrelated calls to change.
		$review_position = strpos( $review, '      - name: Record the reviewer revision in use' );
		self::assertNotFalse( $review_position );
		$step_end = strpos( $review, '      - name:', $review_position + 10 );
		$step = substr( $review, $review_position, false === $step_end ? null : $step_end - $review_position );
		self::assertStringContainsString( 'set -euo pipefail', $step );
		self::assertStringNotContainsString( '|| true', $step, 'the read-back must not mask a failed resolution' );
	}

	/**
	 * A manifest that still carries the retired release fields must fail.
	 */
	public function test_manifest_validator_rejects_retired_release_fields(): void {
		$root = $this->copy_automation_fixture();
		try {
			$manifest_path = $root . '/.github/reviewer-dependency.json';
			$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
			self::assertIsArray( $manifest );
			$manifest['release_commit'] = str_repeat( 'd', 40 );
			file_put_contents( $manifest_path, json_encode( $manifest, JSON_PRETTY_PRINT ) );
			$output = array();
			$status = 0;
			exec( 'php ' . escapeshellarg( $root . '/.github/scripts/validate-reviewer-manifest.php' ) . ' --file ' . escapeshellarg( $manifest_path ) . ' 2>&1', $output, $status );
			self::assertNotSame( 0, $status );
			self::assertStringContainsString( 'retired field release_commit', implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * Copy the automation files into an isolated temporary root.
	 *
	 * @return string Temporary root path.
	 */
	private function copy_automation_fixture(): string {
		$source_root = dirname( __DIR__, 2 );
		$temp_root = sys_get_temp_dir() . '/duoport-reviewer-' . bin2hex( random_bytes( 5 ) );
		mkdir( $temp_root . '/.github/scripts', 0777, true );
		mkdir( $temp_root . '/.github/workflows', 0777, true );
		copy( $source_root . '/.github/reviewer-dependency.json', $temp_root . '/.github/reviewer-dependency.json' );
		foreach ( glob( $source_root . '/.github/scripts/*.php' ) ?: array() as $file ) {
			copy( $file, $temp_root . '/.github/scripts/' . basename( $file ) );
		}
		copy( $source_root . '/.github/scripts/setup-opencode.sh', $temp_root . '/.github/scripts/setup-opencode.sh' );
		foreach ( glob( $source_root . '/.github/workflows/*.yml' ) ?: array() as $file ) {
			copy( $file, $temp_root . '/.github/workflows/' . basename( $file ) );
		}
		return $temp_root;
	}

	/**
	 * Remove an isolated temporary fixture.
	 *
	 * @param string $root Fixture root.
	 * @return void
	 */
	private function remove_fixture( string $root ): void {
		if ( ! is_dir( $root ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $root );
	}
}
