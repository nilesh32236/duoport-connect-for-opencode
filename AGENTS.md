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
   hooks may subscribe to the option-scoped `update_option_`/`add_option_`/
   `delete_option_` trio but must stay credential-blind (transient deletes
   only). All three, because deleting an option fires neither of the other two:
   a handler set missing `delete_option_` lets its caches outlive the thing they
   describe and wait out a TTL instead. `delete_option_{option}` passes the
   option name alone, so its callback takes no arguments. This was a wp.org
   review finding.
3. **Keep versions in sync**: main-file `Version:` header, `VERSION` const,
   `readme.txt` Stable tag + Changelog + Upgrade Notice.
4. **Keep the non-affiliation disclaimer** in `readme.txt` (trademark rule).

## Conventions

- Namespace `OpenCodeConnector\`, PSR-4 via `src/autoload.php` (no Composer autoload at runtime).
- snake_case methods in WP-hook-facing code; WPCS enforced via `phpcs.xml` (PSR-4 filenames in `src/`/`tests/` excluded from FileName sniffs).
- `declare(strict_types=1)` + `ABSPATH` guard in every PHP file.
- All user-facing strings use text domain `duoport-connect-for-opencode`.
- Availability probe: 2xx → true; 401 + `CreditsError` → true (valid key, no credits);
  429 → true (throttled: must not lock out valid users); any other 4xx the gateway
  introduces (400/402/403/404/…) and all 5xx/transport exceptions → **fall back to the
  last-known-good flag**, not `false`. Only a proven invalid or missing key reports not
  configured. Bucket membership lives in `ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES`;
  never restate the list at a call site.
- `PROBE_MODEL` is fleet-wide: every probe on every site sends that one model, so a
  retirement upstream fails everywhere at once. The probe passes the model to
  `ConnectionDiagnostics::classify()`, which reports a response that names THAT model as
  the unavailable thing as `probe_model_unavailable` — indeterminate, never a credential
  verdict, so it cannot clear last-known-good. Attribution needs positive model evidence
  (`param: "model"`, a model-scoped code, or the model's name plus a "gone" phrase) and is
  vetoed by a credential-scoped code, so a revoked key is never rescued by model wording.
  401 CreditsError and 429 are keyed verdicts and are never downgraded.

## Release

Tag `v*` → `.github/workflows/release.yml` (ZIP + GitHub Release + wp.org SVN).
PRs/pushes → `.github/workflows/ci.yml` (php -l, WPCS, PHPUnit, PHPCompatibilityWP 8.2-).
AI loop → `ai-review.yml` (review on PRs, fix on `autofix-trigger`/`/fix`, auto-merge on `autofix:ready`).
Daily → `daily-audit.yml` (verify + AI audit → labeled issues).
Weekly → `catalog-watch.yml` (live `/models` vs allowlist drift → issue; script: `.github/scripts/check-catalog-drift.php`).
Weekly → `research-monitor.yml` (5-lane AI research → schema-validated JSON → Tier-A issues; agents in `opencode.json`, schema in `.github/schemas/`).
