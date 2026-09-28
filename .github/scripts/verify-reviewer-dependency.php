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
foreach ($workflow_files as $file) {
    $source = (string) file_get_contents($file);
    if (preg_match_all('/uses:\s*nilesh32236\/opencode-ai-reviewer@([^\s#]+)/', $source, $matches)) {
        foreach ($matches[1] as $used_ref) {
            ++$reference_count;
            if ($reviewer_ref !== $used_ref) {
                $errors[] = basename($file) . " uses reviewer ref '{$used_ref}' instead of the manifest ref '{$reviewer_ref}'";
            }
        }
    }
    // A stale trailing "# vX.Y.Z" comment would claim a version the floating
    // ref no longer corresponds to, which is exactly the drift this manifest
    // used to prevent. Reject it so the comment cannot outlive the pin.
    if (preg_match('/uses:\s*nilesh32236\/opencode-ai-reviewer@\S+\s+#\s*\S/', $source)) {
        $errors[] = basename($file) . ' reviewer reference carries a stale version comment';
    }
}
if (4 !== $reference_count) {
    $errors[] = "expected four reviewer action references, found {$reference_count}";
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

$review_source = (string) file_get_contents($workflow_dir . '/ai-review.yml');
$audit_source = (string) file_get_contents($workflow_dir . '/daily-audit.yml');
$research_source = (string) file_get_contents($workflow_dir . '/research-monitor.yml');
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
