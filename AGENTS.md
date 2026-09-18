# AGENTS.md — DuoPort Connector for OpenCode

## Commands

```sh
composer install   # dev deps
vendor/bin/phpunit # unit tests (tests/Unit, Brain Monkey)
vendor/bin/phpcs   # WPCS, project ruleset (phpcs.xml)
./scripts/build-release.sh  # distributable ZIP via .distignore
```

No Node, no build step. `vendor/` is git-ignored and never ships.

## Hard rules (learned from production bugs + wp.org review)

1. **Never share/alias `setting_name` between the Go and Zen connectors.**
   Core's `/wp/v2/settings` dispatch validates and masks EACH connector's
   setting independently — aliasing makes the second connector validate the
   first one's masked placeholder, the save is reverted, and valid keys are
   rejected. Each catalog keeps its own key (`connectors_ai_opencode_{go,zen}_api_key`).
2. **Never read or write any `connectors_ai_*` option value.** Cache-bust
   hooks may subscribe to `update_option_`/`add_option_` hooks but must stay
   credential-blind (transient deletes only). This was a wp.org review finding.
3. **Keep versions in sync**: main-file `Version:` header, `VERSION` const,
   `readme.txt` Stable tag + Changelog + Upgrade Notice.
4. **Keep the non-affiliation disclaimer** in `readme.txt` (trademark rule).

## Conventions

- Namespace `OpenCodeConnector\`, PSR-4 via `src/autoload.php` (no Composer autoload at runtime).
- snake_case methods in WP-hook-facing code; WPCS enforced via `phpcs.xml` (PSR-4 filenames in `src/`/`tests/` excluded from FileName sniffs).
- `declare(strict_types=1)` + `ABSPATH` guard in every PHP file.
- All user-facing strings use text domain `duoport-connect-for-opencode`.
- Availability probe: 2xx → true; 401 + `CreditsError` → true (valid key, no credits);
  429 → true (throttled: must not lock out valid users); other 4xx/5xx + exceptions → false.

## Release

Tag `v*` → `.github/workflows/release.yml` (ZIP + GitHub Release + wp.org SVN).
PRs/pushes → `.github/workflows/ci.yml` (php -l, WPCS, PHPUnit, PHPCompatibilityWP 8.2-).
