# Compatibility & Coding Standards Audit — DuoPort Connect for OpenCode

**Agent:** agent-compatibility (WordPress Compatibility / Coding Standards Specialist)
**Date:** 2026-08-28
**Scope:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/duoport-connect-for-opencode` — all production PHP, `readme.txt`, headers, `composer.json`, `phpcs.xml`, `uninstall.php`, `assets/`, i18n, multisite, object-cache, hosting.
**Methodology:** Full file reads; `php -l` syntax check; `vendor/bin/phpcs --standard=WordPress` with and without `phpcs.xml`; `vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.2-`; `vendor/bin/phpunit` (7 tests, 0 failures); manual header/readme/license/domain cross-check; multisite/object-cache/hosting code search. No production code modified.

---

## 1. Executive Summary

| Area | Verdict |
|------|---------|
| WP core version declaration (7.0 vs 7.1) | **Stale — Tested up to lags** |
| PHP 8.2+ compat (`readonly`, `strict_types`, enums, polyfills) | **Pass** |
| Multisite | **Partial — single-site only, missing `site_transient`/`site_option` handling** |
| Object-cache / persistent cache | **Pass with note — transient + `AiClient::getCache()` dual layer** |
| Hosting (Apache / Nginx / LiteSpeed) | **Pass — hosting-agnostic** |
| WPCS / PHPCS (WordPress) | **Pass with config — 0 errors under `phpcs.xml`, 30 errors without exclusions (by design), 2 warnings** |
| PHPCompatibilityWP (8.2–) | **Pass — 0 errors on product code** |
| Plugin header completeness | **Incomplete — optional headers missing** |
| `readme.txt` completeness | **Pass with stale `Tested up to`** |
| Text Domain / Domain Path / License / Stable tag | **Pass except `Domain Path` dir missing** |
| Contributors / Tags | **Pass** |
| Compatibility layers (`AiClient::VERSION` gates, WP guard) | **Pass with observation** |

**Highest-priority action:** Bump `readme.txt:5` `Tested up to: 7.0` → `7.1` (and re-test on WP 7.1) and create/declare `languages/` handling or remove `Domain Path` claim. Medium: add `delete_site_transient`/`delete_site_option` handling for multisite.

---

## 2. WordPress Core Version — Declared 7.0 vs Current 7.1

### 2.1 Declarations

| File:Line | Value |
|-----------|-------|
| `duoport-connect-for-opencode.php:5` | `Requires at least: 7.0` |
| `duoport-connect-for-opencode.php:4` | `Description: ... WordPress 7.0 AI` |
| `readme.txt:4` | `Requires at least: 7.0` |
| `readme.txt:5` | `Tested up to: 7.0` |
| `readme.txt:11` | `Connect ... to WordPress 7.0 AI.` |
| `readme.txt:15` | `Registers ... with the WordPress 7.0 AI Client.` |
| `readme.txt:48-50` | FAQ `Does it work without WordPress 7.0?` / `Requires WordPress 7.0+` |
| `duoport-connect-for-opencode.php:31` | Comment `// Guard: WP < 7.0 or SDK missing` |
| `duoport-connect-for-opencode.php:35` | `version_compare( get_bloginfo('version'), '7.0', '>=' )` |
| `duoport-connect-for-opencode.php:39` | Notice text `requires WordPress 7.0+.` |

### 2.2 Findings

#### C-WP-01 — `Tested up to` stale (MEDIUM)

- **File:Line:** `readme.txt:5`
- **Problem:** Core is now **7.1** per audit brief; `Tested up to: 7.0` lags one minor. WordPress.org directory and the plugin-check tool flag `Tested up to` < latest stable as a warning; it also suppresses the "compatible with your version" badge for 7.1 users.
- **Impact:** Directory listing shows "untested with your version of WordPress" for 7.1 installs; review queue may request bump.
- **Recommendation:** Set `readme.txt:5` to `Tested up to: 7.1` **after** running the unit and manual smoke test on WP 7.1 + AI Client bundled with 7.1. Keep `Requires at least: 7.0` unless 7.1 API break is confirmed.

#### C-WP-02 — `Requires at least: 7.0` is correct to retain (INFO)

- **File:Line:** `duoport-connect-for-opencode.php:5`, `readme.txt:4`
- **Verdict:** Retaining `7.0` as minimum is correct. The AI Client SDK (`WordPress\AiClient\AiClient`) was introduced in WP 7.0. Bumping minimum to 7.1 would unnecessarily drop 7.0 users with no break. Confirm by checking that `AbstractApiProvider`, `AbstractOpenAiCompatibleModelMetadataDirectory`, `ProviderMetadata` signatures are unchanged between AI Client versions shipped in 7.0 and 7.1. If they changed, gate with `AiClient::VERSION` checks (already done at `src/Providers/AbstractOpenCodeProvider.php:116-121`).

#### C-WP-03 — Description strings still say "7.0" (LOW)

- **File:Line:** `duoport-connect-for-opencode.php:4`, `readme.txt:11`, `readme.txt:15`
- **Problem:** Copy says "WordPress 7.0 AI" / "WordPress 7.0 AI Client". After 7.1 ships, this reads as outdated even though functionality is version-gated.
- **Recommendation:** Change to `WordPress AI` (without version) or `WordPress 7.0+ AI` to avoid per-minor churn. E.g. `duoport-connect-for-opencode.php:4` → `Connect OpenCode Go and Zen (including free models) to WordPress AI.`

#### C-WP-04 — `Requires at least` vs `Tested up to` mismatch is not a fatal (INFO)

- WordPress.org validator requires `Requires at least` ≤ `Tested up to`. `7.0 ≤ 7.0` passes today; after fixing C-WP-01, `7.0 ≤ 7.1` will also pass.

### 2.3 WP Guard Runtime Behaviour

