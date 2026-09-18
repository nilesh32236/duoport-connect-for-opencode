# Contributing

## Develop

```sh
composer install          # dev deps (WPCS, PHPUnit, Brain Monkey)
vendor/bin/phpunit        # unit tests
vendor/bin/phpcs          # WordPress Coding Standards (phpcs.xml)
vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.2- --ignore=vendor/*,.github/* .
./scripts/build-release.sh  # build the distributable ZIP (uses .distignore)
```

`vendor/` is git-ignored and never ships — the plugin has no runtime
Composer dependencies (it autoloads its own `src/` and uses core's AI Client).

See [AGENTS.md](AGENTS.md) for hard rules (connector settings, credential
handling, version sync) and conventions.

## Release process

1. Bump the version in `duoport-connect-for-opencode.php` (`Version:` header
   + `VERSION` const), `readme.txt` (`Stable tag`, Changelog, Upgrade Notice).
2. Commit and push to `main` (CI must be green).
3. Tag: `git tag v0.1.3 && git push origin v0.1.3`
4. The `release.yml` workflow builds the ZIP, creates the GitHub Release,
   and deploys to WordPress.org SVN (`trunk/` + tag).

## Automation

| Workflow | Trigger | What it does |
|----------|---------|--------------|
| `release.yml` | `v*` tag | ZIP + GitHub Release + wp.org SVN deploy |
| `ci.yml` | Push/PR | `php -l`, WPCS, PHPUnit, PHP compat 8.2+ |
| `ai-review.yml` | PR opened/synced, `autofix-trigger` label, `/review` `/fix` `/oc` comments | AI review + fix loop + auto-merge on `autofix:ready` |
| `daily-audit.yml` | Daily 2 AM UTC + manual | Verification suite + AI audit → issues (auto-fixable) |
| `catalog-watch.yml` | Weekly Monday + manual | Diffs live OpenCode `/models` vs allowlist → drift issue |

## Required repo secrets

`Settings → Secrets and variables → Actions`:

| Secret             | Purpose                                                  |
|--------------------|----------------------------------------------------------|
| `SVN_USERNAME`     | WordPress.org username for SVN deploy                    |
| `SVN_PASSWORD`     | SVN password from your WordPress.org profile             |
| `OPENCODE_API_KEY` | OpenCode gateway key — powers the AI review/audit workflows |
| `GH_PAT`           | Optional: personal access token (`repo`, `workflow`) used instead of `GITHUB_TOKEN` where workflow-file writes are needed |

Optional repo variable: `OPENCODE_MODEL` (model for AI workflows, defaults to
`opencode/muse-spark-1.3-contributor-free`).

WordPress.org assets (banner, icon) live in `.wordpress-org/` and are deployed to
the SVN `assets/` directory — that folder is excluded from the user-facing ZIP
via `.distignore`. The runtime provider icon is `assets/images/opencode.svg`
and *does* ship in the ZIP.

## Layout

```
duoport-connect-for-opencode.php  Plugin entry (provider registration, cache busting)
src/Providers/    OpenCode Go / Zen provider definitions
src/Models/       Chat/completions text-generation models
src/Metadata/     Model catalogs + allowlist (chat-capable models, free labels)
src/Availability/ Key validation probe (transient-cached)
src/Settings/     "Show all models" toggle + connection status page
tests/Unit/       PHPUnit + Brain Monkey specs
```
