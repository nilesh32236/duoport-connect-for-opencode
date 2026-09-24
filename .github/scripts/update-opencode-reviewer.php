<?php
/**
 * Update the released OpenCode AI Reviewer references from validated release data.
 *
 * Usage:
 *   php .github/scripts/update-opencode-reviewer.php --tag v1.22.0 --commit <40-hex> --published <ISO8601> [--dry-run]
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifest_path = $root . '/.github/reviewer-dependency.json';
$args = array_slice($argv, 1);
$dry_run = in_array('--dry-run', $args, true);
$get_arg = static function (string $name) use ($args): ?string {
    $index = array_search($name, $args, true);
    return false === $index ? null : ($args[$index + 1] ?? null);
};
$tag = $get_arg('--tag');
$commit = $get_arg('--commit');
$published = $get_arg('--published');

if (null === $tag || null === $commit || null === $published) {
    fwrite(STDERR, "Usage: --tag vX.Y.Z --commit 40-hex --published ISO8601 [--dry-run]\n");
    exit(1);
}
if (!preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/', $tag)) {
    fwrite(STDERR, "Refusing non-stable reviewer tag: {$tag}\n");
    exit(1);
}
if (!preg_match('/^[a-f0-9]{40}$/', $commit)) {
    fwrite(STDERR, "Refusing invalid reviewer commit SHA\n");
    exit(1);
}
if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $published)) {
    fwrite(STDERR, "Refusing invalid release publication timestamp\n");
    exit(1);
}

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

$files = glob($root . '/.github/workflows/*.yml') ?: array();
$updated = array();
$manifest_changed = false;
$pattern = '/(uses:\s*nilesh32236\/opencode-ai-reviewer@)[a-f0-9]{40}(\s+#\s*v[0-9]+\.[0-9]+\.[0-9]+)?/';
$count = 0;
foreach ($files as $file) {
    $source = (string) file_get_contents($file);
    if (str_contains($source, 'nilesh32236/opencode-ai-reviewer@main')) {
        fwrite(STDERR, basename($file) . " still uses mutable reviewer main\n");
        exit(1);
    }
    $replaced = preg_replace_callback(
        $pattern,
        static function (array $match) use ($tag, $commit, &$count): string {
            ++$count;
            return $match[1] . $commit . ' # ' . $tag;
        },
        $source,
        -1,
        $replacements
    );
    if (null === $replaced) {
        fwrite(STDERR, basename($file) . " could not be updated\n");
        exit(1);
    }
    if ($replacements > 0) {
        $updated[] = $file;
        if (!$dry_run) {
            file_put_contents($file, $replaced);
        }
    }
}
if ($count < 1) {
    fwrite(STDERR, "No reviewer workflow references found\n");
    exit(1);
}

$manifest_values = array(
    'release_tag' => $tag,
    'release_commit' => $commit,
    'release_published_at' => $published,
    'release_url' => 'https://github.com/nilesh32236/opencode-ai-reviewer/releases/tag/' . $tag,
);
foreach ($manifest_values as $key => $value) {
    if (($manifest[$key] ?? null) !== $value) {
        $manifest[$key] = $value;
        $manifest_changed = true;
    }
}
if ($manifest_changed) {
    $manifest['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $manifest_raw = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (!$dry_run) {
        file_put_contents($manifest_path, $manifest_raw);
    }
}

echo json_encode(array(
    'tag' => $tag,
    'commit' => $commit,
    'published' => $published,
    'references_updated' => $count,
    'manifest_changed' => $manifest_changed,
    'files' => array_map(static fn(string $file): string => str_replace($root . '/', '', $file), $updated),
    'dry_run' => $dry_run,
), JSON_UNESCAPED_SLASHES) . "\n";