- `duoport-connect-for-opencode.php:32-42` admin notice and `duoport-connect-for-opencode.php:44-71` `init` registration both guard on `class_exists(\WordPress\AiClient\AiClient::class)` and `version_compare`. Correct defensive pattern for hosting where WP core is 7.0/7.1 but AI Client plugin may be inactive. Message at `duoport-connect-for-opencode.php:39` is generic (mentions only WP version, not SDK) — see `agent-php-correctness` F-DC-04.

---

## 3. PHP 8.2+ Compatibility

### 3.1 Declared Requirement

| File:Line | Value |
|-----------|-------|
| `duoport-connect-for-opencode.php:6` | `Requires PHP: 8.2` |
| `readme.txt:6` | `Requires PHP: 8.2` |
| `composer.json:32` | `testVersion 8.2-` (PHPCS config) |
| `phpcs.xml:11` | `<config name="testVersion" value="8.2-"/>` |

### 3.2 Language Features Used

| Feature | File:Line | Since | Safe? |
|---------|-----------|-------|-------|
| `declare(strict_types=1)` | `duoport-connect-for-opencode.php:18` + every `src/**/*.php:11` | PHP 7.0 | Yes — uniformly applied |
| `private readonly string $catalog` (constructor promotion + `readonly`) | `src/Availability/OpenCodeProviderAvailability.php:46` | PHP 8.1 | **Yes** — Requires PHP 8.2, so 8.1+ feature is safe |
| `str_starts_with` | `src/autoload.php:21` | PHP 8.0 | Yes |
| `str_contains` | `tests/Unit/LegacyApprovalSourceTest.php:42` + `src/Providers/AbstractOpenCodeProvider.php:115` comment logic | PHP 8.0 | Yes |
| `match` / enums | **Not defined** — consumed via `WordPress\AiClient\Providers\Enums\ProviderTypeEnum`, `CapabilityEnum`, `OptionEnum`, `ModalityEnum`, `HttpMethodEnum`, `RequestAuthenticationMethod` | — | Plugin does not declare `enum`; no 8.1 enum compat risk |
| `mixed` type | `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:68`, `src/Settings/Settings.php:58` | PHP 8.0 | Yes |
| Arrow functions `static fn` | `src/Providers/AbstractOpenCodeProvider.php:96` | PHP 7.4 | Yes |
| Null coalesce `??`, spaceship `<=>` | `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:87,128` | PHP 7.0 | Yes |
| `#[\Attribute]` (PHPUnit) | `tests/Unit/ConnectorOverrideTest.php:32-33` | PHP 8.0 | Test-only, not shipped (`.distignore:18` excludes `tests/`) |

### 3.3 Missing `strict_types` in `uninstall.php`

- **File:Line:** `uninstall.php:1` — no `declare(strict_types=1)`.
- **Severity:** INFO. WordPress loads `uninstall.php` via `include` after defining `WP_UNINSTALL_PLUGIN`; `strict_types` is per-file, so absence is not a bug, but for consistency add `declare(strict_types=1);` on line 2 (after `<?php`). Not a PHPCompatibility violation.

### 3.4 PHPCompatibilityWP Results

- **Command:** `vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.2- --ignore=vendor/* .`
- **Result on product code:** **0 errors, 0 warnings** — verified by running `vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.2 --extensions=php ./duoport-connect-for-opencode.php ./src ./uninstall.php` (exit 0, no output after filtering `vendor`).
- **Command with `vendor` included:** warnings/errors only from `vendor/` (e.g. `antecedent/patchwork` `debug_backtrace` warning at `vendor/antecedent/patchwork/src/CallRerouting.php:362`) — excluded in CI via `--ignore=vendor/*` (`composer.json:33` `compat` script does this correctly).
- **PHP 8.3 re-test:** `vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.3-` also 0 errors on product code (runtime is PHP 8.3.33).

**Verdict:** PHP 8.2+ compatibility **passes**. `readonly` usage is the only 8.1+ feature and is correctly gated by `Requires PHP: 8.2`.

---

## 4. Multisite Compatibility

### 4.1 Code Search

- `default.grep` for `is_multisite`, `get_sites`, `switch_to_blog`, `get_site_option`, `delete_site_option`, `get_site_transient`, `delete_site_transient`, `network` — **0 hits in product code** (only `uninstall.php:9` `WP_UNINSTALL_PLUGIN` guard and vendor hits).

### 4.2 Findings

#### C-MS-01 — Uninstall is single-site only (MEDIUM)

- **File:Line:** `uninstall.php:12-14`
- **Code:**
  ```php
  delete_option( 'opencode_connector_settings' );
  delete_transient( 'opencode_connector_avail_go' );
  delete_transient( 'opencode_connector_avail_zen' );
  ```
- **Problem:** No `delete_site_option`, `delete_site_transient`, or `is_multisite` loop. Since WP 5.1, `uninstall.php` is invoked **per site** when a network-activated plugin is deleted via Network Admin, so `delete_option` suffices for per-site options. However, if the plugin is ever `Network: true` or stores site-wide transients (`ai_client_*` model caches) via `set_site_transient`, those would be orphaned. Current product code uses `get_option` (`src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:87`, `src/Settings/Settings.php:147`) and `get_transient`/`delete_transient` — all per-site.
- **Recommendation:** Document that plugin is **per-site** (no `Network: true` header). Optionally add defensive multisite cleanup:
  ```php
  delete_option( 'opencode_connector_settings' );
  if ( is_multisite() ) {
      delete_site_option( 'opencode_connector_settings' );
      delete_site_transient( 'opencode_connector_avail_go' );
      delete_site_transient( 'opencode_connector_avail_zen' );
  }
  ```
  And enumerate `ai_client_*` keys via `delete_site_transient` as well (see C-MS-02 / `agent-php-correctness` F-UN-01).

#### C-MS-02 — `ai_client_*` model cache not cleaned on uninstall (MEDIUM) — also F-UN-01

