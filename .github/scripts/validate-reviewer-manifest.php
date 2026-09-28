<?php
/**
 * Validate the complete reviewer dependency manifest contract offline.
 *
 * Usage:
 *   php .github/scripts/validate-reviewer-manifest.php [--file path]
 *   cat manifest.json | php .github/scripts/validate-reviewer-manifest.php --stdin
 */

declare(strict_types=1);

$root = getenv('DUOPORT_REPO_ROOT') ?: dirname(__DIR__, 2);
$args = array_slice($argv, 1);
$stdin = in_array('--stdin', $args, true);
$file_index = array_search('--file', $args, true);
$file = false !== $file_index ? ($args[$file_index + 1] ?? null) : $root . '/.github/reviewer-dependency.json';
if (!$stdin && (null === $file || !is_file($file))) {
    fwrite(STDERR, "Manifest file is missing\n");
    exit(1);
}
$raw = $stdin ? stream_get_contents(STDIN) : file_get_contents($file);
if (false === $raw || '' === trim($raw)) {
    fwrite(STDERR, "Manifest is empty\n");
    exit(1);
}
try {
    $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, "Manifest JSON is invalid\n");
    exit(1);
}
if (!is_array($manifest)) {
    fwrite(STDERR, "Manifest root must be an object\n");
    exit(1);
}

$errors = array();
$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};
$expect(($manifest['reviewer_repository'] ?? null) === 'nilesh32236/opencode-ai-reviewer', 'reviewer_repository is not the approved repository');
// The reviewer action tracks a floating branch, so there is no release tag or
// commit to validate here. What is validated instead is that the recorded ref
// is the one the workflows actually use, and that the retired release fields
// have not crept back in: a manifest that still carried release_commit would
// imply a pin that no longer exists.
$expect(is_string($manifest['reviewer_ref'] ?? null) && '' !== $manifest['reviewer_ref'], 'reviewer_ref must be a non-empty string');
$expect(($manifest['reviewer_ref'] ?? null) === 'main', 'reviewer_ref must be the reviewer main branch');
$expect(($manifest['reviewer_ref_kind'] ?? null) === 'floating-branch', 'reviewer_ref_kind must be floating-branch');
$expect(is_string($manifest['reviewer_ref_note'] ?? null) && '' !== trim($manifest['reviewer_ref_note']), 'reviewer_ref_note must explain the floating ref and its trade-off');
foreach (array('release_tag', 'release_commit', 'release_published_at', 'release_url', 'updated_at') as $retired) {
    $expect(!array_key_exists($retired, $manifest), "retired field {$retired} must not remain while the reviewer ref is floating");
}

$cli = $manifest['opencode_cli'] ?? null;
$expect(is_array($cli), 'opencode_cli must be an object');
$cli = is_array($cli) ? $cli : array();
$expect(($cli['version'] ?? null) === 'v1.18.31', 'opencode_cli.version must be the reviewed v1.18.31');
$expect(($cli['release_url'] ?? null) === 'https://github.com/anomalyco/opencode/releases/tag/v1.18.31', 'opencode_cli.release_url is not the reviewed release');
$hashes = $cli['sha256'] ?? null;
$expect(is_array($hashes), 'opencode_cli.sha256 must be an object');
$hashes = is_array($hashes) ? $hashes : array();
foreach (array('linux-x64', 'linux-arm64') as $arch) {
    $expect(is_string($hashes[$arch] ?? null) && preg_match('/^[a-f0-9]{64}$/', $hashes[$arch]) === 1, "opencode_cli.sha256.{$arch} must be a full SHA-256");
}
$expect(($hashes['linux-x64'] ?? null) !== ($hashes['linux-arm64'] ?? null), 'linux-x64 and linux-arm64 hashes must be distinct');

$gate = $manifest['merge_gate'] ?? null;
$expect(is_array($gate), 'merge_gate must be an object');
$gate = is_array($gate) ? $gate : array();
$expect(($gate['ruleset_id'] ?? null) === 23935160, 'merge_gate.ruleset_id must be 23935160');
$expect(($gate['pull_request_ruleset_id'] ?? null) === 23935219, 'merge_gate.pull_request_ruleset_id must be 23935219');
$expect(($gate['required_checks'] ?? null) === array('PHPCS + PHPUnit (PHP 8.2)', 'PHPCS + PHPUnit (PHP 8.3)'), 'merge_gate.required_checks must be the exact PHP matrix list');

if ($errors) {
    foreach (array_unique($errors) as $error) {
        fwrite(STDERR, $error . "\n");
    }
    exit(1);
}
echo "Reviewer manifest contract valid\n";
