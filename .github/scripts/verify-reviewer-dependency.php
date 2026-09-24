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

$tag = (string) ($manifest['release_tag'] ?? '');
$commit = (string) ($manifest['release_commit'] ?? '');
$cli = $manifest['opencode_cli'] ?? array();
$cli_version = (string) ($cli['version'] ?? '');
$errors = array();
if (!preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/', $tag)) {
    $errors[] = 'release_tag must be a stable vX.Y.Z tag';
}
if (!preg_match('/^[a-f0-9]{40}$/', $commit)) {
    $errors[] = 'release_commit must be a full 40-character lowercase SHA';
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
$merge_gate = $manifest['merge_gate'] ?? array();
if (!is_array($merge_gate) || (int) ($merge_gate['ruleset_id'] ?? 0) < 1) {
    $errors[] = 'merge_gate.ruleset_id must identify the required-check ruleset';
}
$required_checks = (array) ($merge_gate['required_checks'] ?? array());
foreach (array('PHPCS + PHPUnit (PHP 8.2)', 'PHPCS + PHPUnit (PHP 8.3)') as $check) {
    if (!in_array($check, $required_checks, true)) {
        $errors[] = "merge_gate.required_checks is missing {$check}";
    }
}

$workflow_dir = $root . '/.github/workflows';
$workflow_files = array_merge(
    glob($workflow_dir . '/*.yml') ?: array(),
    glob($workflow_dir . '/*.yaml') ?: array()
);
$reference_count = 0;
foreach ($workflow_files as $file) {
    if ('reviewer-update.yml' === basename($file)) {
        continue;
    }
    $source = (string) file_get_contents($file);
    if (preg_match_all('/uses:\s*nilesh32236\/opencode-ai-reviewer@([^\s#]+)(?:\s+#\s*(\S+))?/', $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            ++$reference_count;
            if ($commit !== ($match[1] ?? '')) {
                $errors[] = basename($file) . ' uses reviewer SHA ' . ($match[1] ?? '') . ' instead of manifest SHA';
            }
            if ($tag !== ($match[2] ?? '')) {
                $errors[] = basename($file) . ' reviewer tag comment is missing or stale';
            }
        }
    }
}
if (4 !== $reference_count) {
    $errors[] = "expected four reviewer action references, found {$reference_count}";
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
    if ('' === $manifest_hash || !str_contains($setup_source, $manifest_hash)) {
        $errors[] = "manifest hash for {$arch} is not bound to setup-opencode.sh";
    }
}
if (substr_count($research_source, 'OPENCODE_VERSION: v1.18.31') !== 2) {
    $errors[] = 'research-monitor.yml must use the verified CLI version in both install steps';
}
if (!str_contains($setup_source, 'OPENCODE_VERSION="${OPENCODE_VERSION:-v1.18.31}"') || !str_contains($setup_source, 'sha256sum -c')) {
    $errors[] = 'setup-opencode.sh must default to v1.18.31 and verify its archive checksum';
}
$updater_source = (string) file_get_contents($workflow_dir . '/reviewer-update.yml');
$guard_path = $root . '/.github/scripts/reviewer-pr-guard.sh';
if (!is_file($guard_path) || !is_executable($guard_path)) {
    $errors[] = 'reviewer-pr-guard.sh must be present and executable';
}
foreach (array('releases/latest', 'reviewer-dependency.json', 'pull-requests: write', 'concurrency:', 'GH_PAT', 'force-with-lease', 'Campaign PR guard failed', 'Skip already-current dependency branch', 'proceed=false', 'headRepositoryOwner', 'ensure_pr', 'reviewer-pr-guard.sh') as $needle) {
    if (!str_contains($updater_source, $needle)) {
        $errors[] = 'reviewer-update.yml is missing required traceability/safety contract: ' . $needle;
    }
}

if ($errors) {
    foreach (array_unique($errors) as $error) {
        fwrite(STDERR, $error . "\n");
    }
    exit(1);
}

echo "Reviewer dependency contract valid: {$tag} @ {$commit}; OpenCode {$cli_version}\n";