- **File:Line:** `uninstall.php:12-14` vs `src/Settings/Settings.php:112` `$full_key = 'ai_client_' . AiClient::VERSION . '_' . md5($cls) . '_models'`
- **Problem:** `Settings::clearModelCaches()` deletes `ai_client_*` transients/options on settings toggle, but `uninstall.php` does not. Leaves orphaned `_transient_ai_client_*` + `_transient_timeout_*` rows in `wp_options` (or `wp_sitemeta` on multisite).
- **Recommendation:** Replicate `clearModelCaches` logic in `uninstall.php` or wildcard-delete: `DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ai_client_%_models%'` (with `$wpdb->esc_like`).

#### C-MS-03 — `Settings::clearModelCaches()` uses `$wpdb->options` only (LOW)

- **File:Line:** `src/Settings/Settings.php:120-124`
- **Problem:** Direct `$wpdb->query( DELETE FROM {$wpdb->options} WHERE ... )` targets single-site `wp_options`. On multisite with persistent object cache, model caches may reside in `wp_sitemeta` as site transients. No `delete_site_transient($full_key)` call. Covered by `agent-php-correctness` F-ST-05.
- **Recommendation:** After `delete_transient($full_key)`, also `delete_site_transient($full_key)` when `is_multisite()`.

#### C-MS-04 — No `Network:` header — implicit per-site (INFO)

- **File:Line:** `duoport-connect-for-opencode.php:1-16` — no `Network: true` header.
- **Verdict:** Correct — plugin is not a network-only plugin; per-site activation is intended. No change needed, but add a comment in `uninstall.php` clarifying per-site invocation.

**Overall multisite verdict:** Functional on multisite (no `switch_to_blog` needed, no site-wide state), but uninstall/cache cleanup is **single-site only**. No data-loss risk, but orphaned transients remain on multisite.

---

## 5. Object-Cache / Persistent Cache Compatibility

### 5.1 Usage Inventory

| File:Line | API | Purpose |
|-----------|-----|---------|
| `src/Availability/OpenCodeProviderAvailability.php:65` | `get_transient('opencode_connector_avail_'.$catalog)` | Cache availability probe result |
| `src/Availability/OpenCodeProviderAvailability.php:105` | `set_transient($tkey, (int)$ok, 5*MINUTE_IN_SECONDS)` | Store `0`/`1` for 5 min |
| `duoport-connect-for-opencode.php:75-76` | `delete_transient` via `$opencode_connector_bust` | Bust on Go key change |
| `src/Settings/Settings.php:77-78,94-95,118` | `delete_transient` | Bust on settings toggle |
| `src/Settings/Settings.php:110-118` | `AiClient::getCache()->has/delete` + `delete_transient` + `$wpdb` fallback | Clear `ai_client_*` model caches |
| `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:87` | `get_option` | `show_all_models` flag (affects cache key logic) |
| `uninstall.php:13-14` | `delete_transient` | Cleanup |

### 5.2 Findings

#### C-CA-01 — Dual-layer cache handling is correct (PASS)

- **File:Line:** `src/Settings/Settings.php:110-124`
- **Pattern:** `AiClient::getCache()` (likely PSR-16 or object-cache-backed) is checked first (`:112-116`), then `delete_transient` (`:118`), then direct `$wpdb` fallback (`:122-123`). This covers: (a) sites with persistent object cache (Redis/Memcached) where transients are in object cache, (b) sites without, where transients are in `wp_options`. The `phpcs:ignore WordPress.DB.DirectDatabaseQuery` at `src/Settings/Settings.php:122` is justified and documented.

#### C-CA-02 — `set_transient` stores `(int)$ok` but reads with strict `false !== $cached` (PASS — verified, not a bug)

- **File:Line:** `src/Availability/OpenCodeProviderAvailability.php:64-68,105`
- **Logic:** `get_transient` returns `false` on miss, `0`/`1` on hit. `false !== $cached` correctly distinguishes `0` (cached negative) from miss because `0 !== false` under strict comparison. Then `(bool)$cached` normalizes. `agent-php-correctness` F-AV-04 confirmed correct.

#### C-CA-03 — 5-minute TTL for both positive and negative availability is intentional (INFO)

- **File:Line:** `src/Availability/OpenCodeProviderAvailability.php:105` `5 * MINUTE_IN_SECONDS`
- **Verdict:** Debounces repeated `chat/completions` probes. Negative caching prevents hammering OpenCode on invalid keys. TTL is short enough that a user fixing their key sees recovery within 5 min (or immediately after `update_option_connectors_ai_opencode_go_api_key` bust at `duoport-connect-for-opencode.php:78-79`).

#### C-CA-04 — Missing `delete_site_transient` for `ai_client_*` keys (LOW) — same as C-MS-03

- On hosts with persistent object cache + multisite, `delete_transient` may not clear the site-transient variant. Add `delete_site_transient`.

#### C-CA-05 — No cache group / `wp_cache_*` direct use (INFO)

- Plugin does not use `wp_cache_get/set/delete` directly; all via Transient API + AI Client cache abstraction. This is correct — compatible with all object-cache drop-ins (Redis Object Cache, Memcached, LiteSpeed Cache object cache, etc.).

**Overall object-cache verdict:** **Pass.** Pattern is hosting-agnostic and correctly handles both persistent and non-persistent caches. Single improvement: add site-transient deletion for multisite.

---

## 6. Hosting Compatibility (Apache / Nginx / LiteSpeed)

### 6.1 Search

- Grep for `htaccess`, `.htaccess`, `nginx`, `apache`, `litespeed`, `rewrite`, `permalink`, `mod_rewrite`, `$_SERVER` — **0 hits in product code**.

### 6.2 Findings

#### C-HO-01 — No web-server-specific code (PASS)

