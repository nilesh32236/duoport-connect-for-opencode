<?php
/**
 * Verify the released reviewer and OpenCode CLI dependency contract.
 *
 * This script is intentionally offline: CI must detect stale or mutable
 * workflow references without calling GitHub or exposing credentials.
 */

declare(strict_types=1);

$root = getenv('DUOPORT_REPO_ROOT') ?: dirname(__DIR__, 2);
$manifest_path = $root . '/.github/reviewer-dependency.json';
$manifest_raw = file_get_contents($manifest_path);
if (false === $manifest_raw) {
    fwrite(STDERR, "Missing reviewer dependency manifest\n");
    exit(1);
}
$manifest = json_decode($manifest_raw, true);
if (!is_array($manifest)) {
    fwrite(STDERR, "Invalid reviewer dependency JSON\n");
    exit(1);
}

$reviewer_ref = (string) ($manifest['reviewer_ref'] ?? '');
$cli = $manifest['opencode_cli'] ?? array();
$cli_version = (string) ($cli['version'] ?? '');
$errors = array();
$manifest_validator = $root . '/.github/scripts/validate-reviewer-manifest.php';
if (!is_file($manifest_validator)) {
    $errors[] = 'validate-reviewer-manifest.php is missing';
} else {
    $validation_output = array();
    $validation_status = 0;
    exec('php ' . escapeshellarg($manifest_validator) . ' --file ' . escapeshellarg($manifest_path) . ' 2>&1', $validation_output, $validation_status);
    if (0 !== $validation_status) {
        $errors[] = 'manifest schema validation failed: ' . implode('; ', $validation_output);
    }
}
if ('main' !== $reviewer_ref) {
    $errors[] = 'reviewer_ref must be the reviewer main branch';
}
if ('v1.18.31' !== $cli_version) {
    $errors[] = 'opencode_cli.version must remain the checksum-verified v1.18.31';
}
foreach (array('linux-x64', 'linux-arm64') as $arch) {
    $hash = (string) (($cli['sha256'] ?? array())[$arch] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
        $errors[] = "opencode_cli.sha256.{$arch} must be a full SHA-256";
    }
}
if (($cli['sha256']['linux-x64'] ?? null) === ($cli['sha256']['linux-arm64'] ?? null)) {
    $errors[] = 'opencode_cli architecture hashes must be distinct';
}
$merge_gate = $manifest['merge_gate'] ?? array();
$expected_ruleset_id = 23935160;
$expected_pr_ruleset_id = 23935219;
if (!is_array($merge_gate) || (int) ($merge_gate['ruleset_id'] ?? 0) !== $expected_ruleset_id) {
    $errors[] = "merge_gate.ruleset_id must be the active exact-check ruleset {$expected_ruleset_id}";
}
if (!is_array($merge_gate) || (int) ($merge_gate['pull_request_ruleset_id'] ?? 0) !== $expected_pr_ruleset_id) {
    $errors[] = "merge_gate.pull_request_ruleset_id must be the active pull-request ruleset {$expected_pr_ruleset_id}";
}
$required_checks = $merge_gate['required_checks'] ?? null;
$expected_checks = array('PHPCS + PHPUnit (PHP 8.2)', 'PHPCS + PHPUnit (PHP 8.3)');
if ($required_checks !== $expected_checks) {
    $errors[] = 'merge_gate.required_checks must be exactly the PHP 8.2 and PHP 8.3 matrix checks';
}

