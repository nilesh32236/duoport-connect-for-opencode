# DuoPort Connector for OpenCode

Connect [OpenCode](https://opencode.ai) Go and Zen catalogs (including free models) to the WordPress 7.0+ AI Client.

> **Not affiliated with or endorsed by OpenCode (Anomaly Innovations, Inc.).**
>
> WordPress.org listing: https://wordpress.org/plugins/duoport-connect-for-opencode

Registers two AI providers — **OpenCode Go** (subscription catalog) and **OpenCode Zen** (pay-as-you-go catalog including free models) — with the WordPress AI Client. Enter your API key in both the Go and Zen fields on Settings → Connectors; the same opencode.ai key works for both catalogs. `OPENCODE_GO_API_KEY` / `OPENCODE_ZEN_API_KEY` constants or env vars are also supported.

## Install (users)

Install from WordPress.org, or download the release ZIP from the
[Releases](../../releases) page and upload it via Plugins → Add New → Upload.

Requires WordPress 7.0+ and PHP 8.2+.

## Develop

```sh
composer install          # dev deps (WPCS, PHPUnit, Brain Monkey)
vendor/bin/phpunit        # unit tests
vendor/bin/phpcs          # WordPress Coding Standards (phpcs.xml)
vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.2- --ignore=vendor/* .
./scripts/build-release.sh  # build the distributable ZIP (uses .distignore)
```

`vendor/` is git-ignored and never ships — the plugin has no runtime
Composer dependencies (it autoloads its own `src/` and uses core's AI Client).

## Release process

1. Bump the version in `duoport-connect-for-opencode.php` (`Version:` header
   + `VERSION` const), `readme.txt` (`Stable tag`, Changelog, Upgrade Notice).
2. Commit and push to `main`.
3. Tag: `git tag v0.1.3 && git push origin v0.1.3`
4. The `release.yml` workflow builds the ZIP, creates the GitHub Release,
   and deploys to WordPress.org SVN (`trunk/` + tag).

Required repo secrets (`Settings → Secrets and variables → Actions`):

| Secret         | Purpose                                              |
|----------------|------------------------------------------------------|
| `SVN_USERNAME` | WordPress.org username (`nilesh912`) for SVN deploy  |
| `SVN_PASSWORD` | SVN password from your WordPress.org profile for SVN deploy |

WordPress.org assets (banner, icon) live in `assets/` and are deployed to
the SVN `assets/` directory — they are excluded from the user-facing ZIP
via `.distignore`.

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

## License

GPL-2.0-or-later. See `readme.txt`.