- Plugin is pure PHP + WordPress APIs (`add_action`, `add_filter`, `register_setting`, `get_option`, transient API, `AiClient` SDK). No `.htaccess` writes, no `$_SERVER['SERVER_SOFTWARE']` branching, no rewrite rules, no `mod_rewrite` dependency.
- Compatible with **Apache** (with or without `mod_rewrite`), **Nginx**, **LiteSpeed**, **OpenLiteSpeed**, and reverse-proxy/CDN frontends. LiteSpeed Cache plugin's object-cache drop-in does not conflict (see C-CA-01).

#### C-HO-02 — No cron / filesystem / background processing (PASS)

- No `WP_Cron`, no `WP_Filesystem`, no `wp-content` writes. Availability probe is synchronous HTTP via `WithHttpTransporterTrait` / `AiClient` HTTP layer (likely `wp_remote_request` under the hood), which works on all hosts. No hosting-specific timeout issue beyond default WP HTTP timeout (see `agent-php-correctness` F-AV-05 low-priority note about explicit timeout).

#### C-HO-03 — HTTPS endpoints only (PASS)

- `src/Providers/OpenCodeGoProvider.php:78` `https://opencode.ai/zen/go/v1`
- `src/Providers/OpenCodeZenProvider.php:78` `https://opencode.ai/zen/v1`
- No `http://` mixed-content. Works behind TLS-terminating proxies.

**Overall hosting verdict:** **Pass — hosting-agnostic.** No Apache/Nginx/LiteSpeed-specific handling required or present.

---

## 7. Coding Standards — WordPress / WPCS / PHPCS

### 7.1 Configuration

| File:Line | Setting |
|-----------|---------|
| `phpcs.xml:3` | `<rule ref="WordPress"/>` — full WPCS |
| `phpcs.xml:4-9` | `<file>.</file>` + `<exclude-pattern>vendor/*>` + `build/*` + `tests/*` |
| `phpcs.xml:10` | `<arg name="extensions" value="php"/>` |
| `phpcs.xml:11` | `<config name="testVersion" value="8.2-"/>` (redundant with CLI, but harmless) |
| `phpcs.xml:13-16` | Excludes `src/*` from `WordPress.Files.FileName` (allows PSR-4 `PascalCase.php`) |
| `phpcs.xml:18-20` | Excludes `src/*` from `WordPress.NamingConventions.ValidFunctionName` (allows `camelCase` for modern namespaced code) |
| `composer.json:31-33` | `"lint": "phpcs"`, `"lint:fix": "phpcbf"`, `"compat": "phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.2- --ignore=vendor/* ."` |
| `.distignore:16` | `phpcs.xml` excluded from distribution zip — correct |
| `composer.json:17-22` | `wp-coding-standards/wpcs ^3.4`, `phpcompatibility/php-compatibility ^9.3`, `phpcompatibility/phpcompatibility-wp ^2.1` |

### 7.2 PHPCS Results

#### 7.2.1 With `phpcs.xml` (CI / `composer lint` — the authoritative run)

- **Command:** `vendor/bin/phpcs` (reads `phpcs.xml`)
- **Result:** **0 errors, 2 warnings in 1 file**
  ```
  FILE: duoport-connect-for-opencode.php
  59 | WARNING | error_log() found. Debug code should not normally be used in production.
  66 | WARNING | error_log() found. Debug code should not normally be used in production.
  ```
- **Verdict:** **Pass.** Both warnings are inside `if (WP_DEBUG && WP_DEBUG_LOG)` guards (`duoport-connect-for-opencode.php:58-60,65-66`) — intentional, not production debug leakage. WPCS `WordPress.PHP.DevelopmentFunctions` is correctly triggered; suppression is not needed because the guard makes it safe. Could add `// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log -- WP_DEBUG gated` to silence, but current state is acceptable (warnings, not errors).

#### 7.2.2 Without `phpcs.xml` (raw `WordPress` standard, no exclusions)

- **Command:** `vendor/bin/phpcs --standard=WordPress --extensions=php ./duoport-connect-for-opencode.php ./src ./uninstall.php`
- **Result:** **30 errors, 2 warnings in 13 files**

| Category | Count | File:Line | Rule |
|----------|-------|-----------|------|
| FileName — expected `lowercase-hyphen` / `class-` prefix | 24 | `src/Availability/OpenCodeProviderAvailability.php:1`, `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:1`, `src/Metadata/ModelAllowlist.php:1`, `src/Metadata/OpenCodeGoModelMetadataDirectory.php:1`, `src/Metadata/OpenCodeZenModelMetadataDirectory.php:1`, `src/Models/AbstractOpenCodeTextGenerationModel.php:1`, `src/Models/OpenCodeGoTextGenerationModel.php:1`, `src/Models/OpenCodeZenTextGenerationModel.php:1`, `src/Providers/AbstractOpenCodeProvider.php:1`, `src/Providers/OpenCodeGoProvider.php:1`, `src/Providers/OpenCodeZenProvider.php:1`, `src/Settings/Settings.php:1` (2 per file × 12 files) | `WordPress.Files.FileName.NotHyphenatedLowercase`, `WordPress.Files.FileName.InvalidClassFileName` |
| ValidFunctionName — expected `snake_case` | 6 | `src/Metadata/ModelAllowlist.php:96` `isAllowed`, `:108` `isFree`, `:120` `displayName`, `src/Settings/Settings.php:74` `bustCaches`, `:91` `bustCachesAdd`, `:105` `clearModelCaches` | `WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid` |

- **Verdict:** **By design — not a regression.** `phpcs.xml:13-20` intentionally excludes `src/*` from these two sniffs. Rationale in `phpcs.xml:12,17` comments is documented: "Allow PSR-4 file naming for namespaced src/" and "Allow camelCase for modern namespaced code — WordPress security/i18n still enforced". This is a **standard and accepted** pattern for plugins using namespaced PSR-4 + AI Client SDK conventions. WordPress.org review does not flag PSR-4 names when `phpcs.xml` exclusions are documented (and `src/` is not the WordPress-typical `includes/` flat structure). No plugin-check failure.

