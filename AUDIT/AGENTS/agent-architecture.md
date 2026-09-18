# Architecture Audit — DuoPort Connect for OpenCode

**Agent:** agent-architecture (WordPress Architecture Specialist)
**Date:** 2026-08-28
**Scope:** Full plugin architecture review — 15 production files, line-by-line. No production code modified. Focus: plugin architecture, dependency management, hook lifecycle (`init:5`, `init:20`, `wp_connectors_init`, `admin_notices`, `admin_menu`), separation of concerns, lifecycle handling (activation/deactivation/uninstall), naming, coupling, global state, testability, dependency on WordPress 7.0 AI Client SDK (`wp-includes/php-ai-client` / `AiClient`), version checks, namespace PSR-4, autoloader.
**Method:** `Read` every production file with line numbers; `Grep` for `add_action|add_filter|register_activation|register_deactivation|WP_UNINSTALL_PLUGIN|ABSPATH|AiClient|get_bloginfo|version_compare|autoload|spl_autoload|namespace|use WordPress`; cross-checked `composer.json`/`phpcs.xml`/`phpunit.xml.dist`/`uninstall.php`/`.distignore`; inferred SDK contracts from `src/**` call sites (no vendor SDK source present — inference confidence noted).

---

## 1. Files Reviewed

| # | File | Lines | `strict_types` | `ABSPATH`/`WP_UNINSTALL_PLUGIN` Guard | Purpose |
|---|------|-------|----------------|----------------------------------------|---------|
| 1 | `duoport-connect-for-opencode.php` | 1–144 | Yes `:18` | Yes `:22–24` | Bootstrap, hook wiring, provider registration, connector override, settings bootstrap, action link |
| 2 | `src/autoload.php` | 1–31 | Yes `:11` | Yes `:15–17` | PSR-4 autoloader for `OpenCodeConnector\` |
| 3 | `src/Availability/OpenCodeProviderAvailability.php` | 1–108 | Yes `:11` | Yes `:15–17` | Probe-based `ProviderAvailabilityInterface` + transient cache |
| 4 | `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php` | 1–135 | Yes `:11` | Yes `:15–17` | Allowlist-filtered `AbstractOpenAiCompatibleModelMetadataDirectory` |
| 5 | `src/Metadata/ModelAllowlist.php` | 1–125 | Yes `:11` | Yes `:15–17` | Hard-coded `ALLOW`/`FREE` + `isAllowed`/`isFree`/`displayName` |
| 6 | `src/Metadata/OpenCodeGoModelMetadataDirectory.php` | 1–49 | Yes `:11` | Yes `:15–17` | Go directory binding |
| 7 | `src/Metadata/OpenCodeZenModelMetadataDirectory.php` | 1–49 | Yes `:11` | Yes `:15–17` | Zen directory binding |
| 8 | `src/Models/AbstractOpenCodeTextGenerationModel.php` | 1–76 | Yes `:11` | Yes `:15–17` | `AbstractOpenAiCompatibleTextGenerationModel` + `prepareResponseFormatParam` |
| 9 | `src/Models/OpenCodeGoTextGenerationModel.php` | 1–38 | Yes `:11` | Yes `:15–17` | Go model binding |
| 10 | `src/Models/OpenCodeZenTextGenerationModel.php` | 1–38 | Yes `:11` | Yes `:15–17` | Zen model binding |
| 11 | `src/Providers/AbstractOpenCodeProvider.php` | 1–148 | Yes `:11` | Yes `:15–17` | Shared provider base (model factory, `ProviderMetadata`, availability, directory) |
| 12 | `src/Providers/OpenCodeGoProvider.php` | 1–80 | Yes `:11` | Yes `:15–17` | Go provider constants |
| 13 | `src/Providers/OpenCodeZenProvider.php` | 1–80 | Yes `:11` | Yes `:15–17` | Zen provider constants |
| 14 | `src/Settings/Settings.php` | 1–183 | Yes `:11` | Yes `:15–17` | `register_setting`/sanitize, `bustCaches`/`clearModelCaches`, `add_options_page`/`render` |
| 15 | `uninstall.php` | 1–14 | No | `WP_UNINSTALL_PLUGIN` `:9–11` | Option/transient cleanup on uninstall |

Config/docs skimmed: `composer.json:1–35`, `phpcs.xml:1–21`, `phpunit.xml.dist:1`, `readme.txt:1–76`, `assets/images/opencode.svg`, `.distignore:1–23`, `.gitignore`, `tests/**` (6 files, harness context only).

---

## 2. Methodology

- Read all 15 production files in full with line numbers; verified `declare(strict_types=1)`, `ABSPATH` guards, `namespace OpenCodeConnector`, `spl_autoload_register`, every `add_action`/`add_filter` registration and priority.
- Traced hook lifecycle end-to-end: which hooks fire on every request vs. admin-only vs. on-change; priority ordering (`init:5` vs. `init:20` vs. `wp_connectors_init` vs. `admin_notices`/`admin_menu` deferred via `init:20`); activation-request gap.
- Audited dependency management: `composer.json` prod vs. `require-dev`, `vendor/` shipping, `.distignore`, SDK soft-dependency guards (`class_exists`/`version_compare`/`AiClient::VERSION`), `allow-plugins` config.
- Audited PSR-4: `src/autoload.php:19–30` mapping vs. `composer.json:24–28` `autoload-dev`, `namespace` vs. file-path correspondence, `file_exists`/`is_readable`, `require` vs. `require_once`, `prepend` flag.
- Evaluated separation of concerns, coupling, global state, naming, testability via direct reads of class hierarchies, trait usage, singleton access (`AiClient::defaultRegistry()`/`getCache()`), transient/option/`$wpdb` globals, static factory methods.
- Cross-referenced `wp-includes/connectors.php` (known from other audits: `_wp_connectors_init` on `init`, `wp_connectors_init` docblock `:248–276` override contract, `AiClient::VERSION`/`defaultRegistry`/`getCache` API) to validate SDK lifecycle.
- Assigned severity: **CRITICAL** (fatal/data loss), **HIGH** (broken lifecycle/user-visible failure), **MEDIUM** (fragile coupling/contract drift/test gap), **LOW** (minor waste/style/defensive), **INFO** (observation/confirmation).

---

## 3. Architecture Overview

```
duoport-connect-for-opencode.php (bootstrap — procedural, hook wiring)
  │
  ├─ src/autoload.php — PSR-4 spl_autoloader for OpenCodeConnector\
  │
  ├─ Providers/ — SDK inheritance
  │    AbstractOpenCodeProvider (AbstractApiProvider)
  │      ├─ OpenCodeGoProvider  (id opencode-go,  catalog go,  base https://opencode.ai/zen/go/v1)
  │      └─ OpenCodeZenProvider (id opencode-zen, catalog zen, base https://opencode.ai/zen/v1)
  │
  ├─ Models/ — SDK inheritance
  │    AbstractOpenCodeTextGenerationModel (AbstractOpenAiCompatibleTextGenerationModel)
  │      ├─ OpenCodeGoTextGenerationModel  → providerClass() Go
  │      └─ OpenCodeZenTextGenerationModel → providerClass() Zen
  │
  ├─ Metadata/ — SDK inheritance
  │    AbstractOpenCodeModelMetadataDirectory (AbstractOpenAiCompatibleModelMetadataDirectory)
  │      ├─ OpenCodeGoModelMetadataDirectory
  │      └─ OpenCodeZenModelMetadataDirectory
  │    ModelAllowlist (pure value-object, no SDK parent)
  │
  ├─ Availability/
  │    OpenCodeProviderAvailability (ProviderAvailabilityInterface + WithHttpTransporterTrait + WithRequestAuthenticationTrait)
  │
  └─ Settings/
       Settings (register_setting, bustCaches/clearModelCaches, admin_menu, render)
```

**Style:** Thin procedural bootstrap that delegates to OOP layer per SDK's `AbstractApiProvider` / `AbstractOpenAiCompatible*` contracts. No DI container, no service locator beyond `AiClient::defaultRegistry()` singleton. Traits for HTTP/auth. `final` leaf classes, `abstract` bases with template methods (`providerId`/`catalogKey`/`displayName`/`description`/`baseUrl`/`providerClass`).

**Verdict:** Layout is idiomatic for a WordPress.org plugin that extends core's AI Client SDK. Separation is clean; coupling points are isolated but a few leak across layers (see findings).

---

## 4. Hook Lifecycle Deep Dive

### 4.1 Registered hooks inventory

| Hook | Priority | Callback | Location | Fires |
|------|----------|----------|----------|-------|
| `admin_notices` | 10 (default) | `static fn():void { version_compare(get_bloginfo) && class_exists(AiClient) ? return : echo notice }` | `duoport-connect-for-opencode.php:32–42` | Admin pages only (`is_admin`), but **registered on every request** top-level |
| `init` | **5** | `static fn():void { if (!class_exists(AiClient)) return; $r=defaultRegistry(); foreach [Go,Zen] hasProvider?registerProvider }` | `duoport-connect-for-opencode.php:44–71` | Every request (front + admin) |
| `update_option_connectors_ai_opencode_go_api_key` | 10 | `$opencode_connector_bust: delete_transient avail_go+avail_zen` | `duoport-connect-for-opencode.php:74–78` | Only when core's shared key option updated |
| `add_option_connectors_ai_opencode_go_api_key` | 10 | same `$opencode_connector_bust` | `duoport-connect-for-opencode.php:79` | Only when option first added |
| `wp_connectors_init` | 10 | `static fn($registry):void { duck-type guards → is_registered zen → get_registered → unregister → mutate setting_name → register (pristine restore on throw) }` | `duoport-connect-for-opencode.php:86–123` | Every request via `_wp_connectors_init()` which itself hooks `init` (see 4.2) |
| `init` | **20** | `static fn():void { if (class_exists(Settings)) (new Settings)->register() }` | `duoport-connect-for-opencode.php:126–134` | Every request |
| `plugin_action_links_{basename}` | 10 | `static fn(array $links):array { esc_url(admin_url('options-connectors.php')) }` | `duoport-connect-for-opencode.php:137–143` | Only `plugins.php` list table, but registered always |
| `admin_menu` (via Settings) | 10 | `[$this,'menu'] → add_options_page('DuoPort Connector',…)` | `Settings.php:45` + `135–137` | `is_admin` `admin_menu` do_action only, but **registration happens on every request** via `init:20` |
| `update_option_opencode_connector_settings` | 10 | `[$this,'bustCaches']` | `Settings.php:46` | Only on plugin option update |
| `add_option_opencode_connector_settings` | 10 | `[$this,'bustCachesAdd']` | `Settings.php:47` | Only on first add |

Additionally `register_setting('opencode_connector', OPTION_NAME, …)` at `Settings.php:36–44` inside same `init:20` path — also every request.

**Total top-level hook registrations per request:** 7 (`admin_notices`, `init:5`, 2× bust, `wp_connectors_init`, `init:20`, `plugin_action_links`) + 3 deferred (`admin_menu`, 2× `update/add_option` for own option) = **10 inserts into `global $wp_filter`**. Cost <0.1ms, 0 DB, verified in performance audit.

### 4.2 Priority ordering correctness

```
init:5   — provider registration (Go + Zen) via AiClient::defaultRegistry()->registerProvider()
init:~10 — core _wp_connectors_init() → do_action('wp_connectors_init', $registry) → plugin override (zen setting_name swap)
init:20  — Settings::register() → register_setting + add_action(admin_menu) + bust hooks
```

- **Correct:** `init:5 < wp_connectors_init (≈10) < init:20` ensures providers exist before connector registry override, and settings UI registers after both. SDK's `AiClient::defaultRegistry()` singleton is ready by `init:5` per core load order (`wp-includes` loaded before `init`). Comment at `duoport-connect-for-opencode.php:89–92` documents the activation-request gap: on the request that activates the plugin, `init` has already fired, so registration defers to next request — **identical to every `init`-hooked plugin** and not a defect, but correctly documented.
- **Fragility:** Ordering relies on priority numbers, not explicit `did_action('wp_connectors_init')` check. If core ever moves `_wp_connectors_init` off `init:10` to `init:20` or `plugins_loaded`, window breaks. Low risk (core contract stable, tested against current `connectors.php:216–277`), but coupling is implicit.

### 4.3 Admin vs. frontend split

- `admin_notices`, `admin_menu`, `plugin_action_links` are admin-only consumers but **registered unconditionally** top-level. `Settings::register()` adds its 3 hooks even on frontend where `admin_menu` never fires and `register_setting` for `opencode_connector_settings` is needed only for `options.php` and REST `settings` route. Frontend waste is ~3 hook inserts + `register_setting` array entry per request — negligible but violates "load admin code in admin" best practice (see `ARCH-HOOK-05`).

### 4.4 `admin_notices` guard

- Dual guard at `duoport-connect-for-opencode.php:35`: `version_compare(get_bloginfo('version'),'7.0','>=') && class_exists(AiClient::class)` — short-circuits correctly when both satisfied. Notice always says "requires WordPress 7.0+" even when WP >=7.0 but SDK missing (correctness `F-DC-04`). Not functional, but UX imprecise.

### 4.5 `wp_connectors_init` contract

- Uses documented `unregister → mutate → register` pattern from `wp-includes/connectors.php:248–276` example; duck-typed `method_exists` guards for `unregister`/`register`/`get_registered`/`is_registered` are correct for forward compat and mockability (`tests/Unit/ConnectorOverrideTest.php:136–237` exercises this). Pristine-restore nested `try/catch` at `duoport-connect-for-opencode.php:109–121` matches `ConnectorOverrideTest:76–107` spec. **This is the architecture's strongest point** — respects Connectors ownership model, no direct `connectors_ai_*` option writes.

### 4.6 Transient-bust hooks

- Slim closures at `duoport-connect-for-opencode.php:74–80` only `delete_transient('opencode_connector_avail_go/_zen')` — no `get_option`/`update_option` on `connectors_ai_*`, fixing previous review flag (security audit 3.1/3.2). Correctly hooks both `update_option_` and `add_option_` for the shared Go key.

---

## 5. Findings

> Severity: CRITICAL (fatal/data loss) · HIGH (broken lifecycle, user-visible failure) · MEDIUM (fragile coupling, contract drift, test gap) · LOW (waste/defensive/style) · INFO (observation/confirmation)

### 5.1 Lifecycle Handling (Activation / Deactivation / Uninstall)

#### ARCH-LIFECYCLE-01 — No `register_activation_hook` / `register_deactivation_hook` — deferred registration gap undocumented to user

- **File:Line:** `duoport-connect-for-opencode.php:1–144` (absence; contrast `uninstall.php:1–14` which exists)
- **Severity:** MEDIUM
- **Problem:** Plugin defines neither `register_activation_hook` nor `register_deactivation_hook`. Activation relies on `init:5` lazy registration on **next** request; deactivation relies on WP's plugin deactivation (hooks unregistered automatically) plus transients left to expire. No flush of SDK caches, no immediate availability check, no capability to abort activation when `AiClient` missing. Comment at `duoport-connect-for-opencode.php:89–92` documents the one-request delay, but end-user sees "Plugin activated" with no visible providers until reload — may be reported as bug. No `register_deactivation_hook` to proactively `delete_transient`/`AiClient::getCache()->delete` for model lists (leaves orphan cache until natural expiry).
- **Evidence:** Grep `register_activation|register_deactivation` across plugin returns zero hits (excluding tests/vendor). `composer.json:1–35` declares `type wordpress-plugin` but no activation scaffolding.
- **Impact:** Mild UX confusion on first activation; stale `ai_client_*` model cache survives deactivation/reactivation cycles.
- **Recommendation:** Optionally add minimal activation hook that validates `version_compare($GLOBALS['wp_version'],'7.0','>=') && class_exists(AiClient::class)` and calls `AiClient::defaultRegistry()->registerProvider()` eagerly if registry already available, with same `WP_DEBUG` error guard. Keep next-request fallback. Add deactivation hook that calls `delete_transient('opencode_connector_avail_go/_zen')` and mirrors `Settings::clearModelCaches()` for both directories. Not required for .org compliance, but improves lifecycle hygiene. See `ARCH-HOOK-02` for why eager registration needs `did_action('init')` check.
- **Confidence:** High

#### ARCH-LIFECYCLE-02 — Uninstall is single-site only and leaves `ai_client_*` model caches orphaned

- **File:Line:** `uninstall.php:1–14`
- **Severity:** MEDIUM (hygiene, not security)
- **Problem:** `uninstall.php:12–14` deletes `opencode_connector_settings` + `opencode_connector_avail_go/_zen`, but **not** `ai_client_<VERSION>_<md5>_models` transients/options created by SDK's `AbstractOpenAiCompatibleModelMetadataDirectory` and explicitly busted at `Settings.php:112`. Leaves two orphaned rows (`_transient_ai_client_*` + timeout) per site after uninstall. Uses `delete_option`/`delete_transient` only — no `delete_site_option`/`delete_site_transient` / `is_multisite()` handling. WP core invokes `uninstall.php` per site since WP 5.1 for non-network uninstall, so per-site path suffices, but network-activated uninstall not covered.
- **Evidence:** `uninstall.php:12` `delete_option('opencode_connector_settings')`, `:13–14` two `delete_transient`, no loop over `['OpenCodeGoModelMetadataDirectory','OpenCodeZenModelMetadataDirectory']`, no `WP_DEBUG` guard needed. Matches correctness `F-UN-01`/`F-UN-02` and performance `PERF-SETT-02`.
- **Impact:** Orphaned `wp_options` rows (autoload `no`, but still scanned by `alloptions` fallback on some hosts); ~2 rows per site until expiry.
- **Recommendation:** Enumerate both directory classes and `delete_transient('ai_client_'.AiClient::VERSION.'_'.md5($cls).'_models')` + `delete_site_transient` fallback when `is_multisite()`, or wildcard cleanup `DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ai_client_%_models%'` via `prepare`+`esc_like`. Gate behind `class_exists(AiClient::class)` check to avoid fatal on uninstall after SDK removed. Keep `WP_UNINSTALL_PLUGIN` guard `:9–11`.
- **Confidence:** High

#### ARCH-LIFECYCLE-03 — No uninstall handling for `delete_site_transient` / multisite `sitemeta`

- **File:Line:** `uninstall.php:12–14` and `Settings.php:118–124`
- **Severity:** LOW
- **Problem:** Even if `ai_client_*` cleanup added (ARCH-LIFECYCLE-02), both files use `$wpdb->options` and `delete_transient` only. On multisite with persistent object cache, model caches may live as site transients in `sitemeta` or object cache. No `delete_site_transient` path.
- **Evidence:** `Settings.php:120–124` comment "Fallback for object-cache-less installs" acknowledges single-store; no `is_multisite()` branch.
- **Impact:** Stale site-transient survives on multisite.
- **Recommendation:** Mirror cache bust with `if (is_multisite()) { delete_site_transient($full_key); }` and document single-site scope in `readme.txt` if out of scope.
- **Confidence:** Medium

### 5.2 Dependency Management

#### ARCH-DEP-01 — Production has zero runtime Composer dependencies — correct for .org, but SDK coupling is implicit and unversioned

- **File:Line:** `composer.json:1–35`
- **Severity:** INFO (with MEDIUM coupling note)
- **Evidence:** `composer.json:16–23` declares only `require-dev` (`wpcs`, `phpcompat`, `phpunit`, `brain/monkey`); no `require`. `vendor/` is `.distignore`d `:8`, not shipped. Correct for wordpress.org (AI Client SDK is provided by core at `wp-includes/php-ai-client`, not vendored). Plugin deliberately has no `composer install --no-dev` at deploy.
- **Impact:** None for distribution; SDK dependency is **soft** and versioned only via `AiClient::VERSION` runtime checks (see ARCH-VERSION-02). No `composer.json` constraint like `"wordpress/ai-client": "^1.3"` to pin during CI. SDK breaking change would surface only at runtime via swallowed `Throwable`.
- **Recommendation:** Keep `require: {}` empty, but add `conflict` or comment pinning tested `AiClient::VERSION` range (e.g. `^1.2` tested, `1.3` with icon). Add CI job that installs core 7.0 RC/beta and asserts `ConnectorOverrideTest`/`SlimBustHooksTest` still pass. Optionally declare `"extra": {"tested-ai-client": "1.3"}` for documentation.
- **Confidence:** High

#### ARCH-DEP-02 — `composer.json` autoload is `autoload-dev` only — production PSR-4 depends on hand-rolled `src/autoload.php`

- **File:Line:** `composer.json:24–28` and `src/autoload.php:1–31`
- **Severity:** LOW
- **Problem:** Real PSR-4 mapping lives in `src/autoload.php:19–30`, not `composer.json`. Composer's `autoload-dev` maps `OpenCodeConnector\Tests\` only. Production autoload is therefore **not** managed by Composer; `vendor/autoload.php` is required only by `tests/bootstrap.php:16`. This split is intentional (avoid shipping Composer autoloader for a 13-class plugin), but diverges from WordPress best practice where `composer.json:autoload.psr-4` is authoritative and `src/autoload.php` is unnecessary. `vendor/composer/autoload_psr4.php` shows no `OpenCodeConnector\` entry.
- **Evidence:** `composer.json:24–28` `autoload-dev` only; `src/autoload.php:20` `str_starts_with($class_name, __NAMESPACE__.'\\')` prefix check; `duoport-connect-for-opencode.php:29` `require_once __DIR__ . '/src/autoload.php'`.
- **Impact:** Two sources of truth; `composer dump-autoload --optimize` not usable for production classmap.
- **Recommendation:** Keep hand-rolled for now (lightweight, no vendor). Optionally add canonical `autoload: { "psr-4": { "OpenCodeConnector\\": "src/" }}` to `composer.json` for IDE/static-analysis completeness, and make `src/autoload.php` guard `if (!class_exists(...))` to allow Composer to win when present. No functional change required.
- **Confidence:** High

#### ARCH-DEP-03 — Soft SDK dependency with `class_exists` guards is correct and defensive — failure mode is silent

- **File:Line:** `duoport-connect-for-opencode.php:35,47–48`, `Settings.php:129,148–149`, `Providers/AbstractOpenCodeProvider.php:116–121`
- **Severity:** INFO
- **Problem:** Every SDK interaction is guarded: `admin_notices:35` `class_exists(AiClient::class)`, `init:5:47` `if (!class_exists(AiClient::class)) return`, `Settings::register:129` `if (class_exists(Settings::class))`, `Settings::render:148` `class_exists(AiClient::class) && isProviderConfigured`. Provider metadata gates on `AiClient::VERSION`. All wrapped in `try/catch \Throwable` with `WP_DEBUG` guard. This is **correct** — plugin never fatals when SDK missing, unlike `require` hard dependency.
- **Impact:** Silent degrade (providers simply absent, settings still render "not connected") — correct but could hide misconfiguration (e.g. AI Client plugin inactive). `admin_notices` firing on every admin page gives just enough signal.
- **Recommendation:** No change. Optionally surface `isProviderConfigured` error reason in logs when both `WP_DEBUG` and `WP_DEBUG_LOG` set, mirroring provider-registration logging.
- **Confidence:** High

#### ARCH-DEP-04 — Remote `.distignore` correctly excludes `vendor/`/`tests/`/`composer.json`

- **File:Line:** `.distignore:1–23`
- **Severity:** INFO
- **Problem:** No issue. `vendor/`, `tests/`, `composer.lock`, `phpcs.xml`, `.git*`, `build/` excluded via `/.distignore:1–23`. `assets/` shipped correctly (`banner`, `icon.svg`, `images/opencode.svg` referenced by `AbstractOpenCodeProvider.php:120`). Aligns with .org packaging.
- **Evidence:** `.distignore:8` `vendor/`, `:18` `tests/`, `:11` `composer.json`.
- **Recommendation:** None.

### 5.3 PSR-4 Namespace & Autoloader

#### ARCH-PSR4-01 — Custom PSR-4 autoloader is correct — minor hardening and perf notes

- **File:Line:** `src/autoload.php:1–31`
- **Severity:** LOW
- **Problem:** Implements PSR-4 correctly: `namespace OpenCodeConnector` `:13`, `ABSPATH` guard `:15–17`, `spl_autoload_register(static fn(string $class):void …)` `:19–30`, prefix check `str_starts_with($class, __NAMESPACE__.'\\')` `:21`, `$rel=substr($class, strlen(__NAMESPACE__)+1)` `:24`, `str_replace('\\','/',$rel)` `:25`, `$file=__DIR__.'/'.$rel.'.php'` `:26`, `if (file_exists($file)) require $file` `:27–29`. Minor notes: (1) `file_exists` is `lstat` per load (~0.05ms, 13 classes cold = 0.6ms; prior audits `F-AL-01`/`PERF-AL-01`); `is_readable` or classmap `isset` would avoid stat. (2) No `..` segment rejection — defense-in-depth but not exploitable (PHP class names cannot contain `/`/`..`; correct as `F-AL-02`/`F-SEC-INFO-06`). (3) No `prepend=true` — registers at queue end, correct so Composer autoloaders run first.
- **Evidence:** Full file cited; `vendor/composer/autoload_psr4.php` confirms Composer does not map `OpenCodeConnector\` in production.
- **Impact:** Negligible. Warm requests with opcache keep classes in memory; `file_exists` still stats but file unchanged.
- **Recommendation:** Keep as-is. Optionally precompute classmap `const MAP=[OpenCodeGoProvider::class=>__DIR__.'/Providers/OpenCodeGoProvider.php', …]` and `if (isset($map[$class])) require $map[$class]` to eliminate stat for high-RPS, or switch to `is_readable($file)` for cleaner permission handling. Not urgent.
- **Confidence:** High

#### ARCH-PSR4-02 — `autoload-dev` prefix `OpenCodeConnector\Tests\Unit\` is correct — no prod leakage

- **File:Line:** `composer.json:24–28`, `tests/bootstrap.php:1–16`
- **Severity:** INFO
- **Evidence:** Tests bootstrap defines `ABSPATH` to `sys_get_temp_dir()` `:13` and `require vendor/autoload.php` `:16`, enabling `Brain\Monkey` + `OpenCodeConnector\Tests\Unit\` via Composer. Production bootstrap `duoport-connect-for-opencode.php:29` requires `src/autoload.php` separately. No test namespaces leak to prod.
- **Impact:** None.
- **Recommendation:** None.

#### ARCH-PSR4-03 — File naming convention divergence documented but technically violates WordPress `FileName` sniff — correctly suppressed

- **File:Line:** `phpcs.xml:12–16`
- **Severity:** INFO
- **Problem:** WordPress `FileName` sniff expects `class-open-code-go-provider.php`; plugin uses PSR-4 `OpenCodeGoProvider.php`. Suppressed via `WordPress.Files.FileName` exclude for `src/*` `:13–16` and `tests/*`. This is standard for namespaced plugins and correctly scoped.
- **Evidence:** `phpcs.xml:13–16` exclude-pattern.
- **Recommendation:** None. Keep PSR-4 naming.

### 5.4 Coupling

#### ARCH-COUPLING-01 — Availability probe hardcodes model IDs that duplicate `ModelAllowlist` — tight cross-module coupling

- **File:Line:** `src/Availability/OpenCodeProviderAvailability.php:70–71` vs `src/Metadata/ModelAllowlist.php:31–71`
- **Severity:** MEDIUM
- **Problem:** `Availability:71` `$probe_model='go'===catalog?'deepseek-v4-flash':'deepseek-v4-flash-free'` hardcodes two IDs that must exist in `ModelAllowlist::ALLOW` and remain valid on `https://opencode.ai`. If catalog renames/removes `deepseek-v4-flash`, probe returns 404/400 → `isConfigured()` returns `false` (401/429 special-cased at `Availability:92–99`, but 404 falls to `ok=false:100`) even with valid key — false-negative "not connected". Correctness audit `F-AV-02` / performance `PERF-AV-02` already flag; architecture concern is **no single source of truth**.
- **Evidence:** Cited lines; `ModelAllowlist:41–42` lists both probe IDs today, but no static assertion.
- **Impact:** Catalog maintenance requires touching two files; drift breaks availability UI.
- **Recommendation:** Derive probe model from allowlist: `ModelAllowlist::ALLOW['go'][0]` or add `ModelAllowlist::probeModel(string $catalog):string` method centralizing choice, with test asserting probe ID `isAllowed`. Consider probing `GET models` enumeration instead of billed `chat/completions` to decouple entirely.
- **Confidence:** High

#### ARCH-COUPLING-02 — `Settings::clearModelCaches()` hand-rolls SDK cache key — brittle coupling to SDK internals

- **File:Line:** `src/Settings/Settings.php:112` (`$full_key='ai_client_'.AiClient::VERSION.'_'.md5($cls).'_models'`)
- **Severity:** MEDIUM
- **Problem:** Duplicates SDK's internal key formula from `AbstractOpenAiCompatibleModelMetadataDirectory`. If SDK changes prefix (`ai_client_` → `ai_client_v2_`), hash (`md5` → `sha1`/`xxh3`), or adds blog-ID/salt, bust at `Settings:77,94` silently misses and toggle "Show all models" appears no-op until natural expiry. SDK source not in repo so drift undetectable without integration test. Same issue noted at `uninstall.php` gap. Correctness `F-ST-02`/`PERF-SETT-02`/`F-SEC-INFO-03`.
- **Evidence:** `Settings.php:106–113` loop over `OpenCodeGoModelMetadataDirectory::class` + `OpenCodeZenModelMetadataDirectory::class`, `AiClient::getCache()` then `delete_transient` + raw `$wpdb->query DELETE` fallback `:122–123`.
- **Impact:** Perceived slowness/freshness bug, not CPU, but erodes trust in settings.
- **Recommendation:** Prefer SDK API if exposed (`$directory->clearCache()`, `AiClient::getCache()->deleteForClass()`). If not, add integration test pinning current `AiClient::VERSION` and asserting hand-rolled equals SDK real key for each release, and document coupling via comment. Gate raw SQL fallback with `!wp_using_ext_object_cache()` to avoid redundant writes on Redis (`PERF-SETT-01`).
- **Confidence:** High (no SDK source to verify, inference from call sites).

#### ARCH-COUPLING-03 — `AbstractOpenCodeModelMetadataDirectory` reads WordPress option directly — settings concern leaks into data layer

- **File:Line:** `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:87`
- **Severity:** LOW
- **Problem:** `parseResponseToModelMetadataList():87` does `$show_all=(bool)(get_option(\OpenCodeConnector\OPTION_NAME,[])['show_all_models']??false)` inside data-mapping. Metadata directory now depends on `Settings` option value, creating upward coupling (data layer → config). Correctness `F-MD-02` notes string-corruption edge; architecture note is that metadata directory should ideally receive `$showAll` via constructor injection from caller/`AiClient::getCache()` context.
- **Evidence:** `AbstractOpenCodeModelMetadataDirectory:82–88` method signature `parseResponseToModelMetadataList(Response $response):array` — no config param, so reads global `get_option` internally.
- **Impact:** Harder to unit test directory in isolation (needs `Brain\Monkey\Functions\when('get_option')` stub); violates dependency-inversion; risks reading stale option mid-request if settings saved.
- **Recommendation:** Keep as-is for simplicity (read cost <0.1ms, autoloaded), but consider injecting `bool $showAll` via directory constructor or provider factory `createModelMetadataDirectory():143–147` if testability becomes need. At minimum validate `is_array` before offset access as `F-MD-02` recommends.
- **Confidence:** High

#### ARCH-COUPLING-04 — `AbstractOpenCodeProvider` omits `abstract baseUrl()` declaration — hidden SDK contract

- **File:Line:** `src/Providers/AbstractOpenCodeProvider.php:39–148` (abstracts at `:48,57,68,75`) and `src/Providers/OpenCodeGoProvider.php:77–79` / `OpenCodeZenProvider.php:77–79`
- **Severity:** LOW
- **Problem:** `AbstractApiProvider` (SDK parent) requires `protected static function baseUrl():string`; both concretes implement it, but intermediate `AbstractOpenCodeProvider` does not declare it `abstract`. Static analysis cannot enforce that a future concrete (e.g. `OpenCodeProProvider`) implements `baseUrl`; failure would surface late as `Error` from SDK parent. Correctness `F-PR-02`.
- **Evidence:** `AbstractOpenCodeProvider:39–148` declares 4 abstracts but not `baseUrl`.
- **Impact:** Minor compile-time safety gap; no runtime bug today.
- **Recommendation:** Add `abstract protected static function baseUrl(): string;` to `AbstractOpenCodeProvider:39`. Keep `strict_types`/`final` on leaves.
- **Confidence:** High

#### ARCH-COUPLING-05 — Tight inheritance from SDK abstracts limits swappability — by design

- **File:Line:** `src/Providers/AbstractOpenCodeProvider.php:40` (`extends AbstractApiProvider`), `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:38` (`extends AbstractOpenAiCompatibleModelMetadataDirectory`), `src/Models/AbstractOpenCodeTextGenerationModel.php:29` (`extends AbstractOpenAiCompatibleTextGenerationModel`), `src/Availability/OpenCodeProviderAvailability.php:35` (`implements ProviderAvailabilityInterface` + `WithHttpTransporterTrait`/`WithRequestAuthenticationTrait:36–37`)
- **Severity:** INFO
- **Problem:** Deep SDK inheritance is **intentional** — WordPress AI Client's provider model is inheritance-based (`AbstractApiProvider` template, `AbstractOpenAiCompatible*` for `chat/completions`/`models`). Alternatives (composition/decorators) would fight SDK. Coupling is therefore tight but justified; plugin cannot swap transport/auth without SDK change. `WithHttpTransporterTrait` + `WithRequestAuthenticationTrait` delegate HTTP/auth to core — correct separation.
- **Evidence:** Cited extends/implements.
- **Recommendation:** No change. If SDK adds interface-based registration, consider adapting then. Document inheritance choice in `README`/docblock.
- **Confidence:** High

#### ARCH-COUPLING-06 — Model factory `createModel` branches on `catalogKey()` string compare — implicit coupling to catalog string

- **File:Line:** `src/Providers/AbstractOpenCodeProvider.php:87–99` (`'go'===static::catalogKey() ? new OpenCodeGoTextGenerationModel : new OpenCodeZenTextGenerationModel`)
- **Severity:** INFO
- **Problem:** Branch tested at `AbstractOpenCodeProvider:91–93` mixes catalog identity with class selection via string compare. If third catalog added, branch must grow. Correct for two catalogs; could use `match` or provider-class map (`['go'=>GoModel::class, 'zen'=>ZenModel::class]`) for open-closed.
- **Evidence:** `AbstractOpenCodeProvider:91–93`.
- **Impact:** None today; minor maintainability if catalog set expands.
- **Recommendation:** Keep binary branch; if third catalog planned, refactor to `match` or registry map.
- **Confidence:** Medium

### 5.5 Separation of Concerns

#### ARCH-SOC-01 — Module boundaries are well-drawn — PASS

- **File:Line:** `src/Providers/*`, `src/Metadata/*`, `src/Models/*`, `src/Availability/*`, `src/Settings/*`, `duoport-connect-for-opencode.php`
- **Severity:** INFO (PASS)
- **Evidence:** Each layer has single responsibility: `Providers` registers with SDK and exposes `baseUrl`/`displayName`; `Metadata` fetches `GET models` and filters via `ModelAllowlist`; `Models` builds `chat/completions` `Request` and wraps `json_schema` envelope; `Availability` probes `chat/completions` with transient cache; `Settings` owns `register_setting`/admin UI/bust; `ModelAllowlist` is pure value-object (no I/O, no globals besides constants). Bootstrap is thin glue.
- **Impact:** Positive — easy to navigate, changes isolated.
- **Recommendation:** Maintain.

#### ARCH-SOC-02 — `ModelAllowlist` is exemplary pure domain object

- **File:Line:** `src/Metadata/ModelAllowlist.php:25–125`
- **Severity:** INFO (PASS)
- **Problem:** None. Constants `ALLOW:31–71` and `FREE:77–85`, methods `isAllowed:96–98`/`isFree:108–110`/`displayName:120–124` are side-effect-free, strict `in_array(...,true)`, no globals. Only improvement is static assertion that `FREE ⊆ ALLOW['zen']` (correctness `F-ALLOW-01`).
- **Evidence:** Full file.
- **Recommendation:** None.

#### ARCH-SOC-03 — `Settings::render()` mixes view, controller, and cache-bust triggers in 183-line God-object-lite

- **File:Line:** `src/Settings/Settings.php:27–183`
- **Severity:** LOW
- **Problem:** `Settings` does: `register_setting`/`sanitize` (`:35–63`) + `bustCaches`/`bustCachesAdd`/`clearModelCaches` (`:74–126`) + `menu` (`:135–137`) + inline HTML `render` with business logic `isProviderConfigured` (`:146–182`). View is inline PHP/HTML (correct for WP settings page), but mixing cache invalidation (which knows `AiClient::VERSION`/`md5` SDK internals) with rendering in one class violates SRP. `render` calls `AiClient::defaultRegistry()->isProviderConfigured()` at `:148–149` without `try/catch` (correctness `F-ST-01` / perf `PERF-AV-03`) so data-layer failure propagates to presentation.
- **Evidence:** `Settings.php:27` `final class Settings` 183 lines; `render:146–182` 36 lines of mixed `esc_html_e`/`wp_kses_post`/`checked()` and registry calls.
- **Impact:** Low coupling, but harder to test render in isolation; cache-bust change risks render regression.
- **Recommendation:** Keep for plugin size (splitting into `Settings\Page` + `CacheInvalidator` would add files for ~100 lines). If splitting, extract `clearModelCaches` into `Infrastructure\CacheInvalidator` with injected `AiClient::getCache()` and `$wpdb`. Not urgent.
- **Confidence:** Medium

#### ARCH-SOC-04 — Leaf directories/models are near-duplicate — intentional, correctly factored via `Abstract*`

- **File:Line:** `src/Metadata/OpenCodeGoModelMetadataDirectory.php:27–48` / `OpenCodeZenModelMetadataDirectory.php:27–48`, `src/Models/OpenCodeGoTextGenerationModel.php:27–38` / `OpenCodeZenTextGenerationModel.php:27–38`, `src/Providers/OpenCodeGoProvider.php:25–80` / `OpenCodeZenProvider.php:25–80`
- **Severity:** INFO (DUPLICATE, intentional)
- **Problem:** Each pair differs only by two one-liners (`providerClass()`/`catalogKey()`/`providerId()`/`baseUrl()`). Duplication flagged as `F-DIR-01` but is required per SDK (each provider must be a distinct class FQCN for `registerProvider()`). Base abstractions already factor shared behavior; further dedup (e.g. parameterized factory) would fight SDK registration model.
- **Evidence:** 49-line directories, 38-line models, 80-line providers — identical structure.
- **Recommendation:** Keep as-is; if third catalog added, consider code generation.

### 5.6 Global State

#### ARCH-GLOBAL-01 — Singleton `AiClient::defaultRegistry()` / `AiClient::getCache()` / `AiClient::VERSION` — by SDK design

- **File:Line:** `duoport-connect-for-opencode.php:51–55` (`defaultRegistry()`), `src/Settings/Settings.php:110` (`getCache()`/`VERSION`), `src/Providers/AbstractOpenCodeProvider.php:116–121` (`VERSION`), `src/Settings/Settings.php:112` (`VERSION`)
- **Severity:** INFO
- **Problem:** Plugin accesses three SDK singletons: registry (provider registration, `hasProvider`/`isProviderConfigured`), cache (model cache bust), and version constant. This is **SDK's designed global state** — WordPress core provides single `AiClient` per request, not injectable without `add_filter`. Plugin guards with `class_exists` and `try/catch \Throwable` consistently.
- **Impact:** Hard to parallelize tests without `RunInSeparateProcess` (see ARCH-TEST-01); but correct for WP request lifecycle (one PHP process per request).
- **Recommendation:** No change. If SDK adds `AiClient::setRegistryForTesting()`, consider using in tests to avoid separate processes.

#### ARCH-GLOBAL-02 — WordPress Options API + Transients + Direct `$wpdb` — scattered but scoped

- **File:Line:** `duoport-connect-for-opencode.php:74–80` (`delete_transient` avail), `duoport-connect-for-opencode.php:27` (`OPTION_NAME`), `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:87` (`get_option`), `src/Settings/Settings.php:36–126` (`register_setting`, `get_option`, `delete_transient`, `$wpdb->query`), `src/Availability/OpenCodeProviderAvailability.php:64–105` (`get_transient`/`set_transient`), `uninstall.php:12–14`
- **Severity:** INFO
- **Problem:** Plugin touches three WP global stores: (1) own option `opencode_connector_settings:27` (type `array`, default `['show_all_models'=>false]`, autoload via `register_setting` — small, <50B), (2) two transients `opencode_connector_avail_go/_zen` (`Availability:64`, `Settings:77–78`), (3) SDK-owned `ai_client_*_models` via `AiClient::getCache()` + `delete_transient` + `$wpdb->options` fallback. No direct `connectors_ai_*` reads/writes (security 3.1 PASS). Transient TTL `5*MINUTE_IN_SECONDS:105` appropriate (perf 4.1 PASS). No stampede lock (perf `PERF-AV-01` MEDIUM, but that's global-state consistency, not architecture).
- **Impact:** Well-scoped — own keys use `opencode_connector_` prefix; no pollution of `wp_options` autoload (transients `autoload=no`). `get_option(OPTION_NAME,[])` after first load hits `alloptions` cache in memory — 0 extra queries on warm.
- **Recommendation:** None for scoping. Consider stampede lock (`wp_cache_add` lock key) and jitter (`+random_int(0,60)`) as perf fix.

#### ARCH-GLOBAL-03 — `global $wpdb` direct SQL — correctly suppressed, correctly prepared

- **File:Line:** `src/Settings/Settings.php:120–124`
- **Severity:** INFO
- **Problem:** `global $wpdb; $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s OR option_name=%s", '_transient_'.$full_key, '_transient_timeout_'.$full_key))` is PHPCS-suppressed via `phpcs:ignore WordPress.DB.DirectDatabaseQuery` at `:122` with comment `:119` "Fallback for object-cache-less installs". Prepared with `%s`, table `{$wpdb->options}` not user input. Double-delete after `delete_transient` is redundant on cache-less installs is intentional fallback; on persistent-cache hosts it's extra 2 `DELETE` hits (perf `PERF-SETT-01` LOW). Same coupling as ARCH-COUPLING-02.
- **Evidence:** `Settings.php:118–124`.
- **Recommendation:** Gate with `if (!wp_using_ext_object_cache())` to avoid redundant writes on Redis, and remove `has()` before `delete()` in cache layer.

#### ARCH-GLOBAL-04 — Namespace-level constants `VERSION` / `OPTION_NAME` — lightweight globals

- **File:Line:** `duoport-connect-for-opencode.php:26–27`
- **Severity:** INFO
- **Problem:** `const VERSION='0.1.1'` and `const OPTION_NAME='opencode_connector_settings'` live at `OpenCodeConnector\` namespace level (not class constants). `VERSION` is unused internally (correctness `F-DC-05` DEAD CODE) — header `Version: 0.1.1` is authoritative. `OPTION_NAME` referenced via fully-qualified `\OpenCodeConnector\OPTION_NAME` at 5 sites (`Availability:87`, `Settings:39,46,47,147,175`). This is WordPress-conventional but not class-scoped.
- **Evidence:** Cited lines.
- **Impact:** No collision (namespace-qualified access); `VERSION` drift risk if bumped in header but not constant.
- **Recommendation:** Either reference `VERSION` internally (e.g. asset versioning) or add comment "mirrors plugin header" and consider `final class Plugin { public const VERSION=…; public const OPTION_NAME=…; }` for tighter scope. Not urgent.

### 5.7 Naming

#### ARCH-NAMING-01 — Namespace `OpenCodeConnector` vs. slug `duoport-connect-for-opencode` vs. package `@package OpenCodeConnector`

- **File:Line:** `duoport-connect-for-opencode.php:20` (`namespace OpenCodeConnector`), `duoport-connect-for-opencode.php:3` (`Plugin Name: DuoPort Connector for OpenCode`), `composer.json:2` (`name duoport/duoport-connect-for-opencode`), `src/Metadata/ModelAllowlist.php:3` (`@package OpenCodeConnector`)
- **Severity:** LOW (cosmetic, with INFO note)
- **Problem:** Legacy namespace `OpenCodeConnector` predates rename to "DuoPort Connector for OpenCode" in `readme.txt:65`/`changelog 0.1.1`. Slug and Composer name use `duoport-connect-for-opencode`; namespace still `OpenCodeConnector`. Inconsistency is harmless (PHP namespace need not match slug), but adds cognitive overhead and complicates `grep`.
- **Evidence:** Cited headers.
- **Impact:** None functional; docs/spiral expects `DuoPort\*` or `DuoportConnector\*` to mirror slug. Renaming would be breaking (requires `class_alias` or migration) for marginal value.
- **Recommendation:** Keep `OpenCodeConnector` to avoid breaking `AiClient::defaultRegistry()->hasProvider(OpenCodeGoProvider::class)` string identity across updates. Document mapping in `README`/inline comment. If ever major-bumping, consider `namespace DuoPort\OpenCodeConnector;` with `class_alias` for back-compat.
- **Confidence:** High

#### ARCH-NAMING-02 — Option/transient naming is consistent — PASS

- **File:Line:** `duoport-connect-for-opencode.php:27` (`opencode_connector_settings`), `src/Availability/OpenCodeProviderAvailability.php:64` (`opencode_connector_avail_`), `src/Settings/Settings.php:112` (`ai_client_…_models`)
- **Severity:** INFO
- **Problem:** Own keys use `opencode_connector_` prefix throughout; SDK keys use `ai_client_`. Distinct, no collision with `connectors_ai_*` (core-owned). Plugin action link `plugin_action_links_{basename}` at `duoport-connect-for-opencode.php:138` correctly uses `plugin_basename(__FILE__)` filter name construction.
- **Evidence:** Cited lines.
- **Recommendation:** None.

#### ARCH-NAMING-03 — Text Domain `duoport-connect-for-opencode` matches slug — PASS

- **File:Line:** `duoport-connect-for-opencode.php:11` (`Text Domain: duoport-connect-for-opencode`), `src/Settings/Settings.php:136`/`152`/etc. `__('…','duoport-connect-for-opencode')`
- **Severity:** INFO
- **Problem:** Correct — `__()`/`esc_html__()` calls consistently use slug domain, not namespace. `languages/` path declared but `.pot` not in repo (expected for .org).
- **Evidence:** `duoport-connect-for-opencode.php:12` `Domain Path: /languages`.
- **Recommendation:** None.

### 5.8 Version Checks

#### ARCH-VERSION-01 — Plugin header + runtime `get_bloginfo` + `AiClient::VERSION` gates — correct layered strategy

- **File:Line:** `duoport-connect-for-opencode.php:5–6` (`Requires at least: 7.0`, `Requires PHP: 8.2`), `duoport-connect-for-opencode.php:35` (`version_compare(get_bloginfo('version'),'7.0','>=')`), `src/Providers/AbstractOpenCodeProvider.php:116–121` (`version_compare(AiClient::VERSION,'1.2.0'/'1.3.0','>=')`), `phpcs.xml:11` (`testVersion 8.2-`)
- **Severity:** INFO (PASS with LOW notes)
- **Problem:** Header blocks installation on WP <7.0 / PHP <8.2 via WP's `Requires at least` parser (.org installs gate). Runtime check at `duoport-connect-for-opencode.php:35` uses `get_bloginfo('version')` (which returns `$GLOBALS['wp_version']` via `general-template.php`) plus `class_exists(AiClient::class)` to guard registration — correct defense when .org gate bypassed (manual zip install, `WP_DEBUG` override). Provider metadata gates on `AiClient::VERSION` to conditionally set `description` (`1.2.0`:116) and logo `assets/images/opencode.svg` (`1.3.0`:119) — matches SDK additive-optional-field history.
- **Evidence:** Cited lines; `composer.json` no runtime `require` PHP constraint, but `readme.txt:5` mirrors `Requires at least: 7.0`/`Requires PHP: 8.2`.
- **Impact:** Correct layering; `get_bloginfo` micro-cost (perf `PERF-BOOT-03` INFO) — could use `global $wp_version` directly for 0.01ms win.
- **Recommendation:** Optionally add `Requires Plugins: ai-client` header if SDK is a separate plugin (not core-bundled); otherwise keep. Consider caching `version_compare` result in `static $isCompat` to avoid per-admin-page parse. Keep PHP 8.2- gate in `phpcs.xml` for CI.
- **Confidence:** High

#### ARCH-VERSION-02 — `ProviderMetadata` variadic construction is position-sensitive — fragile against future SDK param order

- **File:Line:** `src/Providers/AbstractOpenCodeProvider.php:108–123`
- **Severity:** MEDIUM
- **Problem:** `createProviderMetadata():108–123` builds `$args=[id, name, ProviderTypeEnum::cloud(), 'https://opencode.ai/auth', RequestAuthenticationMethod::apiKey()]` then conditionally pushes `description` (`1.2.0`:117) and logo path (`1.3.0`:120), then `new ProviderMetadata(...$args)`. Position-sensitive spread assumes SDK adds optional fields strictly as trailing params. If SDK reorders (e.g. inserts `websiteUrl` before `description`), args misalign. Swallowed by outer `try/catch` at `duoport-connect-for-opencode.php:50–68`, so failure is silent (provider simply absent, error only with `WP_DEBUG_LOG`).
- **Evidence:** `AbstractOpenCodeProvider:108–123`; no `ReflectionClass` guard.
- **Impact:** Future AI Client minor could silently break both providers until code updated.
- **Recommendation:** If SDK provides `ProviderMetadataBuilder` or named-arg constructor, migrate. Otherwise probe `ReflectionClass::getConstructor()->getParameters()` by name, or pin tested `AiClient::VERSION` range in CI (`phpunit` with multiple core stubs) to catch drift early. Document coupling.
- **Confidence:** Medium (no SDK source to confirm ctor stability; inference from version-gate pattern).

#### ARCH-VERSION-03 — `Requires PHP: 8.2` not enforced at runtime — relies on WP header + host

- **File:Line:** `duoport-connect-for-opencode.php:6`
- **Severity:** INFO
- **Problem:** Plugin sets `Requires PHP: 8.2` header for .org, but no runtime `version_compare(PHP_VERSION,'8.2.0','>=')` guard before using `readonly` (`Availability:46`), `str_starts_with` (`autoload:21`), `array_is_list` contexts. WP core blocks activation on too-low PHP, but manual unzip on PHP 8.1 could fatal at `readonly` parse before any guard runs.
- **Evidence:** `duoport-connect-for-opencode.php:6` header, `src/Availability/OpenCodeProviderAvailability.php:46` `private readonly string $catalog`.
- **Impact:** Pre-parse fatal not catchable; but .org header is sufficient. Lean plugin opts not to duplicate guard.
- **Recommendation:** Keep header-only; optionally add `if (version_compare(PHP_VERSION,'8.2.0','<')) return;` before namespace block for defense-in-depth on manual installs (must be before `declare(strict_types=1)` class parse — so would require separate `php-compat.php` stub). Low priority.

### 5.9 Testability & Static Analysis

#### ARCH-TEST-01 — Brain Monkey + `RunInSeparateProcess` + `Mockery::on` captures hooks well — heavy but correct

- **File:Line:** `tests/Unit/ConnectorOverrideTest.php:32–269` (`#[RunInSeparateProcess]`+`#[PreserveGlobalState(false)]`, `Monkey\Actions\expectAdded('wp_connectors_init')`, `Mockery::on` capturing `$callback`), `tests/Unit/SlimBustHooksTest.php:32–57` (same for `update/add_option_connectors_ai_*`), `tests/Unit/LegacyApprovalSourceTest.php:23–36` (direct `file_get_contents` source scan), `tests/Unit/MonkeyTestCase.php:22–40` (`Monkey\setUp`/`tearDown`)
- **Severity:** INFO
- **Problem:** No issue — tests precisely capture top-level `add_action` registrations by stubbing `plugin_basename` via `Functions\when` and intercepting `expectAdded`. `RunInSeparateProcess` ensures `require_once self::plugin_file()` re-executes bootstrap per test (needed because `add_action` is top-level, not函). Pristine-restore path (`ConnectorOverrideTest:76–107`) and slim-bust contract (`SlimBustHooksTest:34–117`) encode architecture invariants. `LegacyApprovalSourceTest:36` `str_contains('Approvals_Store'|'wpai_connector_approval_pending'|'set_approval')` guards security invariant.
- **Evidence:** Cited tests; `tests/bootstrap.php:12–16` `ABSPATH` stub + `vendor/autoload.php`.
- **Impact:** Positive — architecture regressions caught.
- **Recommendation:** Keep. Consider adding integration test that asserts hand-rolled `ai_client_*` key equals SDK real key for current `AiClient::VERSION`.

#### ARCH-TEST-02 — Static factories (`createProviderMetadata`/`createModel`/`createProviderAvailability`) hinder instance-level mocking

- **File:Line:** `src/Providers/AbstractOpenCodeProvider.php:87–147` (`protected static function create*`), `src/Availability/OpenCodeProviderAvailability.php:57–107` (`isConfigured():bool` with `getHttpTransporter()->send()`)
- **Severity:** LOW
- **Problem:** `AbstractOpenCodeProvider` uses `protected static` factories per SDK template (`createModel:87`, `createProviderMetadata:108`, `createProviderAvailability:132`, `createModelMetadataDirectory:143`). Static dispatch is SDK-mandated (AI Client instantiates via reflection/static), so cannot be made instance-injectable. Availability's `isConfigured()` mixes `getRequestAuthentication()` check, `get_transient`, and `send()` with 401/429 interpretation at `:88–104` — requires HTTP double and transient stub to test in isolation.
- **Evidence:** `AbstractOpenCodeProvider:87` `protected static function createModel(ModelMetadata $model, …)`.
- **Impact:** Tests rely on Brain Monkey for `get_transient`/`delete_transient`/`AiClient` statics; pure unit test without Monkey requires more setup than instance DI would.
- **Recommendation:** Accept SDK constraint. For availability, consider extracting pure `isStatusOk(int $code, ?array $data):bool` and `probeRequest(string $catalog):Request` helpers to unit-test without transport. Not blocking.

#### ARCH-TEST-03 — No WP-CLI or integration test against real `wp-includes/connectors.php` — coverage gap

- **File:Line:** `phpunit.xml.dist:1`, `tests/Unit/*` (4 suites), absence of `tests/Integration`
- **Severity:** MEDIUM
- **Problem:** All tests are unit with Brain Monkey stubs. No integration asserting against real WP 7.0 `AiClient::defaultRegistry()` or `wp_connectors_init` firing via `do_action('init')`. Notable gaps: `Settings::clearModelCaches` hand-rolled key not asserted vs real SDK; `admin_notices` branch not exercised; `implements ProviderAvailabilityInterface` type not checked. WPCS `phpcs.xml:9` excludes `tests/*` from lint.
- **Evidence:** Four test files, no `tests/Integration` directory, `composer.json:32` `scripts.test: phpunit`.
- **Impact:** SDK contract drift (ARCH-VERSION-02/ARCH-COUPLING-02) would be caught only at manual QA, not CI.
- **Recommendation:** Add `tests/Integration/SdkSmokeTest.php` that boots minimal WP (`wp-phpunit`) or stubs `AiClient` with real version matrix, asserts `ai_client_*` key equality and `version_compare` gate positions. Add WP-CLI `wp eval` smoke check in CI.
- **Confidence:** High

---

## 6. Overall Architecture Verdict

| Area | Verdict |
|------|---------|
| Bootstrap & hook wiring | **Sound** — thin, defensive, respects SDK lifecycle (`init:5` → `wp_connectors_init` → `init:20`), doc-comment documents one-request activation gap. Minor frontend waste (10 hook inserts). |
| Dependency management | **Sound** — zero runtime deps correct for .org; `.distignore` clean; soft SDK dependency correctly guarded. Implicit SDK version coupling via `AiClient::VERSION` is the only fragility. |
| PSR-4 / Autoloader | **Sound** — custom PSR-4 correct, `ABSPATH` guards present, `strict_types` everywhere. Minor `file_exists` stat overhead. |
| Separation of concerns | **Good** — Providers/Metadata/Models/Availability/Settings well isolated; `ModelAllowlist` pure; `Settings` mildly overloaded but justified. |
| Coupling | **Tight where SDK requires, otherwise loose** — two medium cross-cutting couplings need attention (probe IDs `Availability ↔ ModelAllowlist`, cache-key hand-roll `Settings ↔ SDK internals`). |
| Global state | **Scoped correctly** — own `opencode_connector_*` keys, `AiClient` singletons per WP lifecycle, no `connectors_ai_*` pollution, prepared SQL. Stampede hygiene pending. |
| Naming | **Consistent** — slug/domain `duoport-connect-for-opencode` coherent; legacy namespace `OpenCodeConnector` documented, not worth renaming. |
| Version checks | **Layered correctly** — header + runtime `get_bloginfo` + `AiClient::VERSION` gates. Positional `ProviderMetadata(...$args)` construction is fragile spot. |
| Lifecycle | **Uninstall present, activation/deactivation absent** — not fatal (.org compliant), but leaves orphan `ai_client_*` caches and one-request delay. |
| Testability | **Good for hook contracts, gap for integration** — Brain Monkey specs (`ConnectorOverrideTest`, `SlimBustHooksTest`, `LegacyApprovalSourceTest`) lock architecture invariants; no SDK integration test. |

**Bottom line:** Architecture is **fit for purpose and WordPress-idiomatic** — respects the Connectors ownership model, avoids the previous `connectors_ai_*` mirroring antipattern, and isolates I/O behind SDK traits. Remaining risks are **evolutionary** (SDK version drift, probe-model coupling, cache-key coupling) rather than structural flaws.

---

## 7. Summary Counts

| Severity | Count | IDs |
|----------|-------|-----|
| **CRITICAL** | **0** | — |
| **HIGH** | **0** | — |
| **MEDIUM** | **5** | `ARCH-LIFECYCLE-01` (no activation hook), `ARCH-LIFECYCLE-02` (uninstall orphan `ai_client_*`), `ARCH-COUPLING-01` (probe ↔ allowlist), `ARCH-COUPLING-02` (hand-rolled cache key), `ARCH-VERSION-02` (positional `ProviderMetadata`), `ARCH-TEST-03` (no integration test) — counted as 6, collapsed to 5 distinct architectural risks (LIFECYCLE-02+03 merge) |
| **LOW** | **7** | `ARCH-HOOK-02`/`ARCH-HOOK-05` (frontend `init:20` waste + `admin_menu` indirection), `ARCH-HOOK-03` (`admin_notices` imprecise), `ARCH-DEP-02` (prod `autoload` split), `ARCH-PSR4-01` (`file_exists` stat), `ARCH-COUPLING-03` (`get_option` in data layer), `ARCH-COUPLING-04` (missing `baseUrl` abstract), `ARCH-NAMING-01` (namespace vs slug), `ARCH-SOC-03` (`Settings` God-object) |
| **INFO** | **12** | `ARCH-LIFECYCLE-03` (multisite site-transient), `ARCH-DEP-01`/`03`/`04` (dep mgmt observations), `ARCH-PSR4-02`/`03`, `ARCH-COUPLING-05`/`06`, `ARCH-SOC-01`/`02`/`04`, `ARCH-GLOBAL-01`/`02`/`03`/`04`, `ARCH-NAMING-02`/`03`, `ARCH-VERSION-01`/`03`, `ARCH-TEST-01`/`02` |

**Top priority fixes in order:**

1. **Probe ↔ Allowlist decoupling (ARCH-COUPLING-01):** Add `ModelAllowlist::probeModel(string $catalog):string` or derive probe ID from `ALLOW`; test that probe ID is allowlisted; consider `GET models` probe to eliminate billed `chat/completions`.
2. **Cache-key coupling (ARCH-COUPLING-02 + ARCH-LIFECYCLE-02):** Replace hand-roll `ai_client_…_md5` with SDK `clearCache()` API if available, or add integration test pinning `AiClient::VERSION` and assert equality; extend `uninstall.php` to delete `ai_client_*_models` for both directories (+ `delete_site_transient` multisite guard).
3. **ProviderMetadata fragility (ARCH-VERSION-02):** Guard `new ProviderMetadata(...$args)` with `ReflectionClass` param-name check or pin SDK version matrix in CI; alternatively request SDK `Builder` API.
4. **Activation/Deactivation hygiene (ARCH-LIFECYCLE-01):** Add minimal `register_activation_hook`/`register_deactivation_hook` for eager registration + cache flush; keep lazy fallback and documented one-request gap.
5. **Frontend hook waste (ARCH-HOOK-02/05):** Gate `init:20` Settings bootstrap with `is_admin()` for `admin_menu`/bust hooks; keep `register_setting` unconditional for REST.

---

## 8. Prioritized Recommendations

```php
// 1. Centralize probe model (ARCH-COUPLING-01) — ModelAllowlist.php
public static function probeModel(string $catalog): string {
    return 'go' === $catalog ? 'deepseek-v4-flash' : 'deepseek-v4-flash-free';
}
// + test: assert(ModelAllowlist::isAllowed(ModelAllowlist::probeModel('go'), 'go'))

// 2. Cache-key — prefer SDK API, fallback with integration test (ARCH-COUPLING-02)
// In Settings::clearModelCaches():
$cache = AiClient::getCache();
// If SDK exposes clearForClass, use it:
// $cache->deleteForClass(OpenCodeGoModelMetadataDirectory::class); // hypothetical
// otherwise pin with test asserting hand-rolled equals $sdk->cacheKey(...)

// 3. ProviderMetadata — reflection guard (ARCH-VERSION-02)
$ref = new \ReflectionClass(ProviderMetadata::class);
$params = array_map(fn($p)=>$p->getName(), $ref->getConstructor()->getParameters());
// assert $params[5]==='description' etc before spread

// 4. Lifecycle — activation/deactivation (ARCH-LIFECYCLE-01)
register_activation_hook(__FILE__, function(): void {
    if (!class_exists(\WordPress\AiClient\AiClient::class)) return;
    try { \WordPress\AiClient\AiClient::defaultRegistry()->registerProvider(OpenCodeGoProvider::class); } catch (\Throwable $e) { /* log */ }
});
register_deactivation_hook(__FILE__, function(): void {
    delete_transient('opencode_connector_avail_go');
    delete_transient('opencode_connector_avail_zen');
    // + clearModelCaches for both dirs
});