$workflow_dir = $root . '/.github/workflows';
$workflow_files = array_merge(
    glob($workflow_dir . '/*.yml') ?: array(),
    glob($workflow_dir . '/*.yaml') ?: array()
);
$reference_count = 0;
$workflow_sources = array();
foreach ($workflow_files as $file) {
    $contents = file_get_contents($file);
    if (false === $contents) {
        $errors[] = basename($file) . ' could not be read; refusing to verify against an empty source';
        continue;
    }
    $workflow_sources[$file] = $contents;
}
foreach ($workflow_files as $file) {
    // A file that could not be read is already recorded as an error. Skip it
    // rather than scanning an absent source, which would both miscount the
    // references and fatal on a null subject instead of failing cleanly.
    if (!isset($workflow_sources[$file])) {
        continue;
    }
    // Only real action lines count. A commented-out reference, of the kind
    // added when documenting a previous approach, is documentation rather than
    // configuration; counting it would inflate the expected-reference total
    // and fail CI on an explanatory line.
    $lines = array();
    foreach (explode("\n", $workflow_sources[$file]) as $line) {
        if ('' !== ltrim($line) && 0 === strpos(ltrim($line), '#')) {
            continue;
        }
        $lines[] = $line;
    }
    $source = implode("\n", $lines);
    if (preg_match_all('/uses:\s*nilesh32236\/opencode-ai-reviewer@([^\s#]+)/', $source, $matches)) {
        foreach ($matches[1] as $used_ref) {
            ++$reference_count;
            if ($reviewer_ref !== $used_ref) {
                $errors[] = basename($file) . " uses reviewer ref '{$used_ref}' instead of the manifest ref '{$reviewer_ref}'";
            }
        }
    }
    // A trailing "# vX.Y.Z" comment would claim a version the floating ref no
    // longer corresponds to, which is exactly the drift this manifest used to
    // prevent. Only version-shaped comments are rejected, so a genuine note
    // beside the reference is still allowed.
    if (preg_match('/uses:\s*nilesh32236\/opencode-ai-reviewer@\S+\s+#\s*v?\d/i', $source)) {
        $errors[] = basename($file) . ' reviewer reference carries a stale version comment';
    }
}
if (4 !== $reference_count) {
    $errors[] = "expected four reviewer action references, found {$reference_count}";
}

// The revision read-back is repeated once per reviewer job, because a job
// cannot share steps with another job. Duplication is only safe while the
// copies stay identical, so compare them rather than trusting a future edit to
// update all four. The steps are extracted and compared by their body, ignoring
// the job they sit in.
$readback_marker = '      - name: Record the reviewer main at run start';
$readback_bodies = array();
foreach ($workflow_files as $file) {
    if (!isset($workflow_sources[$file])) {
        continue;
    }
    $text = $workflow_sources[$file];
    $offset = 0;
    while (false !== ($position = strpos($text, $readback_marker, $offset))) {
        // Double quotes: PHP single quotes do not expand \n, which would make
        // this search a literal backslash-n and stop at the wrong step.
        $next = strpos($text, "\n      - name:", $position + 10);
        $body = substr($text, $position, false === $next ? null : $next - $position);
        $readback_bodies[] = $body;
        $offset = false === $next ? strlen($text) : $next;
    }
}
if (4 !== count($readback_bodies)) {
    $errors[] = 'expected four reviewer revision read-back steps, found ' . count($readback_bodies);
} else {
    $first = $readback_bodies[0];
    foreach (array_slice($readback_bodies, 1) as $index => $body) {
        if ($body !== $first) {
            $errors[] = 'reviewer revision read-back copy ' . ($index + 2) . ' has drifted from the first';
        }
    }
    // The read-back only reads a public repository, so it must use the default
    // token. The PAT carries broader scope and is reserved for the reviewer's
    // own write operations; a uniform copy using it would otherwise expose a
    // wider credential for no gain. This checks the whole set once: every
    // copy is byte-identical to the first by this point.
    if (str_contains($first, 'secrets.GH_PAT') || !str_contains($first, 'GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}')) {
        $errors[] = 'reviewer revision read-back must use the default GITHUB_TOKEN, not the PAT';
    }
}