#### 7.2.3 What WPCS *does* enforce on `src/*`

- Security: `WordPress.Security.EscapeOutput`, `NonceVerification` — plugin correctly uses `esc_html__`, `esc_html_e`, `esc_url`, `esc_attr`, `wp_kses_post` everywhere (`src/Settings/Settings.php:152-179`, `duoport-connect-for-opencode.php:39,141`). One intentional `phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped` at `src/Providers/AbstractOpenCodeProvider.php:97` for exception message (not output) — correct.
- I18n: `WordPress.WP.I18n` — all strings use `duoport-connect-for-opencode` domain, with translators comments where needed (`src/Settings/Settings.php:157,167`).
- DB: `WordPress.DB.DirectDatabaseQuery` — flagged at `src/Settings/Settings.php:122-123` but correctly suppressed with `phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fallback for object-cache-less installs.` (`src/Settings/Settings.php:122`).

### 7.3 PHPCompatibilityWP Detail

- **Product-code result (vendor excluded):** **0 errors**
- **Vendor-included run:** only vendor false positives (e.g. `vendor/antecedent/patchwork`, `vendor/squizlabs/php_codesniffer` test fixtures) — excluded in CI.
- **Direct file run:** `vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.2 --extensions=php ./duoport-connect-for-opencode.php ./src ./uninstall.php` → exit 0, no output.
- **Threshold `8.2-` vs `8.2`:** `8.2-` means "test against 8.2 and later" — correctly detects use of features *newer* than 8.2. Plugin uses no 8.3+ features (no `typed class constants`, no `#[\Override]`, no `json_validate`), so passes both `8.2-` and `8.3-`.

---

## 8. Plugin Header Completeness

### 8.1 Current Header (`duoport-connect-for-opencode.php:1-16`)

```
/**
 * Plugin Name:       DuoPort Connector for OpenCode
 * Description:       Connect OpenCode Go and Zen (including free models) to WordPress 7.0 AI.
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Version:           0.1.1
 * Author:            Nilesh Kanzariya
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       duoport-connect-for-opencode
 * Domain Path:       /languages
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */
```

### 8.2 WordPress Header Spec Audit

| Header | Present | Value | Required? | Notes |
|--------|---------|-------|-----------|-------|
| `Plugin Name` | Yes | `DuoPort Connector for OpenCode` | **Required** | Correct |
| `Plugin URI` | **No** | — | Optional | Recommended for directory listing; add if plugin has a homepage (e.g. `https://opencode.ai` or portfolio URL). Not a blocker. |
| `Description` | Yes | `Connect OpenCode Go...` | **Required** | Correct; consider removing version number per C-WP-03 |
| `Version` | Yes | `0.1.1` | **Required** | Matches `readme.txt:7` `Stable tag: 0.1.1` and `const VERSION` at `duoport-connect-for-opencode.php:26` |
| `Requires at least` | Yes | `7.0` | **Required** (WP 5.0+) | Correct; see C-WP-02 |
| `Requires PHP` | Yes | `8.2` | **Required** (WP 5.0+) | Correct |
| `Author` | Yes | `Nilesh Kanzariya` | **Required** | Correct |
| `Author URI` | **No** | — | Optional | Add if desired |
| `License` | Yes | `GPL-2.0-or-later` | **Required for .org** | Correct, matches `readme.txt:8` and `composer.json:5` |
| `License URI` | Yes | `https://www.gnu.org/licenses/gpl-2.0.html` | Required when `License` is `GPL-2.0-*` | Correct |
| `Text Domain` | Yes | `duoport-connect-for-opencode` | **Required** | Correct — matches slug and directory name |
| `Domain Path` | Yes | `/languages` | Optional | Declared but **directory `languages/` does not exist** — see C-I18N-01 |
| `Network` | **No** | — | Optional | Absence = per-site (correct for this plugin) |
| `Update URI` | **No** | — | Optional (WP 5.8+) | Not needed for .org-hosted plugins; add only if self-hosted updates via custom endpoint |
| `Requires Plugins` | **No** | — | Optional (WP 6.5+) | Could declare AI Client dependency if it were a plugin, but AI Client is core-bundled in 7.0+, so not needed |
| `Tested up to` | **Not a header** | — | Readme only | Correctly not in header |

**Verdict:** **Complete for minimum required headers.** Missing `Plugin URI` / `Author URI` are optional. No blocking issue.

### 8.3 `ABSPATH` Guard

- `duoport-connect-for-opencode.php:22-24` `if (!defined('ABSPATH')) exit;` — correct.
- Every `src/**/*.php:15-17` and `uninstall.php:9-11` (`WP_UNINSTALL_PLUGIN`) — correct.

---

## 9. `readme.txt` Completeness

### 9.1 Current `readme.txt:1-9`

```
=== DuoPort Connector for OpenCode ===
Contributors: nilesh912
Tags: ai, opencode, connector, zen, go
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
```

### 9.2 WordPress.org Readme Spec Audit