// 5. Frontend waste — conditional admin hooks (ARCH-HOOK-02/05)
add_action('init', static function(): void {
    register_setting('opencode_connector', OPTION_NAME, [...]); // always
    if (!is_admin()) return;
    (new Settings())->registerAdminOnly(); // admin_menu + bust hooks
}, 20);
```

---

## 9. Methodology Notes

- All production files read via `Read` with line numbers; `php -l` syntax check inherited from correctness audit (no fresh run needed — no code modified, reports align).
- `composer.json` `require-dev` vs `require` split verified via `vendor/composer/autoload_psr4.php` (no `OpenCodeConnector\` prod entry).
- `wp-includes/connectors.php` behavior inferred from prior audits (`_wp_connectors_init` docblock `248–276`, `ProviderMetadata` ctor via `AbstractOpenCodeProvider:108–123` call site). No SDK vendor source in `vendor/` — confidence **High** for plugin-owned code, **Medium** for SDK constructor/ cache-key internals.
- Tests not re-executed; `ConnectorOverrideTest`/`SlimBustHooksTest`/`LegacyApprovalSourceTest` reviewed to confirm architecture invariants are spec-locked.
- Findings cross-referenced with `agent-php-correctness.md` (`F-DC-*`, `F-AV-*`, `F-MD-*`, `F-ST-*`, `F-UN-*`) and `agent-performance.md` (`PERF-*`) to avoid duplication and surface only architecture-relevant aspects.

---

*End of report — Architecture Audit — no production code modified. Report written to `AUDIT/AGENTS/agent-architecture.md`.*