// No run: block may interpolate a GitHub expression directly. Values taken
// from a pull request are chosen by whoever opened it, so
// `echo "ref=${{ github.event.pull_request.head.ref }}"` inside a shell script
// executes whatever a branch name contains. They must arrive through env: and
// be referenced as quoted shell variables.
//
// This is checked for every expression, not only the known-unsafe ones: a new
// `uses:` or input reference is exactly as risky as head.ref, and the rule is
// simple enough to apply without a judgement call each time.
// Scope: this guards `run:` blocks only, block and scalar alike. That is the
// boundary that matters, because a run: body is the one place GitHub
// substitutes text into a shell script. A `with:` value is handed to an action
// as an input and is never shell-evaluated, so the same expression there is
// the ordinary, intended way to pass a branch name to actions/checkout; flagging
// it would be a false positive that teaches readers to ignore the rule.
//
// Only expressions whose value is chosen by whoever opened the pull request
// are dangerous. github.repository, github.run_id, vars.*, env.*, needs.*,
// steps.*, secrets.* and matrix.* are fixed by the repository or the workflow
// file, so a blanket ban would flag a hundred safe uses and train everyone to
// ignore the rule. The list below is the free-text attacker can choose, and it
// is the one that turns a pull request into shell.
$unsafe_expressions = array(
    'github.head_ref',
    'github.event.pull_request.head.ref',
    'github.event.pull_request.head.label',
    'github.event.pull_request.title',
    'github.event.pull_request.body',
    'github.event.issue.title',
    'github.event.issue.body',
    'github.event.comment.body',
    'github.event.review.body',
    'github.event.review_comment.body',
    'github.event.head_commit.message',
    'github.event.discussion.title',
    'github.event.discussion.body',
    'github.event.workflow_run.display_title',
    'github.event.workflow_run.head_branch',
);
$unsafe_pattern = '/\$\{\{\s*(' . implode('|', array_map(static fn($e) => preg_quote($e, '/'), $unsafe_expressions)) . ')\s*\}\}/';
foreach ($workflow_files as $file) {
    if (!isset($workflow_sources[$file])) {
        continue;
    }
    $lines = explode("\n", $workflow_sources[$file]);
    $in_run = false;
    $run_indent = 0;
    foreach ($lines as $index => $line) {
        $trimmed = ltrim($line);
        $indent = strlen($line) - strlen($trimmed);
        // Detect a run: block by its literal prefix rather than a regex. An
        // earlier regex for this silently failed to match, so the guard never
        // fired; string comparison cannot be escaped by accident.
        if (0 === strpos($trimmed, 'run:')) {
            $rest = ltrim(substr($trimmed, 4));
            if ('' !== $rest && ('|' === $rest[0] || '>' === $rest[0])) {
                $in_run = true;
                $run_indent = $indent;
                continue;
            }
            // A scalar run: keeps its body on the same line, so entering block
            // mode would miss it. Check the body directly. An earlier version
            // only handled the block form and silently accepted
            // `run: echo "${{ github.event.pull_request.head.ref }}"`.
            if ('' !== $rest) {
                if (preg_match($unsafe_pattern, $rest, $scalar_hit)) {
                    $errors[] = basename($file) . ':' . ($index + 1) . ' interpolates ' . $scalar_hit[1] . ' inside a scalar run:; pass it through env: and quote the variable';
                }
                continue;
            }
        }
        if (!$in_run) {
            continue;
        }
        if ('' !== trim($line) && $indent <= $run_indent) {
            $in_run = false;
            continue;
        }
        if (preg_match($unsafe_pattern, $line, $hit)) {
            $errors[] = basename($file) . ':' . ($index + 1) . ' interpolates ' . $hit[1] . ' inside a run: block; pass it through env: and quote the variable';
        }
    }
}

// The dependency graph is documentation, so it drifts silently unless checked.
$graph_path = $root . '/docs/architecture/DEPENDENCY-GRAPH.json';
if (!is_file($graph_path)) {
    $errors[] = 'docs/architecture/DEPENDENCY-GRAPH.json is missing';
} else {
    $graph_text = (string) file_get_contents($graph_path);
    $graph = json_decode($graph_text, true);
    if (!is_array($graph)) {
        $errors[] = 'DEPENDENCY-GRAPH.json is not valid JSON';
    } else {
        $graph_ids = array();
        foreach ((array) ($graph['nodes'] ?? array()) as $node) {
            if (is_array($node) && isset($node['id'])) {
                $graph_ids[(string) $node['id']] = true;
            }
        }
        foreach ((array) ($graph['edges'] ?? array()) as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            foreach (array('from', 'to') as $end) {
                $target = (string) ($edge[$end] ?? '');
                if ('' !== $target && !isset($graph_ids[$target])) {
                    $errors[] = "DEPENDENCY-GRAPH.json edge {$end} '{$target}' has no matching node";
                }
            }
        }
        foreach (array('reviewer-update.yml', 'update-opencode-reviewer.php', 'reviewer-pr-guard.sh') as $removed) {
            if (str_contains($graph_text, $removed)) {
                $errors[] = "DEPENDENCY-GRAPH.json still references the removed {$removed}";
            }
        }
    }
}