| Field | Present | Value | Valid? | Notes |
|-------|---------|-------|--------|-------|
| Plugin name `=== ===` | Yes | `DuoPort Connector for OpenCode` | Pass | Must match `Plugin Name` header — does |
| `Contributors` | Yes | `nilesh912` | Pass | wordpress.org username; valid `^[a-z0-9-]+$` |
| `Donate link` | No | — | Optional | Not required |
| `Tags` | Yes | `ai, opencode, connector, zen, go` (5) | Pass | Max 5, lowercase, comma-separated — correct |
| `Requires at least` | Yes | `7.0` | Pass | Must be valid WP version — is |
| `Tested up to` | Yes | `7.0` | **Stale** | See C-WP-01 — should be `7.1` |
| `Requires PHP` | Yes | `8.2` | Pass | Must be valid PHP version — is |
| `Stable tag` | Yes | `0.1.1` | Pass | Matches header `Version: 0.1.1` (`duoport-connect-for-opencode.php:7`) and `const VERSION` (`:26`) — correct |
| `License` | Yes | `GPL-2.0-or-later` | Pass | Matches header and `composer.json:5` |
| `License URI` | Yes | `https://www.gnu.org/licenses/gpl-2.0.html` | Pass | Correct GPL-2.0 URL |
| `== Description ==` | Yes | `readme.txt:13-26` | Pass | Has features list |
| `== Installation ==` | Yes | `readme.txt:28-33` | Pass | 4 steps |
| `== Frequently Asked Questions ==` | Yes | `readme.txt:34-51` | Pass | 3 FAQs |
| `== Screenshots ==` | **No** | — | Optional | No section — acceptable for v0.1.1; add when assets include screenshots |
| `== Changelog ==` | Yes | `readme.txt:62-69` | Pass | `= 0.1.1 =`, `= 0.1.0 =` |
| `== Upgrade Notice ==` | Yes | `readme.txt:71-76` | Pass | Matches changelog |
| `== External Services ==` | Yes | `readme.txt:53-60` | Pass | **Required by .org** for plugins that call external APIs — present and correctly describes `https://opencode.ai`, data sent, model list endpoints, probe, terms/privacy links |

**Verdict:** **Pass with one stale field (`Tested up to`).** No missing required sections.

---

## 10. Text Domain / Domain Path / License / Stable Tag Cross-Check

### 10.1 Text Domain

| Check | File:Line | Value | Matches slug? |
|-------|-----------|-------|---------------|
| Header `Text Domain` | `duoport-connect-for-opencode.php:11` | `duoport-connect-for-opencode` | **Yes** — equals plugin directory `duoport-connect-for-opencode` and slug |
| I18n calls | `duoport-connect-for-opencode.php:39,141`, `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:112`, `src/Settings/Settings.php:136,152,158,164,168,174,175,179` | `duoport-connect-for-opencode` | All 11 `__()`, `esc_html__()`, `esc_html_e()` calls use correct domain |
| `load_plugin_textdomain` | **Not present** | — | WordPress 4.6+ auto-loads from `Domain Path` when Text Domain matches slug; explicit call not required. No issue. |

**Verdict:** **Pass.**

### 10.2 Domain Path

| File:Line | Value | Status |
|-----------|-------|--------|
| `duoport-connect-for-opencode.php:12` | `/languages` | **Declared** |
| Filesystem | `languages/` | **Does not exist** — `ls languages/` → `No such file or directory` |

#### C-I18N-01 — `Domain Path` declares `/languages` but directory is missing (MEDIUM)

- **Severity:** MEDIUM (directory review will flag)
- **Problem:** Header claims translations are in `/languages`, but no such directory exists in the plugin (and `.distignore` does not mention it). WordPress will look for `duoport-connect-for-opencode-{locale}.mo` under `wp-content/plugins/duoport-connect-for-opencode/languages/` and find nothing. GlotPress / translate.wordpress.org expects the path to exist if declared.
- **Fix options:**
  1. **Create `languages/`** with a `.pot` file (e.g. via `wp i18n make-pot`) and keep `Domain Path: /languages` — preferred for .org.
  2. **Remove `Domain Path` header** if relying on WordPress.org language packs (WP 4.6+ auto-discovers packs in `wp-content/languages/plugins/` without `Domain Path`). Either is valid, but header and filesystem must agree.
- **Current `.distignore`:** does not exclude `languages/` — correct for option 1.

### 10.3 License

| File:Line | Value | Consistent? |
|-----------|-------|-------------|
| `duoport-connect-for-opencode.php:9` | `GPL-2.0-or-later` | — |
| `duoport-connect-for-opencode.php:10` | `https://www.gnu.org/licenses/gpl-2.0.html` | — |
| `readme.txt:8` | `GPL-2.0-or-later` | **Yes** |
| `readme.txt:9` | `https://www.gnu.org/licenses/gpl-2.0.html` | **Yes** |
| `composer.json:5` | `GPL-2.0-or-later` | **Yes** |

**Verdict:** **Pass.** `License URI` is required when `License` is GPL and correctly points to `gpl-2.0.html`.

### 10.4 Stable Tag

| File:Line | Value | Matches `Version`? |
|-----------|-------|---------------------|
| `duoport-connect-for-opencode.php:7` | `Version: 0.1.1` | — |
| `duoport-connect-for-opencode.php:26` | `const VERSION = '0.1.1'` | **Yes** |
| `readme.txt:7` | `Stable tag: 0.1.1` | **Yes** |
| `composer.json` | No `version` field (correct — inferred from git tag) | — |

**Verdict:** **Pass.** All three agree. No drift.

---

## 11. Contributors / Tags

### 11.1 Contributors (`readme.txt:2`)

- **Value:** `nilesh912`
- **Spec:** `Contributors: <wordpress.org username>[, ...]` — must be valid .org usernames, lowercase, alphanumeric + hyphen. `nilesh912` is valid.
- **Cross-check:** `Author: Nilesh Kanzariya` (`duoport-connect-for-opencode.php:8`) — display name vs username mismatch is normal (username is .org handle, Author is human name). No issue.

### 11.2 Tags (`readme.txt:3`)

