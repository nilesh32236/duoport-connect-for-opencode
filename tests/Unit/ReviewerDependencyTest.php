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
		self::assertStringContainsString( 'GH_TOKEN: ${{ secrets.GH_PAT || secrets.GITHUB_TOKEN }}', $updater );
		self::assertStringContainsString( 'proceed=false', $updater );
		self::assertStringContainsString( 'ensure_pr', $updater );
		self::assertStringContainsString( 'gh api --paginate', $updater );
		self::assertStringContainsString( 'per_page=100', $updater );
		self::assertStringContainsString( 'push-reviewer-branch.sh', $updater );
		$ci = (string) file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/ci.yml' );
		self::assertSame( 2, substr_count( $ci, "'.github/workflows/*.yaml'" ) );
		$guard_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/.github/scripts/reviewer-pr-guard.sh' );
		self::assertStringContainsString( 'head.repo.full_name', $guard_source );
		self::assertStringContainsString( 'expected=', $guard_source );
		$inspect = (string) file_get_contents( dirname( __DIR__, 2 ) . '/.github/scripts/inspect-reviewer-branch.sh' );
		self::assertStringContainsString( 'git show', $inspect );
		self::assertStringContainsString( 'git grep', $inspect );
		self::assertStringNotContainsString( '|| true', $inspect );
		$push = (string) file_get_contents( dirname( __DIR__, 2 ) . '/.github/scripts/push-reviewer-branch.sh' );
		self::assertStringContainsString( '--force-with-lease="${REF}:${EXISTING_SHA}"', $push );
		self::assertStringContainsString( '--force-with-lease="${REF}:1111111111111111111111111111111111111111"', $push );
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
	 * A synthetic next release updates the temp root and is idempotent afterward.
	 */
	public function test_updater_handles_a_next_release_and_second_run(): void {
		$root = $this->copy_automation_fixture();
		try {
			file_put_contents( $root . '/.github/workflows/extra.yaml', "name: Extra\\njobs:\\n  review:\\n    steps:\\n      - uses: nilesh32236/opencode-ai-reviewer@aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\\n" );
			$command = 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/update-opencode-reviewer.php' ) . ' --tag v99.0.0 --commit ' . escapeshellarg( str_repeat( 'b', 40 ) ) . ' --published 2026-10-01T00:00:00Z';
			$output = array();
			$status = 0;
			exec( $command . ' 2>&1', $output, $status );
			self::assertSame( 0, $status, implode( "\n", $output ) );
			self::assertStringContainsString( '"manifest_changed":true', implode( "\n", $output ) );
			$manifest = json_decode( (string) file_get_contents( $root . '/.github/reviewer-dependency.json' ), true );
			self::assertSame( 'v99.0.0', $manifest['release_tag'] );
			self::assertStringContainsString( 'opencode-ai-reviewer@' . str_repeat( 'b', 40 ) . ' # v99.0.0', (string) file_get_contents( $root . '/.github/workflows/extra.yaml' ) );
			$output = array();
			exec( $command . ' 2>&1', $output, $status );
			self::assertSame( 0, $status, implode( "\n", $output ) );
			self::assertStringContainsString( '"manifest_changed":false', implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * The campaign guard fails closed and does not trust a fork with the automation name.
	 */
	public function test_campaign_guard_is_identity_aware_and_fails_closed(): void {
		$temp = sys_get_temp_dir() . '/duoport-guard-' . bin2hex( random_bytes( 5 ) );
		mkdir( $temp );
		try {
			$fake = $temp . '/gh';
			$log = $temp . '/args';
			file_put_contents( $fake, "#!/usr/bin/env bash\nprintf '%s\\n' \"\$@\" > \"\${GH_LOG}\"\nprintf 'automation/opencode-ai-reviewer\\tnilesh32236/duoport-connect-for-opencode\\n'\nfor i in \$(seq 1 35); do printf 'filler-%02d\\tattacker/other\\n' \"\$i\"; done\nprintf 'automation/opencode-ai-reviewer\\tattacker/other\\ncampaign\\tnilesh32236/duoport-connect-for-opencode\\n'\n" );
			chmod( $fake, 0777 );
			$guard = dirname( __DIR__, 2 ) . '/.github/scripts/reviewer-pr-guard.sh';
			$output = array();
			$status = 0;
			exec( 'GH_LOG=' . escapeshellarg( $log ) . ' GH_BIN=' . escapeshellarg( $fake ) . ' ' . escapeshellarg( $guard ) . ' nilesh32236/duoport-connect-for-opencode nilesh32236/duoport-connect-for-opencode 2>&1', $output, $status );
			self::assertSame( 0, $status, implode( "\n", $output ) );
			self::assertCount( 37, $output );
			self::assertContains( 'automation/opencode-ai-reviewer', $output );
			self::assertContains( 'campaign', $output );
			$args = (string) file_get_contents( $log );
			self::assertStringContainsString( 'api', $args );
			self::assertStringContainsString( '--paginate', $args );
			self::assertStringContainsString( 'per_page=100', $args );

			file_put_contents( $fake, "#!/usr/bin/env bash\nexit 42\n" );
			chmod( $fake, 0777 );
			$unused = array();
			$failure_status = 0;
			exec( 'GH_BIN=' . escapeshellarg( $fake ) . ' ' . escapeshellarg( $guard ) . ' nilesh32236/duoport-connect-for-opencode nilesh32236/duoport-connect-for-opencode >/dev/null 2>&1', $unused, $failure_status );
			self::assertSame( 1, $failure_status );
		} finally {
			$this->remove_fixture( $temp );
		}
	}

	/**
	 * Run the executable workflow shell contracts as part of PHPUnit.
	 */
	public function test_workflow_shell_contracts(): void {
		$root = dirname( __DIR__, 2 );
		foreach ( array( 'reviewer-push-contract.sh', 'reviewer-branch-inspection-contract.sh' ) as $script ) {
			$output = array();
			$status = 0;
			exec( 'bash ' . escapeshellarg( $root . '/tests/Unit/' . $script ) . ' 2>&1', $output, $status );
			self::assertSame( 0, $status, implode( "\n", $output ) );
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
		copy( $source_root . '/.github/scripts/reviewer-pr-guard.sh', $temp_root . '/.github/scripts/reviewer-pr-guard.sh' );
		chmod( $temp_root . '/.github/scripts/reviewer-pr-guard.sh', 0777 );
		copy( $source_root . '/.github/scripts/push-reviewer-branch.sh', $temp_root . '/.github/scripts/push-reviewer-branch.sh' );
		chmod( $temp_root . '/.github/scripts/push-reviewer-branch.sh', 0777 );
		copy( $source_root . '/.github/scripts/inspect-reviewer-branch.sh', $temp_root . '/.github/scripts/inspect-reviewer-branch.sh' );
		chmod( $temp_root . '/.github/scripts/inspect-reviewer-branch.sh', 0777 );
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