// The dependency updater and the scripts that only it called are removed.
// Reject a partial revert: half of this machinery back with no updater to
// drive it is a confusing state, and the removed helpers are the ones that
// wrote to the repository.
foreach (array(
    '.github/workflows/reviewer-update.yml',
    '.github/scripts/update-opencode-reviewer.php',
    '.github/scripts/push-reviewer-branch.sh',
    '.github/scripts/inspect-reviewer-branch.sh',
    '.github/scripts/reviewer-pr-guard.sh',
) as $removed) {
    if (file_exists($root . '/' . $removed)) {
        $errors[] = "{$removed} must stay removed while the reviewer ref is floating";
    }
}

// Reuse the sources read above rather than reading each file again.
$review_source = $workflow_sources[$workflow_dir . '/ai-review.yml'] ?? '';
$audit_source = $workflow_sources[$workflow_dir . '/daily-audit.yml'] ?? '';
$research_source = $workflow_sources[$workflow_dir . '/research-monitor.yml'] ?? '';
$setup_source = (string) file_get_contents($root . '/.github/scripts/setup-opencode.sh');
$reviewer_counts = array(
    'ai-review.yml' => 3,
    'daily-audit.yml' => 1,
);
foreach (array('ai-review.yml' => $review_source, 'daily-audit.yml' => $audit_source) as $name => $source) {
    $expected = $reviewer_counts[$name];
    if (substr_count($source, 'opencode_version: v1.18.31') !== $expected) {
        $errors[] = $name . " must pin opencode_version on all {$expected} reviewer action(s)";
    }
    if (substr_count($source, 'require_opencode_checksum: true') !== $expected) {
        $errors[] = $name . " must require the OpenCode CLI checksum on all {$expected} reviewer action(s)";
    }
}
foreach (array('linux-x64', 'linux-arm64') as $arch) {
    $manifest_hash = (string) (($cli['sha256'] ?? array())[$arch] ?? '');
    $escaped_arch = preg_quote($arch, '/');
    $assignment_count = preg_match_all('/\[' . $escaped_arch . '\]\s*=\s*"([a-f0-9]{64})"/', $setup_source, $matches);
    if (1 !== $assignment_count) {
        $errors[] = "setup-opencode.sh must have exactly one SHA-256 assignment for {$arch}";
        continue;
    }
    if ($manifest_hash !== ($matches[1][0] ?? '')) {
        $errors[] = "manifest hash for {$arch} does not match setup-opencode.sh architecture assignment";
    }
}
foreach (array(
    'aarch64|arm64' => 'linux-arm64',
    'x86_64|amd64' => 'linux-x64',
) as $machine_arch => $asset_arch) {
    $pattern = '/' . preg_quote($machine_arch, '/') . '\)\s*ARCH\s*=\s*"' . preg_quote($asset_arch, '/') . '"/';
    if (1 !== preg_match($pattern, $setup_source)) {
        $errors[] = "setup-opencode.sh must map {$machine_arch} to {$asset_arch}";
    }
}
if (substr_count($research_source, 'OPENCODE_VERSION: v1.18.31') !== 2) {
    $errors[] = 'research-monitor.yml must use the verified CLI version in both install steps';
}
if (!str_contains($setup_source, 'OPENCODE_VERSION="${OPENCODE_VERSION:-v1.18.31}"') || !str_contains($setup_source, 'sha256sum -c')) {
    $errors[] = 'setup-opencode.sh must default to v1.18.31 and verify its archive checksum';
}
if ($errors) {
    foreach (array_unique($errors) as $error) {
        fwrite(STDERR, $error . "\n");
    }
    exit(1);
}

echo "Reviewer dependency contract valid: {$reviewer_ref} (floating); OpenCode {$cli_version}\n";