- **Value:** `ai, opencode, connector, zen, go` — 5 tags
- **Spec:** Max 5 tags, lowercase, comma-separated, no duplicates, ideally from the [plugin tag list](https://wordpress.org/plugins/tags/). `ai` is a common tag; `opencode`, `connector`, `zen`, `go` are specific but valid. All lowercase, no spaces inside tags — correct.
- **Header `Tags` is not a plugin header** — only in `readme.txt`, correctly not in `duoport-connect-for-opencode.php`.

**Verdict:** **Pass.**

---

## 12. Compatibility Layers

### 12.1 WordPress / AI Client Version Gating

| File:Line | Layer | Purpose | Correct? |
|-----------|-------|---------|----------|
| `duoport-connect-for-opencode.php:35` | `version_compare(get_bloginfo('version'), '7.0', '>=') && class_exists(AiClient::class)` | Gate admin notice + skip registration on <7.0 or missing SDK | Yes — defensive, non-fatal |
| `duoport-connect-for-opencode.php:47` | `if (!class_exists(AiClient::class)) return;` inside `init:5` | Skip provider registration when SDK absent | Yes |
| `src/Providers/AbstractOpenCodeProvider.php:116` | `version_compare(AiClient::VERSION, '1.2.0', '>=')` then push `description` | Handle `ProviderMetadata` 5-arg vs 6-arg constructor across AI Client 1.1/1.2 | Yes — `agent-php-correctness` F-PR-01 notes positional fragility, but gating is correct |
| `src/Providers/AbstractOpenCodeProvider.php:119` | `version_compare(AiClient::VERSION, '1.3.0', '>=')` then push icon path `assets/images/opencode.svg:120` | Handle optional icon param added in 1.3 | Yes |
| `src/Settings/Settings.php:112` | `'ai_client_' . AiClient::VERSION . '_' . md5($cls) . '_models'` | Versioned cache key — busts correctly on AI Client upgrade | Yes, but hand-rolled — see F-ST-02 |
| `duoport-connect-for-opencode.php:93-99` | `is_object` + `method_exists` guards on `$registry` | Defensive against `wp_connectors_init` payload change | Yes |

### 12.2 PHP Version Gating

- No runtime `version_compare(PHP_VERSION, ...)` needed because `Requires PHP: 8.2` is enforced by WordPress core before activation (WP 5.0+ `Requires PHP` header prevents activation on older PHP). Correct to not duplicate.

### 12.3 Asset Compatibility

- `assets/images/opencode.svg` exists (`assets/images/opencode.svg:1` — 503 bytes). Referenced only when `AiClient::VERSION >=1.3.0` (`src/Providers/AbstractOpenCodeProvider.php:120`), so no 404 on older AI Client.

### 12.4 Findings

#### C-COMP-01 — `AiClient::VERSION` string comparison assumes semver (LOW)

- **File:Line:** `src/Providers/AbstractOpenCodeProvider.php:116,119`
- `version_compare('1.10.0', '1.2.0', '>=')` correctly returns true (string compare handles multi-digit). No bug. If AI Client ever uses `1.2.0-beta`, `version_compare` handles pre-release. No action.

#### C-COMP-02 — No `Requires Plugins` header for AI Client (INFO)

- WordPress 6.5+ supports `Requires Plugins: ai-client` style dependency. Not needed here because AI Client is **core-bundled** in WP 7.0+, not a separate plugin. If the project ever extracts AI Client to a standalone plugin (as in earlier WP AI experiments), this header would be needed. No change now.

**Overall compatibility layers verdict:** **Pass.** Version gates are correctly ordered and defensive. No missing polyfill.

---

## 13. File-by-File Compatibility Notes

| File | Lines | `strict_types` | Key Finding |
|------|-------|----------------|-------------|
| `duoport-connect-for-opencode.php` | 144 | Yes `:18` | C-WP-01 (Tested up stale), header C-I18N-01, WPCS 2 warnings (WP_DEBUG-gated `error_log:59,66`), WP guard correct |
| `src/autoload.php` | 31 | Yes `:11` | Pass — PSR-4, `str_starts_with:21` (PHP 8.0+, safe), `is_readable` hardening possible |
| `src/Availability/OpenCodeProviderAvailability.php` | 108 | Yes `:11` | `readonly:46` (PHP 8.1+, safe), transient 5-min cache correct, probe hard-coded model `deepseek-v4-flash:71` |
| `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php` | 135 | Yes `:11` | `get_option` array-access edge at `:87` (see `agent-php-correctness` F-MD-02) |
| `src/Metadata/ModelAllowlist.php` | 125 | Yes `:11` | No compat issue — constants, `in_array` strict, `ucwords` |
| `src/Metadata/OpenCodeGoModelMetadataDirectory.php` | 49 | Yes `:11` | Pass |
| `src/Metadata/OpenCodeZenModelMetadataDirectory.php` | 49 | Yes `:11` | Pass |
| `src/Providers/AbstractOpenCodeProvider.php` | 148 | Yes `:11` | `AiClient::VERSION` gates `:116,119`, variadic `ProviderMetadata` construction |
| `src/Providers/OpenCodeGoProvider.php` | 80 | Yes `:11` | `baseUrl:78` `https://opencode.ai/zen/go/v1` — correct |
| `src/Providers/OpenCodeZenProvider.php` | 80 | Yes `:11` | `baseUrl:78` `https://opencode.ai/zen/v1` — correct |
| `src/Models/AbstractOpenCodeTextGenerationModel.php` | 76 | Yes `:11` | `prepareResponseFormatParam:63` `json_schema` wrapper correct |
| `src/Models/OpenCodeGoTextGenerationModel.php` | 38 | Yes `:11` | Pass |
| `src/Models/OpenCodeZenTextGenerationModel.php` | 38 | Yes `:11` | Pass |
| `src/Settings/Settings.php` | 183 | Yes `:11` | Object-cache dual layer `:110-124`, multisite site-transient missing |
| `uninstall.php` | 14 | **No** | C-MS-01/02 — single-site cleanup, missing `ai_client_*` + site-transient |
| `readme.txt` | 76 | — | C-WP-01 stale `Tested up to`, otherwise complete |
| `composer.json` | 35 | — | License `GPL-2.0-or-later:5`, `testVersion 8.2-`, `compat` script ignores vendor |
| `phpcs.xml` | 21 | — | Correct WPCS exclusions for PSR-4/camelCase |

---

## 14. Severity-Ranked Findings Summary

| ID | File:Line | Severity | Area | Title |
|----|-----------|----------|------|-------|
| C-I18N-01 | `duoport-connect-for-opencode.php:12` + filesystem | MEDIUM | i18n | `Domain Path: /languages` declared but `languages/` dir missing |
| C-WP-01 | `readme.txt:5` | MEDIUM | WP | `Tested up to: 7.0` stale — should be `7.1` |
| C-MS-01 | `uninstall.php:12-14` | MEDIUM | Multisite | Uninstall single-site only, no `delete_site_*` |
| C-MS-02 | `uninstall.php:12-14` | MEDIUM | Multisite/Cache | `ai_client_*` caches not cleaned on uninstall |
| C-WP-03 | `duoport-connect-for-opencode.php:4`, `readme.txt:11,15` | LOW | WP | Description says "7.0" — should be version-agnostic |
| C-MS-03 | `src/Settings/Settings.php:120-124` | LOW | Multisite/Cache | `clearModelCaches` targets `$wpdb->options` only, no `delete_site_transient` |
| C-CA-04 | `src/Settings/Settings.php:118` | LOW | Cache | Missing `delete_site_transient` for `ai_client_*` |
| C-HO-* | — | INFO | Hosting | Pass — hosting-agnostic, no finding |
| C-COMP-01 | `src/Providers/AbstractOpenCodeProvider.php:116,119` | INFO | Compat | `AiClient::VERSION` semver compare — correct |
| Uninstall `strict_types` | `uninstall.php:1` | INFO | PHP | Missing `declare(strict_types=1)` — add for consistency |
| PHPCS `FileName`/`ValidFunctionName` | 12 files `:1`, `ModelAllowlist.php:96,108,120`, `Settings.php:74,91,105` | INFO | WPCS | 30 errors without `phpcs.xml` — by design, suppressed via documented exclusions |
| PHPCS `error_log` | `duoport-connect-for-opencode.php:59,66` | INFO | WPCS | 2 warnings — WP_DEBUG-gated, safe |

**Counts:** CRITICAL 0, HIGH 0, MEDIUM 4, LOW 4, INFO 5

---

## 15. Recommended Fixes (Priority Order)

1. **P1 — `readme.txt:5` `Tested up to: 7.1`** — bump after testing on WP 7.1 + bundled AI Client; also update `readme.txt:11,15` descriptions to `WordPress AI` (or `WordPress 7.0+ AI`).
2. **P1 — `languages/` dir** — either create `languages/duoport-connect-for-opencode.pot` and keep `Domain Path: /languages` (`duoport-connect-for-opencode.php:12`), or delete the `Domain Path` header if using translate.wordpress.org language packs.
3. **P2 — Multisite uninstall** — patch `uninstall.php:12-14` to also `delete_site_option`/`delete_site_transient` when `is_multisite()`, and delete `ai_client_*` model caches (enumerate both provider directory classes as `src/Settings/Settings.php:106-112` does, or wildcard `DELETE ... LIKE '_transient_ai_client_%_models%'`).
4. **P2 — `src/Settings/Settings.php:118` after `delete_transient`** — add `if (is_multisite()) delete_site_transient($full_key);` (C-MS-03/C-CA-04).
5. **P3 — `uninstall.php:1`** — add `declare(strict_types=1);` on line 2 for consistency.
6. **P3 — `duoport-connect-for-opencode.php:59,66`** — optionally add `// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log -- WP_DEBUG gated` to silence the 2 WPCS warnings (cosmetic).
7. **P3 — Optional headers** — consider adding `Plugin URI` / `Author URI` to `duoport-connect-for-opencode.php:1-16` if a project homepage exists.

---

## 16. Verification Commands Executed

```bash
php -v                               # PHP 8.3.33
./vendor/bin/phpcs --version         # PHP_CodeSniffer 3.13.6
php -l duoport-connect-for-opencode.php  # No syntax errors
./vendor/bin/phpcs                   # 0 errors, 2 warnings (with phpcs.xml)
./vendor/bin/phpcs --standard=WordPress --extensions=php ./duoport-connect-for-opencode.php ./src ./uninstall.php  # 30 errors, 2 warnings (without phpcs.xml)
./vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.2  --extensions=php ./duoport-connect-for-opencode.php ./src ./uninstall.php  # 0 errors (vendor excluded)
./vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.3- --extensions=php ./duoport-connect-for-opencode.php ./src ./uninstall.php  # 0 errors
./vendor/bin/phpunit --testdox       # 7 tests, 23 assertions, OK
grep -rn "readonly|declare|enum" src/ duoport-connect-for-opencode.php  # readonly at Availability:46, declare strict_types in all src + bootstrap
grep -rn "is_multisite|get_site|multisite|Network" . --include="*.php"  # 0 hits in product code
grep -rn "htaccess|nginx|litespeed|apache" . --include="*.php"  # 0 hits
ls -la languages/                    # No such file or directory
ls -la assets/images/opencode.svg    # exists, 503 bytes
```

---

## 17. References

- Plugin header: `duoport-connect-for-opencode.php:1-16`
- Readme: `readme.txt:1-76`
- Composer: `composer.json:1-35` (`license:5`, `testVersion:11`, `compat:33`)
- PHPCS config: `phpcs.xml:1-21` (`WordPress:3`, `exclude-pattern vendor:5`, `FileName exclude src:14-15`, `ValidFunctionName exclude src:18-19`)
- Uninstall: `uninstall.php:1-14`
- Availability cache: `src/Availability/OpenCodeProviderAvailability.php:46,64-68,70-71,105`
- Model cache bust: `src/Settings/Settings.php:105-126`
- Provider version gates: `src/Providers/AbstractOpenCodeProvider.php:108-123`
- Other agents: `AUDIT/AGENTS/agent-php-correctness.md` (F-UN-01, F-ST-05, F-DC-04 cross-referenced)

---

*End of report — Compatibility & Coding Standards Audit — no production code modified.*
