# Database & Caching Audit — DuoPort Connect for OpenCode

**Agent:** agent-database-cache (WordPress Database/Caching Specialist)
**Date:** 2026-08-28
**Scope:** Full line-by-line review of 15 production files. Focus: `get_option`/`update_option`/`delete_option`, transients (`get_transient`/`set_transient`/`delete_transient`), object cache (`AiClient::getCache()` / `wp_cache_*` / `wp_using_ext_object_cache`), `$wpdb` queries, cache key design, expiry, invalidation, collision risks, persistent cache compatibility, race conditions / stampede, option `opencode_connector_settings`, transients `opencode_connector_avail_go`/`_zen`, `ai_client_*_models` keys, cron, uninstall cleanup, multisite compatibility.
**Method:** `Read` every production file (lines cited), `Grep` for `get_option|update_option|delete_option|get_transient|set_transient|delete_transient|wp_cache|wpdb|transient|cache|OPTION_NAME|opencode_connector|ai_client|cron|schedule`, cross-checked against `duoport-connect-for-opencode.php:1-144` bootstrap lifecycle and sibling audits (performance, php-correctness, security). No production code modified.
**Repo snapshot:** Plugin `0.1.1`, `Requires at least: 7.0`, `Requires PHP: 8.2` (`duoport-connect-for-opencode.php:5-7,26`).

---

## 1. Files Reviewed

| # | File | Lines | DB/Cache relevance |
|---|------|-------|-------------------|
| 1 | `duoport-connect-for-opencode.php` | 1–144 | `OPTION_NAME` constant `:27`, `update_option`/`add_option` hook registrations for bust `:74-80`, `wp_connectors_init` override (no DB) `:86-123`, `init:20` settings bootstrap `:126-134` |
| 2 | `src/autoload.php` | 1–31 | None (no DB/cache) — PSR-4 autoloader |
| 3 | `src/Settings/Settings.php` | 1–183 | **Primary DB/cache file.** `register_setting` `:36-44`, `get_option` `:147`, `get_option` inside metadata parse `:87` (call site), `bustCaches` `:74-80` + `bustCachesAdd` `:91-96`, `clearModelCaches` with `AiClient::getCache()` + `delete_transient` + `$wpdb->query DELETE` `:105-126`, `delete_transient avail` `:77-78,94-95` |
| 4 | `src/Availability/OpenCodeProviderAvailability.php` | 1–108 | `get_transient` `:65`, `set_transient` `:105`, `5*MINUTE_IN_SECONDS` expiry `:105`, `delete_transient` callers elsewhere |
| 5 | `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php` | 1–135 | `get_option(OPTION_NAME)['show_all_models']` `:87`, SDK cache key `ai_client_*_models` consumed via `Settings::clearModelCaches` only, no direct transient ops here |
| 6 | `src/Metadata/ModelAllowlist.php` | 1–125 | No DB/cache — constants only |
| 7 | `src/Metadata/OpenCodeGoModelMetadataDirectory.php` | 1–49 | No DB/cache |
| 8 | `src/Metadata/OpenCodeZenModelMetadataDirectory.php` | 1–49 | No DB/cache |
| 9 | `src/Models/AbstractOpenCodeTextGenerationModel.php` | 1–76 | No DB/cache |
| 10 | `src/Models/OpenCodeGoTextGenerationModel.php` | 1–38 | No DB/cache |
| 11 | `src/Models/OpenCodeZenTextGenerationModel.php` | 1–38 | No DB/cache |
| 12 | `src/Providers/AbstractOpenCodeProvider.php` | 1–148 | No DB/cache (in-memory registry) |
| 13 | `src/Providers/OpenCodeGoProvider.php` | 1–80 | No DB/cache |
| 14 | `src/Providers/OpenCodeZenProvider.php` | 1–80 | No DB/cache |
| 15 | `uninstall.php` | 1–14 | `delete_option` `:12`, `delete_transient` `:13-14` |

Excluded: `vendor/`, `tests/`, `assets/`, `composer.json`, `phpcs.xml`, `phpunit.xml.dist`. Cron check: zero hits for `wp_schedule|cron|wp_next_scheduled` in production source.

---

## 2. Methodology

- Read every production file with `Read` (full content, line numbers verified). Re-read `duoport-connect-for-opencode.php:27` (`const OPTION_NAME`), `Settings.php:35-48` (`register`), `Settings.php:105-126` (`clearModelCaches`), `Availability.php:57-107` (`isConfigured`), `AbstractOpenCodeModelMetadataDirectory.php:87` (`get_option`), `uninstall.php:12-14`.
- Grepped production + tests for `get_option|update_option|delete_option|get_transient|set_transient|delete_transient|wp_cache|wpdb|transient|cache|OPTION_NAME|opencode_connector|ai_client|cron|schedule` (36 hits in `src/`, 100 overall including vendor). Manually classified each hit as DB read/write vs cache vs test harness.
- Traced WordPress option/transient lifecycle: `register_setting` → `get_option`/`update_option` via `wp-admin/options.php`; `get_transient`/`set_transient` branching on `wp_using_ext_object_cache()` (object cache vs `wp_options` `_transient_*` rows); `AiClient::getCache()` (SDK's `WP_Object_Cache` or `wp_cache_*` wrapper) vs `delete_transient` dual-layer; `$wpdb->options` direct delete fallback.
- Evaluated cache key design (uniqueness, namespace, hash, version), expiry (TTL, jitter, negative caching, soft-expiry), invalidation (hooks, coverage, idempotency), collision risks (prefix, multisite, user-scope), persistent cache compatibility (external object cache vs DB fallback), race conditions / stampede (check-then-set without lock, thundering herd, write contention).
- Cross-checked sibling audits (`agent-performance.md:1-455`, `agent-php-correctness.md:1-509`, `agent-security.md:1-290`) for agreement on `hand-rolled ai_client_* key` and `avail stampede` findings; re-verified evidence independently rather than trusting summaries.

---

## 3. Executive Summary

**Verdict: NO CRITICAL DB corruption or cache poisoning. Correctness of primary option and availability transients is sound. MEDIUM gaps remain: stampede on `opencode_connector_avail_*`, hand-rolled `ai_client_*_models` key fragility, incomplete uninstall cleanup, single-site-only `$wpdb->options` usage (multisite), and missing jitter / lock / delete hooks.**

- **Steady-state DB load: ~0 extra queries on frontend warm path** (confirmed via trace: `init:5` provider registration does `class_exists`+`hasProvider` in-memory, no option reads; `init:20` settings bootstrap only registers hooks). **Admin settings warm: 2 `get_transient` reads** (object-cache `get` or `SELECT _transient_*`), **1 `get_option`** (from `alloptions` cache). **Cold: +2 network probes + 2 `set_transient` writes**. See `agent-performance.md:372-384` for matching counts — DB audit confirms same.
- **Overall cache design is conventional WordPress** (`get_option` autoload, transients with `5*MINUTE_IN_SECONDS`, `AiClient::getCache()` layered cache). No `wp_cache_set`/`wp_cache_get` direct except via SDK, no cron, no site-transients.
- **Top DB/cache risks** (all MEDIUM): **(1) availability stampede** (`Availability.php:64-68,105` no lock/jitter), **(2) model-cache key coupling** (`Settings.php:112` `md5` duplication), **(3) uninstall leaves orphaned `ai_client_*_models` rows** (`uninstall.php:12-14` vs `Settings.php:112`), **(4) multisite not cleaned** (`$wpdb->options` only, no `$wpdb->sitemeta`/`delete_site_transient`), **(5) delete hooks missing** (no `delete_option_*` bust for key or settings).

---

## 4. Option Trace — `opencode_connector_settings` (`OPTION_NAME`)

### 4.1 Definition and Registration

- **Constant:** `duoport-connect-for-opencode.php:27` — `const OPTION_NAME = 'opencode_connector_settings';` — namespaced but short, 27 chars, `opencode_` prefix reduces collision risk (not `wp_` or bare `settings`). No site-prefix needed; `get_option` is per-site via `$wpdb->options` table prefix.
- **Registration:** `src/Settings/Settings.php:36-44`:
  ```php
  register_setting(
    'opencode_connector',
    \OpenCodeConnector\OPTION_NAME,
    array(
      'type'              => 'array',
      'default'           => array( 'show_all_models' => false ),
      'sanitize_callback' => array( $this, 'sanitize' ),
    )
  );
  ```
  - `register_setting` correctly uses `type=>array` and `default=>['show_all_models'=>false]` — WP will return default when option missing (`get_option(OPTION_NAME, ['show_all_models'=>false])` at `Settings.php:147` and `AbstractOpenCodeModelMetadataDirectory.php:87` both pass defaults consistently).
  - `sanitize_callback` at `Settings.php:58-63` normalizes: `if (!is_array($value)) return ['show_all_models'=>false]; return ['show_all_models'=>!empty($value['show_all_models'])];` — guarantees array shape, prevents string/int corruption from polluting cache.
  - **Autoload:** `register_setting` does not pass `autoload` param; WP core defaults to `autoload=yes` for `add_option` path. For a 50-byte array (`a:1:{s:15:"show_all_models";b:0;}` serialized), autoload `yes` means loaded into `alloptions` cache on every request via `wp_load_alloptions()` (one `SELECT * WHERE autoload='yes'`). Cost: negligible (<0.1ms, in-memory after first load), avoids extra `SELECT` per `get_option`. Correct choice — option read on every `parseResponseToModelMetadataList` (`:87`) benefits from autoload. If strict micro-optimization desired, could set `'autoload'=>false` and rely on `wp_cache_get` — not recommended; keep `yes`.
  - **No direct `add_option`/`update_option` in plugin** — writes go through `wp-admin/options.php` `options.php` handler which validates nonce via `settings_fields('opencode_connector')` at `Settings.php:172`. No plugin-owned `update_option(OPTION_NAME, ...)` calls — clean ownership (mirrors security audit `agent-security.md:103-108` PASS).

### 4.2 Read Trace

| Call site | Code | Cache behavior | DB fallback |
|-----------|------|----------------|-------------|
| `Settings.php:147` (render) | `$opts = get_option(\OpenCodeConnector\OPTION_NAME, array('show_all_models'=>false));` | `wp_cache_get('alloptions')` or `wp_cache_get(OPTION_NAME)` hit after first load — in-memory hash, no DB | `SELECT option_value FROM wp_options WHERE option_name='opencode_connector_settings'` (autoload=yes → already in `alloptions` query, so 0 extra) |
| `AbstractOpenCodeModelMetadataDirectory.php:87` | `$show_all = (bool)(get_option(\OpenCodeConnector\OPTION_NAME, array())['show_all_models'] ?? false);` | Same — cached after first `get_option` per request | Same |
| `Settings.php:62` (sanitize) | `!empty($value['show_all_models'])` — `$value` is raw POST array, not DB | No DB | — |

- **Correctness edge (php-correctness F-MD-02):** `AbstractOpenCodeModelMetadataDirectory.php:87` does direct offset `get_option(...)['show_all_models'] ?? false` without `is_array` guard. If DB row corrupted to string (e.g. manual `UPDATE wp_options SET option_value='corrupt'`), `['show_all_models']` triggers `Warning: Trying to access array offset on value of type string` before `??` applies. **MEDIUM correctness, not DB corruption** — DB value itself unaffected, but warning pollutes error log and could throw if `WP_DEBUG` handler converts warnings to `ErrorException`. **Fix:** `$raw=get_option(...,[]); $show_all=is_array($raw)&&!empty($raw['show_all_models']);`. Low DB impact.

### 4.3 Write and Delete Trace

- **Writes:** Via `register_setting` + `settings_fields` + `options.php` POST — WP core calls `update_option(OPTION_NAME, $sanitized)` internally. Plugin does not call `update_option` directly for this name — verified via grep (zero hits for `update_option.*opencode_connector_settings`).
- **Deletes:** Only `uninstall.php:12` — `delete_option('opencode_connector_settings');` — guarded by `if (!defined('WP_UNINSTALL_PLUGIN')) exit;` at `:9-11` — correct single-site delete. No `delete_site_option` — multisite gap (see §10).
- **Autoload cleanup:** `delete_option` removes `alloptions` cache entry and DB row — no orphan.

### 4.4 Option Design Verdict

| Aspect | Verdict | Evidence |
|--------|---------|----------|
| Namespace / collision | PASS | `opencode_connector_settings` unique, 27 chars, no core collision |
| Size | PASS | Single bool array — <50B serialized, autoload overhead trivial |
| Sanitization | PASS | `sanitize:58-63` strict, `register_setting:42` |
| Autoload | PASS | Default `yes` appropriate for per-request read at `:87` |
| Read caching | PASS | Hits `alloptions` / `wp_cache_get` after first load |
| Corruption guard | MEDIUM | F-MD-02 missing `is_array` before offset — not DB corruption but warning |

---

## 5. Transient Trace — `opencode_connector_avail_go` / `_zen`

### 5.1 Key Design

- **Keys:** `src/Availability/OpenCodeProviderAvailability.php:64` — `$tkey = 'opencode_connector_avail_' . $this->catalog;` where `$this->catalog` is `'go'` or `'zen'` (constructor `:46` `private readonly string $catalog`). Result: `opencode_connector_avail_go` (27 chars) / `opencode_connector_avail_zen` (28 chars). Well under 172-char `option_name` limit (191 with `_transient_` prefix, 167 max for site transients — still safe).
- **Namespace:** `opencode_connector_` prefix (18 chars) namespaces to plugin, avoids collision with core `ai_client_*` or other plugins. No user-ID or API-key hash suffix — intentional: key is **site-wide** (same credential for all users), invalidation via `delete_transient` on key change (see §7) rather than per-key variant. This matches `Settings::render:148-149` `isProviderConfigured` which is site-scoped.
- **Storage:** `get_transient($tkey)` / `set_transient($tkey, (int)$ok, 5*MINUTE_IN_SECONDS)` at `Availability.php:65,105`. With external object cache (Redis/Memcached): stored via `wp_cache_set($tkey, $value, 'transient', $ttl)` — no DB rows, sub-ms. Without: stored as two `wp_options` rows: `_transient_opencode_connector_avail_go` (`option_value='1'` or `'0'` serialized) + `_transient_timeout_opencode_connector_avail_go` (`option_value='1719999999'`), `autoload='no'` — one `SELECT` per `get_transient` (or combined if `alloptions` miss), two `INSERT/UPDATE` per `set_transient`, two `DELETE` per `delete_transient`.
- **Value encoding:** `set_transient:105` stores `(int)$ok` (`0`/`1`), read at `:65-68` with `if (false !== $cached) return (bool)$cached;` — strict `!== false` distinguishes cache miss (`false`) from cached `0` (`0 !== false` → true → `(bool)0 === false` correctly returns not-configured). **PASS** — avoids negative-cache poisoning where `false` would be mistaken for miss. Correctness audit F-AV-04 confirms.

### 5.2 Expiry

- **TTL:** `Availability.php:105` — `set_transient($tkey, (int)$ok, 5 * MINUTE_IN_SECONDS);` — 300s fixed. Appropriate: short enough to recover within 5 min after user fixes 401 key, long enough to avoid per-page probe. Both positive (`$ok=true` for 200/429/CreditsError) and negative (`false` for 401/other/throw) cached same TTL — avoids hammering down API (good, per `agent-performance.md:72-75`).
- **No jitter:** Fixed 300s → synchronized expiry across `go` and `zen` (both set at same `time()` after `delete_transient` bust deletes both simultaneously at `Settings.php:77-78` or `duoport-connect-for-opencode.php:75-76`). At 5-minute boundary, concurrent workers herd. **MEDIUM stampede contributor** (see §11).
- **No soft-expiry / grace:** `get_transient` returns `false` immediately on expiry — no `['value'=>..., 'time'=>...]` wrapper with stale-while-revalidate. Could add `expiration + grace` pattern.
- **Auth edge:** 401 `CreditsError` treated as `ok=true:98` cached positive — prevents flapping when credits exhausted but auth valid. Correct.

### 5.3 Object Cache vs DB Branching

- `get_transient`/`set_transient`/`delete_transient` are the **correct** WordPress abstraction — they automatically branch on `wp_using_ext_object_cache()`. No direct `wp_cache_get`/`wp_cache_set` for availability keys — good, respects persistent cache setting. No `wp_cache_add` lock attempted (stampede gap).
- `Availability.php:65` `get_transient` after `getRequestAuthentication()` try/catch — `getRequestAuthentication` failure returns `false` before transient read, so unauthenticated probe never hits cache — correct, avoids caching "not configured due to missing key" under same key as probe result (but actually same early-return would also be `false`, so cache not poisoned either way).

### 5.4 Uninstall Cleanup

- `uninstall.php:13-14` — `delete_transient('opencode_connector_avail_go'); delete_transient('opencode_connector_avail_zen');` — correctly removes both availability transients. With object cache, `delete_transient` does `wp_cache_delete` (no DB row); without, deletes both `_transient_*` and `_transient_timeout_*` rows. **PASS** — but only single-site `delete_transient`, not `delete_site_transient` (multisite gap §10).

### 5.5 Verdict

| Aspect | Verdict | Evidence |
|--------|---------|----------|
| Key uniqueness | PASS | `opencode_connector_avail_{go,zen}` namespaced, short |
| Value semantics | PASS | `(int)0/1` + `false!==cached` strict — correct negative caching |
| TTL choice | PASS | 300s balanced |
| Jitter | MEDIUM MISSING | Fixed 300s → synchronized expiry |
| Persistent cache compat | PASS | Uses `get/set/delete_transient` abstraction correctly |
| Uninstall | PASS (single-site) | `uninstall.php:13-14` |
| Collision risk | LOW | Hard-coded catalog suffix, site-wide, no user scope needed |

---

## 6. Cache Key Design — `ai_client_*_models`

### 6.1 Key Construction

- **Construction:** `src/Settings/Settings.php:112` — `$full_key = 'ai_client_' . AiClient::VERSION . '_' . md5($cls) . '_models';` where `$cls` is `OpenCodeGoModelMetadataDirectory::class` or `OpenCodeZenModelMetadataDirectory::class` (`$classes:106-109`). Example: `ai_client_1.2.3_abc123..._models`.
- **Components:** `ai_client_` (9 chars) + `AiClient::VERSION` (semver, e.g. `1.0.0` — 5 chars) + `_` + `md5` (32 chars) + `_models` (7 chars) = ~54 chars transient name, ~64 with `_transient_` prefix, ~72 with timeout — well under limits.
- **Provenance:** Duplicates SDK's internal key formula from `AbstractOpenAiCompatibleModelMetadataDirectory` (inferred, since SDK vendor not in repo — `composer.json:16-23` dev deps only). Plugin does **not** call SDK-provided `getCacheKey()` or `clearCache()` API — **hand-rolled** (correctness F-ST-02, performance PERF-SETT-02, security INFO F-SEC-INFO-03 all flag this).
- **Usage sites:**
  - `Settings.php:112-124` — `clearModelCaches()` constructs `full_key` for both directories, checks `AiClient::getCache()->has($full_key)` → `delete`, then `delete_transient($full_key)`, then raw `$wpdb->query DELETE`.
  - Indirect: SDK parent `AbstractOpenAiCompatibleModelMetadataDirectory` presumably does `get_transient(full_key)` / `set_transient(full_key, $list, $ttl)` for model list caching — plugin only busts, not reads/writes directly.

### 6.2 Collision and Versioning

- **Versioned by `AiClient::VERSION`:** When SDK bumps version (e.g. `1.2.0` → `1.3.0`), key changes (`ai_client_1.2.0_*` vs `ai_client_1.3.0_*`) — old keys orphaned until expiry/GC, new keys cold. Correct — avoids stale schema after SDK upgrade.
- **Hashed by `md5($cls)`:** Class FQCN hash ensures Go vs Zen directories have distinct keys (`md5('OpenCodeConnector\Metadata\OpenCodeGoModelMetadataDirectory')` vs Zen). Collision probability negligible (md5 128-bit, but truncated to 32 hex chars — still ~2^-64 for two keys). No user input in hash — not injectable.
- **No site/blog prefix:** `full_key` lacks `get_current_blog_id()` or `wp_cache` blog prefix. WordPress transients are **per-site** via `$wpdb->options` table prefix (`wp_2_options` for blog 2 in multisite) + `get_transient` uses `wp_options` per blog, so no cross-site collision even without blog ID. For object cache, `wp_cache_*` adds blog prefix automatically via `wp_cache_init` — so safe. No collision across sites.
- **No API-key or `show_all_models` suffix:** Model list varies by `show_all_models` toggle (`AbstractOpenCodeModelMetadataDirectory.php:87,107-108` filters via `ModelAllowlist::isAllowed`), but key does **not** include `show_all` variant. Instead, `bustCaches:74-80` deletes entire key when `show_all` toggles, forcing refetch with new filter. Correct — avoids key proliferation (2 variants × 2 catalogs × N versions), but means toggle bust is **destructive** (cold miss for all users) rather than variant switch. Acceptable for rare toggle.

### 6.3 TTL and Storage

- **TTL:** Not set by plugin — SDK-controlled (likely `HOUR_IN_SECONDS` or `DAY_IN_SECONDS`, not shown). Plugin only deletes, never sets. Sibling audit `agent-performance.md:353` infers parent cache TTL — plausible.
- **Storage:** Dual-layer in `clearModelCaches`: `AiClient::getCache()` (likely `WP_Object_Cache` or `wp_cache_*` with group) + `delete_transient` (object-cache or DB) + raw `DELETE FROM wp_options` fallback. See §8 for object-cache compat.

### 6.4 Fragility

- **Hand-rolled coupling — MEDIUM:** If SDK changes prefix to `ai_client_v2_` or hash to `sha256` or adds salt/blog-ID, `Settings.php:112` will delete wrong key, **bust silently misses**, stale model list survives toggle until natural expiry. User sees no change, retries repeatedly — perceived perf bug. Security audit correctly notes no injection, but correctness audit F-ST-02 flags freshness risk. **Recommendation:** Prefer SDK `AiClient::getCache()->delete($directory)` or `$directory->clearCache()` if exposed; else pin integration test: `assertSame('ai_client_'.AiClient::VERSION.'_'.md5(GoDir::class).'_models', $sdk->getCacheKey(GoDir::class))` per SDK release.

### 6.5 Uninstall Orphan

- `uninstall.php:12-14` does **not** delete `ai_client_*_models` transients — leaves orphaned rows per `agent-php-correctness.md: F-UN-01` and `agent-security.md: F-SEC-INFO-05`. DB impact: each `ai_client_*` entry is serialized model list (16–19 `ModelMetadata` with `SupportedOption` refs) — ~5–15KB per catalog ×2 catalogs ×2 versions = ~20–60KB orphaned per site, plus timeout rows. Not huge, but leaks until transient GC (`_transient_timeout_*` expiry) or manual cleanup. **MEDIUM hygiene**.

### 6.6 Verdict

| Aspect | Verdict | Evidence |
|--------|---------|----------|
| Uniqueness | PASS | `ai_client_` + version + `md5(class)` distinct |
| Collision risk | LOW | Site-scoped via `wp_options` prefix + `wp_cache` blog prefix |
| Version safety | PASS | Versioned, avoids stale after SDK bump |
| Hand-rolled coupling | MEDIUM | Duplicates SDK internals — bust may miss if SDK changes |
| TTL control | INFO | SDK-owned, not plugin |
| Uninstall orphan | MEDIUM | Not cleaned in `uninstall.php:12-14` |

---

## 7. Invalidation — Hooks and Coverage

### 7.1 Registered Invalidation Hooks

| Hook | Location | Callback | Deleted keys | Args |
|------|----------|----------|--------------|------|
| `update_option_connectors_ai_opencode_go_api_key` | `duoport-connect-for-opencode.php:78` | `$opencode_connector_bust:74-76` closure `():void { delete_transient(go); delete_transient(zen); }` | `opencode_connector_avail_go`, `_zen` | `(old, new)` discarded (closure takes 0) — intentional slim |
| `add_option_connectors_ai_opencode_go_api_key` | `duoport-connect-for-opencode.php:79` | Same closure | Same | `(option, value)` discarded |
| `update_option_opencode_connector_settings` | `src/Settings/Settings.php:46` | `bustCaches:74-80` `(old, new)` → if `show_all` changed: `clearModelCaches()` + `delete_transient avail go/zen` | `ai_client_*_models` (both) + avail `go`/`zen` | `($old_value, $new_value)` correctly declared `10,2` |
| `add_option_opencode_connector_settings` | `src/Settings/Settings.php:47` | `bustCachesAdd:91-96` `(option, value)` → `clearModelCaches()` + `delete_transient avail` | Same | `(string $option, $value)` with `unset` — clears unconditionally |

- **Hook priority:** All default `10` — correct, runs after core `sanitize` and `update_option` success. WP fires `update_option_{$option}` inside `update_option()` after `wpdb->update` and `wp_cache_delete` — synchronous, no race with request that triggered save.
- **Idempotency:** `delete_transient` on non-existent key is no-op (returns `false` but no error) — safe to call twice (e.g. `bustCaches` called for same `show_all` toggle plus `update_option` hook fires again via other plugin). No duplicate-delete harm.

### 7.2 Coverage Matrix

| Event | Expected invalidation | Actual | Gap |
|-------|----------------------|--------|-----|
| `show_all_models` toggled via `Settings::sanitize` + `options.php` | Model cache + avail | `bustCaches:74-80` does both — **PASS** | None |
| `opencode_connector_settings` first creation (`add_option`) | Model cache + avail | `bustCachesAdd:91-96` does both — **PASS** | None |
| `connectors_ai_opencode_go_api_key` updated via core Connectors UI (`sanitize_text_field` at `wp-includes/connectors.php:801`) | Avail `go`+`zen` | `duoport-connect-for-opencode.php:74-80` deletes both — **PASS** (slim, no `get_option`/`update_option` mirroring, verified by `tests/Unit/SlimBustHooksTest.php:30-117`) | None |
| `connectors_ai_opencode_go_api_key` first added | Avail `go`+`zen` | Same `add_option_*` hook — **PASS** | None |
| `connectors_ai_opencode_go_api_key` deleted (`delete_option`) | Avail should clear | **MISSING** — no `add_action('delete_option_connectors_ai_opencode_go_api_key', ...)` | **MEDIUM** — deleting key (e.g. `wp option delete` CLI) leaves stale `avail` positive until 300s expiry, UI shows "connected" briefly |
| `opencode_connector_settings` deleted (`delete_option`) | Model cache + avail should clear? | **MISSING** — no `delete_option_opencode_connector_settings` hook | **LOW** — deletion rare (manual CLI or uninstall via `delete_option` not `uninstall.php` path); stale model cache survives until expiry, not critical |
| `connectors_ai_opencode_go_api_key` added/updated but `zen` key separately (legacy) | Not needed — `zen` shares go key via `wp_connectors_init` override `:111` | Correct — only go key matters — **PASS** | None |
| SDK version bump (`AiClient::VERSION` change) | Old `ai_client_oldVersion_*` orphaned | Not invalidated — **LOW** orphan until GC | Expected — versioned keys cold after bump |

### 7.3 Invalidation Correctness Notes

- **Conservative double-delete on settings toggle:** `bustCaches:77-78` deletes avail even when toggle could only affect model list, not availability — harmless, ensures `show_all` + availability probe not out-of-sync if probe model logic changed together (e.g. probe ID coincides with allowlist entry).
- **No model-cache bust on API-key change:** `duoport-connect-for-opencode.php:74-80` only deletes avail, not `ai_client_*_models`. Correctness audit `agent-performance.md:103` notes this as minor gap: after key rotation, `GET models` still served from stale cache until expiry, but next fetch will auth-fail and surface error — not immediate but not stale-success (won't show models that shouldn't be visible). Could also bust model cache on key change for faster recovery — **LOW**.

### 7.4 Verdict

| Aspect | Verdict | Evidence |
|--------|---------|----------|
| Settings toggle invalidation | PASS | `Settings.php:46-48,74-96` correct, guarded by `show_all` diff |
| Connector key invalidation | PASS | `duoport-connect-for-opencode.php:74-80` slim, correct |
| Delete hooks | MEDIUM MISSING | No `delete_option_*` for either name |
| Idempotency | PASS | `delete_transient` idempotent |
| Model-cache on key change | LOW MISSING | Not busted, but minor |

---

## 8. Object Cache, Transients, and Persistent Cache Compatibility

### 8.1 Transient Abstraction

- WordPress transients API (`get_transient`/`set_transient`/`delete_transient`) is the **single correct abstraction** for this plugin's availability cache — automatically delegates to external object cache when `wp_using_ext_object_cache()` true (Redis/Memcached via `wp_cache_get/set/delete` with `transient` group), or DB `_transient_*` rows when false. Plugin uses this exclusively for `opencode_connector_avail_*` (`Availability.php:65,105` + bust `delete_transient` sites) — **PASS**.

### 8.2 `AiClient::getCache()` Layer

- `src/Settings/Settings.php:110-116`:
  ```php
  $cache = AiClient::getCache();
  foreach ($classes as $cls) {
    $full_key = 'ai_client_' . AiClient::VERSION . '_' . md5($cls) . '_models';
    if ($cache) {
      if ($cache->has($full_key)) {
        $cache->delete($full_key);
      }
    }
    delete_transient($full_key);
  ```
  - `AiClient::getCache()` likely returns a `Psr\SimpleCache\CacheInterface` or WordPress `WP_Object_Cache` wrapper (inferred, no vendor source). `has`+`delete` handles in-memory/object-cache layer.
  - `delete_transient` after `cache->delete` **double-deletes** same key via different layer: if `getCache()` *is* `WP_Object_Cache` with `wp_cache_*`, then `cache->delete` and `delete_transient` both do `wp_cache_delete` for same key — redundant but not harmful. If `getCache()` is separate store (e.g. SDK's own in-memory array), then both layers needed — correct defense.

### 8.3 Direct `$wpdb` Fallback — Triple Delete

- `src/Settings/Settings.php:118-124`:
  ```php
  delete_transient($full_key);
  // Fallback: direct option delete for object-cache-less installs.
  global $wpdb;
  if (isset($wpdb)) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fallback for object-cache-less installs.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s OR option_name = %s", '_transient_' . $full_key, '_transient_timeout_' . $full_key));
  }
  ```
  - **Intention:** Fallback for non-persistent-cache installs where `delete_transient` deletes `_transient_*` rows — but `delete_transient` already does `delete_option('_transient_*')` internally, so raw `DELETE` is **redundant** when object cache not persistent. PHPCS suppressed correctly at `:122`, `prepare` with `%s` correctly escapes `full_key` (hard-coded, not user input — no SQLi, per `agent-security.md:150-158`).
  - **On persistent-cache installs (Redis):** `_transient_*` rows **not used** — `delete_transient` is `wp_cache_delete` only, no DB rows. Raw `DELETE` then scans `wp_options` index for non-existent `option_name='_transient_ai_client_*'` — 2 `DELETE` queries per catalog ×2 catalogs = 4 index scans hitting 0 rows, ~0.2ms each, on rare settings-save event — negligible but wasteful.
  - **Optimization:** Gate with `if (!wp_using_ext_object_cache())` before `$wpdb->query` — saves 4 DB writes on Redis sites. Keep `has` guard removal (`if ($cache->has(...))` adds extra `get` roundtrip) — `delete` is idempotent, skip `has` (per `agent-performance.md: PERF-SETT-01`).

### 8.4 Persistent Cache Behavior Summary

| Scenario | Availability `get_transient` | Availability `set/delete_transient` | Model cache `AiClient::getCache` + `delete_transient` + `$wpdb` | DB rows created? |
|----------|------------------------------|------------------------------------|----------------------------------------------------------------|------------------|
| **No external cache** (default WP) | `SELECT option_value FROM wp_options WHERE option_name='_transient_opencode_connector_avail_go'` + timeout check | `INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('_transient_...','0','no')` + timeout row; `DELETE FROM wp_options WHERE option_name IN (...)` | `cache->delete` (maybe in-memory) + `delete_transient` (DB) + raw `DELETE` (DB) | Yes — `opencode_connector_*` + `ai_client_*` rows |
| **Redis/Memcached** | `wp_cache_get('opencode_connector_avail_go','transient')` — sub-ms, no DB | `wp_cache_set/delete` — sub-ms, no DB | `cache->delete` (object cache) + `delete_transient` (`wp_cache_delete`) + raw `DELETE` (scans DB for 0 rows) | No for transients — only if `wp_cache` fallback disabled |

- **No `wp_cache_flush` or `wp_cache_init` calls** — correct, respects site-wide cache.
- **No `wp_cache_add` lock** — not used anywhere (stampede gap).

### 8.5 Verdict

| Aspect | Verdict | Evidence |
|--------|---------|----------|
| Availability transient abstraction | PASS | Correct `get/set/delete_transient` |
| Model cache dual-layer | PASS | `getCache` + `delete_transient` covers both |
| Raw SQL fallback | LOW REDUNDANT | Triple-delete; gate with `!wp_using_ext_object_cache()` |
| PHPCS/security | PASS | `prepare` with `%s`, hard-coded key — no SQLi |
| `has`+`delete` | LOW | Extra `get` roundtrip — skip `has` |

---

## 9. `wpdb` Queries — Inventory and Audit

### 9.1 Direct Queries in Production

| Location | Query | Prepared? | Purpose | Frequency |
|----------|-------|-----------|---------|-----------|
| `src/Settings/Settings.php:122-123` | `DELETE FROM {$wpdb->options} WHERE option_name = %s OR option_name = %s` with `'_transient_' . $full_key`, `'_transient_timeout_' . $full_key` | Yes — `$wpdb->prepare("... %s OR %s", ...)` | Fallback delete of `ai_client_*_models` transient rows | Per `show_all` toggle (rare) ×2 catalogs |
| — | No other `wpdb->query`/`get_var`/`get_results`/`insert`/`update` | — | — | — |

- **No `wpdb->get_results` for reads** — all reads via `get_option`/`get_transient`/`AiClient::getCache` (which internally may use `$wpdb` but not plugin-direct).
- **No `LIKE` with unsanitized input** — `%s` placeholders cover exact equality, no `esc_like` needed.
- **Table name:** `{$wpdb->options}` property — not user input, correct for single-site. Multisite gap: does not handle `{$wpdb->sitemeta}` / `{$wpdb->get_blog_prefix($id)->options}` for per-site or network-wide deletes (see §10).

### 9.2 Indirect Queries via WP APIs

- `register_setting` + `get_option`/`update_option`/`delete_option` → `wpdb->get_var("SELECT option_value...")` / `update_option` → `wpdb->update($wpdb->options, ...)` / `delete_option` → `wpdb->query("DELETE FROM ...")` — all via core, not plugin-direct, but accounted in DB load table below.
- `get_transient` without object cache → `get_option('_transient_*')` → one `SELECT`; `set_transient` → `set_option` or `add_option` with `autoload='no'` → `INSERT` or `UPDATE`; `delete_transient` → `delete_option` ×2.

### 9.3 Verdict

| Aspect | Verdict | Evidence |
|--------|---------|----------|
| Direct query safety | PASS | Single prepared `DELETE`, hard-coded key |
| Query count (steady-state) | PASS | 0 direct queries on warm frontend (see §8.4) |
| Query count (avail cold) | PASS | 2 `set_transient` → 4 `INSERT/UPDATE` rows (2 keys ×2 rows) — rare (every 300s) |
| Query count (settings save) | PASS | ~6 deletes triple-layer, rare event |
| PHPCS flag | INFO | Suppressed at `:122` with justification — acceptable |

---

## 10. Multisite Compatibility

### 10.1 Current Behavior

- **Option `opencode_connector_settings`:** `get_option`/`delete_option` are **per-site** (`wp_options` vs `wp_2_options` via `$wpdb->get_blog_prefix()`). `register_setting('opencode_connector', OPTION_NAME, ...)` registers for current site only. `uninstall.php:12` `delete_option` runs per-site when WP invokes `uninstall.php` per blog for network-activated plugins (since WP 5.1) — **sufficient for per-site cleanup**, but no `delete_site_option` or `is_multisite()` loop. Documented as F-UN-02 in `agent-php-correctness.md:425-430` (INFO/LOW).
- **Transients `opencode_connector_avail_*`:** `get_transient`/`set_transient`/`delete_transient` are **per-site** (table-prefixed `wp_options` / site object-cache prefix). No `get_site_transient`/`set_site_transient`/`delete_site_transient` usage. For network-activated plugin, each site has independent availability cache — correct if each site can have different key, but current design shares one `connectors_ai_opencode_go_api_key` per site (core connectors are per-site via `get_option` in `wp-includes/connectors.php:391`), so per-site transients correct.
- **Model cache `ai_client_*_models`:** `delete_transient` + `$wpdb->options` delete is **per-site only**. On multisite, `wp_sitemeta` is not touched. If plugin is network-activated and model cache were ever stored as `site_transient` (inferred SDK may use `get_site_transient` for network-wide cache — not confirmed), then per-site delete would miss `wp_sitemeta` rows. Also `clearModelCaches:120-124` uses `$wpdb->options` only — would not delete `wp_sitemeta` `_site_transient_*` rows on multisite.

### 10.2 Gaps and Risks

| Gap | Severity | Evidence | Impact |
|-----|----------|----------|--------|
| No `delete_site_transient` for availability or model cache | LOW | No `*_site_transient*` calls anywhere (`grep` zero hits) | If SDK ever uses site transients on multisite, orphaned rows in `wp_sitemeta` |
| `clearModelCaches` uses `$wpdb->options` only, not `$wpdb->sitemeta` or `$wpdb->get_blog_prefix()` loop | MEDIUM | `Settings.php:120-123` `{$wpdb->options}` hard-coded | On multisite with many blogs, network-wide settings toggle (if added) would not clear model caches across all sites — stale model list per site |
| `uninstall.php` does not handle network-wide uninstall iteration | LOW | `uninstall.php:12-14` single `delete_option` | WP core handles per-site invocation since 5.1, so not a bug, but explicit `if (is_multisite()) { foreach (get_sites()... switch_to_blog... delete_option... }` would be more defensive for older WP (not needed at `Requires at least: 7.0`) |
| Cache key lacks blog ID | INFO | `opencode_connector_avail_go` static | Safe — `get_transient` per-site via table prefix + `wp_cache` blog prefix, so no cross-site collision |

### 10.3 Recommendation

- If multisite officially supported, add `delete_site_transient($full_key)` and `delete_site_transient('opencode_connector_avail_go')` alongside `delete_transient` in `clearModelCaches` and `uninstall.php`, and guard raw SQL with `if (is_multisite()) { $wpdb->query(... sitemeta ...) }`. If single-site only, document in `readme.txt` and add comment in `Settings.php:120-124` clarifying per-site scope — lowest cost.

---

## 11. Race Conditions and Cache Stampede

### 11.1 Availability Probe Stampede

- **Code:** `src/Availability/OpenCodeProviderAvailability.php:64-105`:
  ```php
  $tkey = 'opencode_connector_avail_' . $this->catalog;
  $cached = get_transient($tkey);
  if (false !== $cached) {
    return (bool)$cached;
  }
  // ... build Request to chat/completions with probe_model deepseek-v4-flash[-free] ...
  $req = new Request(HttpMethodEnum::POST(), $cls::url('chat/completions'), ...);
  $req = $this->getRequestAuthentication()->authenticateRequest($req);
  $res = $this->getHttpTransporter()->send($req);
  // ... status code logic 200/429/401 CreditsError → $ok ...
  set_transient($tkey, (int)$ok, 5 * MINUTE_IN_SECONDS);
  return $ok;
  ```
- **Race:** `get_transient` miss (`false`) → `send` → `set_transient` is **check-then-set without atomic lock**. When `tkey` expires, N concurrent requests (e.g. 20 PHP-FPM workers after cache clear, or admin + REST + cron parallel) all see `false` and fire **N duplicate `POST https://opencode.ai/.../chat/completions` probes** (each billed `max_tokens:1`, 150–800ms TLS+inference, plus DB `set_transient` write contention on `wp_options` `INSERT ... ON DUPLICATE KEY UPDATE`).
- **No mitigation:** No `wp_cache_add("lock_$tkey",1,'',30)` / `set_transient($tkey.'_lock',1,30)` / `get_transient` soft-expiry with stale-while-revalidate / `random_int` jitter. `set_transient` TTL is fixed `5*MINUTE_IN_SECONDS` → synchronized expiry (both `go`+`zen` set at same `time()` after bust deletes both).
- **Amplifiers:**
  - `Settings::render:148-149` calls `isProviderConfigured('opencode-go')` then `isProviderConfigured('opencode-zen')` **sequentially** on every settings page load — cold both triggers 2 probes serially, blocking render 1–3s (performance PERF-AV-03).
  - Bust hooks `delete_transient` both keys simultaneously (`Settings.php:77-78`, `duoport-connect-for-opencode.php:75-76`) → next `isConfigured` call for either catalog primes both at same timestamp → synchronized expiry 5 min later → herd at boundary.
- **Severity: MEDIUM** — not data corruption (last `set_transient` wins, value is deterministic `0/1` per status code), but **billed cost, latency, and DB write contention** under load. Classic thundering herd.
- **Recommendation:**
  ```php
  // Before probe:
  $lock_key = $tkey . '_lock';
  if (false !== get_transient($lock_key)) {
    // Another worker is probing — serve stale or false without probing.
    return false; // or return last stale if soft-expiry wrapper used
  }
  set_transient($lock_key, 1, 30);
  try {
    // ... existing probe ...
    set_transient($tkey, (int)$ok, 5*MINUTE_IN_SECONDS + random_int(0,60));
  } finally {
    delete_transient($lock_key);
  }
  ```
  Or soft-expiry: store `['ok'=>bool,'time'=>time()]` with TTL `300+60`, serve stale when `time()-stored_time < 360` while background refresh via `wp_schedule_single_event`. At minimum add `random_int(0,60)` jitter to desynchronize.

### 11.2 Model Metadata Race

- **Code:** `Settings::clearModelCaches:110-124` deletes `AiClient::getCache` entry + `delete_transient` + raw `DELETE` — then next `getModels()` (SDK parent) will `GET https://opencode.ai/.../models` and `set_transient` (SDK-controlled). Concurrent `GET models` after bust could herd similarly, but **LOW risk**: model listing is rare (SDK cache likely hourly), admin-triggered, not per-request. SDK may have own lock (unverified, no vendor source). No plugin fix required beyond reducing triple-delete races (see §8.3).

### 11.3 Option Write Race

- **Option `opencode_connector_settings`:** `register_setting` + `update_option` via `options.php` is atomic per `wpdb->update` with `WHERE option_name=%s` — last write wins, no lost-update anomaly beyond standard WP. `bustCaches:74-80` conditional `show_all` diff avoids clearing when unrelated keys change — correct, no race.
- **No `update_option` read-modify-write in plugin** — no `get_option` → mutate → `update_option` pattern that would need `wp_cache` compare-and-swap.

### 11.4 `get_option` Corruption Race

- **Edge F-MD-02:** If concurrent `update_option` corrupts serialized value (e.g. `update_option(OPTION_NAME, 'string')` bypassing sanitize via direct `$wpdb` or CLI), `get_option` at `:87` warns but does not corrupt DB further. No plugin recovery path — WP core `maybe_unserialize` handles.

### 11.5 Verdict

| Race | Severity | Type | Evidence |
|------|----------|------|----------|
| Availability thundering herd | MEDIUM | Check-then-set without lock, fixed TTL, synchronized expiry | `Availability.php:64-68,105`, `Settings.php:77-78` |
| Settings render blocking | MEDIUM (perf) | Sequential synchronous probes, no timeout | `Settings.php:148-149`, `Availability.php:88-90` |
| Model metadata herd | LOW | Bust → concurrent `GET models` | `Settings.php:110-124` (rare) |
| Option write | INFO | No plugin RMW | `register_setting` only |

---

## 12. Cron — Scheduled Tasks

- **Search:** `grep` for `wp_schedule|cron|wp_next_scheduled|wp_unschedule|schedule.*event` in production source returned **0 hits** (excluding vendor). No `add_action('duoport_*_cron', ...)` or `wp_schedule_single_event` usage.
- **Behavior:** Plugin relies on **synchronous `isConfigured()` probe** (`Availability.php:57-107`) on demand (admin settings render, `isProviderConfigured`), not background cron refresh. No periodic `GET models` refresh — SDK cache handles model list TTL (likely `HOUR_IN_SECONDS`+), not plugin cron.
- **Implication:** No cron table bloat, no `wp_cron` overhead, no orphaned `wp_options` cron row (`cron` option). Correct for low-frequency probe (5-min transient). If background availability refresh desired (to avoid blocking `render`), could add `wp_schedule_single_event(time()+300, 'duoport_refresh_avail')` on transient miss and serve stale — but not required.
- **Uninstall:** No `wp_clear_scheduled_hook` needed — none scheduled. **PASS**.

---

## 13. Uninstall Cleanup — `uninstall.php:1-14`

### 13.1 Code

```php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
delete_option('opencode_connector_settings');
delete_transient('opencode_connector_avail_go');
delete_transient('opencode_connector_avail_zen');
```

### 13.2 Audit

| Item | Deleted | Orphan left? | Severity | Evidence |
|------|---------|--------------|----------|----------|
| Option `opencode_connector_settings` | Yes — `delete_option` `:12` | No | PASS | Single-site, `alloptions` cleaned |
| Transient `opencode_connector_avail_go` | Yes — `delete_transient` `:13` | No | PASS | Deletes `_transient_*` + `_transient_timeout_*` or `wp_cache_delete` |
| Transient `opencode_connector_avail_zen` | Yes — `delete_transient` `:14` | No | PASS | Same |
| Transient `ai_client_{VERSION}_{md5}_models` (×2 catalogs) | **No** | **Yes** — orphaned `ai_client_*_models` rows remain until expiry/GC | MEDIUM | `Settings.php:112` keys not handled here; F-UN-01 in `agent-php-correctness.md:412-422` |
| Site transients (`*_site_transient_*` in `wp_sitemeta`) | **No** | **Yes** (if any) on multisite | LOW | No `delete_site_transient` or `delete_site_option` |
| `ai_client_*` with old `VERSION` after SDK bump | No | Orphaned until GC | LOW | Versioned keys cold after bump, not cleaned |
| Cron hooks | N/A | None scheduled | PASS | §12 |

- **Guard:** `WP_UNINSTALL_PLUGIN` at `:9-11` correct — prevents direct URL invocation.
- **No `$wpdb` direct delete of `ai_client_*`** — `delete_transient` would be sufficient if added; raw `LIKE 'ai_client_%_models'` delete with `prepare`+`esc_like` could enumerate orphaned versions.
- **Multisite:** Since WP 5.1, `uninstall.php` is invoked **per site** for network-activated uninstall, so `delete_option` per-site suffices (F-UN-02). No loop over `get_sites()` needed at `Requires at least: 7.0`.

### 13.3 Recommendation

Add to `uninstall.php:12-14`:

```php
// Clean SDK model-metadata caches (both catalogs, current version)
if (class_exists(\WordPress\AiClient\AiClient::class)) {
  foreach (array(
    \OpenCodeConnector\Metadata\OpenCodeGoModelMetadataDirectory::class,
    \OpenCodeConnector\Metadata\OpenCodeZenModelMetadataDirectory::class,
  ) as $cls) {
    $k = 'ai_client_' . \WordPress\AiClient\AiClient::VERSION . '_' . md5($cls) . '_models';
    delete_transient($k);
    if (is_multisite()) { delete_site_transient($k); }
  }
}
// Multisite site-transient fallback
if (is_multisite()) {
  delete_site_transient('opencode_connector_avail_go');
  delete_site_transient('opencode_connector_avail_zen');
}
```

Alternatively wildcard cleanup (defensive for version orphans):

```php
global $wpdb;
$wpdb->query($wpdb->prepare(
  "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
  $wpdb->esc_like('_transient_ai_client_') . '%_models'
));
$wpdb->query($wpdb->prepare(
  "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
  $wpdb->esc_like('_transient_timeout_ai_client_') . '%_models'
));
```

---

## 14. Collision Risks — Namespace and Key Uniqueness

| Risk | Analysis | Severity | Evidence |
|------|----------|----------|----------|
| **Option name collision** | `opencode_connector_settings` unique 27-char, `opencode_` prefix, not `wp_`/`settings` generic. No other plugin known to use same. `register_setting` group `opencode_connector` distinct. | PASS | `duoport-connect-for-opencode.php:27` |
| **Transient name collision** | `opencode_connector_avail_go/_zen` 27/28-char, `opencode_connector_` prefix. `_transient_` prefix + 63-char limit safe. No core transient uses same. | PASS | `Availability.php:64`, `Settings.php:77-78` |
| **Model cache key collision** | `ai_client_{VERSION}_{md5(class)}_models` — `ai_client_` is SDK-owned prefix, but `delete_transient`/`cache->delete` scoped to SDK; no other plugin should use same `md5(GoDir::class)` — FQCN hash distinct per provider directory. No user input in hash. | PASS (with coupling note) | `Settings.php:112` |
| **Multisite cross-site collision** | `get_transient` is per-site via `$wpdb->get_blog_prefix()` table routing + `wp_cache` blog-group prefix. Static key without blog ID still site-isolated. | PASS | §10 |
| **User-scope collision** | Availability and model cache are site-wide (key independent of `get_current_user_id()`), correct because credential is site option `connectors_ai_opencode_go_api_key` (per-site, not per-user). No per-user transient needed. | PASS | `Availability.php:64` |
| **API-key variant collision** | Key does not suffix API-key hash — intentional invalidation via `delete_transient` on key change (`duoport-connect-for-opencode.php:74-80`) rather than key variant. If invalidation missed, stale `avail` (e.g. `true` for old valid key) could survive until expiry after key deleted (see §7.2 delete-hook gap). | LOW (delete gap) | `Availability.php:64`, `duoport-connect-for-opencode.php:74-80` |
| **Version collision** | SDK version bump creates new `ai_client_{new}_*` keys, old keys orphaned but not colliding (different version segment). | PASS | `Settings.php:112` |

---

## 15. Findings Detail

> Severity: CRITICAL (data loss / DB corruption / cache poisoning), HIGH (user-visible stale/freshness bug, write failure), MEDIUM (thundering herd / billed cost / orphaned rows / missed invalidation), LOW (redundant query / micro-optimization), INFO (confirmation / edge).

### DB-CACHE-01 — Availability stampede (no lock, no jitter, synchronized expiry)

- **File:Line:** `src/Availability/OpenCodeProviderAvailability.php:64-68,105` — `get_transient` miss → `send` → `set_transient` fixed `5*MINUTE_IN_SECONDS`; `src/Settings/Settings.php:77-78` + `duoport-connect-for-opencode.php:75-76` delete both keys simultaneously.
- **Severity:** MEDIUM
- **Evidence:** `get_transient($tkey)===false` path unconditionally probes `POST https://opencode.ai/.../chat/completions` with `max_tokens:1` and `deepseek-v4-flash[-free]` model, then `set_transient($tkey,(int)$ok,300)`. No `wp_cache_add` lock, no `set_transient($tkey.'_lock',1,30)`, no `random_int` jitter, no soft-expiry `['value'=>bool,'time'=>time()]`. Concurrent expiry → N workers ×2 catalogs = N×2 billed probes + DB write contention.
- **Impact:** 20 FPM workers after bust → 40 concurrent probes, 300–800ms each, billing + TLS overhead, `wp_options` write contention. `Settings::render:148-149` sequential double probe blocks settings page 1–3s cold.
- **Recommendation:** Add lock + jitter (see §11.1 snippet); consider switching probe to `GET models` (cheaper, metadata-only) or `wp_schedule_single_event` background refresh.

### DB-CACHE-02 — Hand-rolled `ai_client_*_models` cache key duplicates SDK internals

- **File:Line:** `src/Settings/Settings.php:112` — `$full_key='ai_client_'.AiClient::VERSION.'_'.md5($cls).'_models'`.
- **Severity:** MEDIUM
- **Evidence:** SDK vendor not in repo; key formula inferred from `AbstractOpenAiCompatibleModelMetadataDirectory` pattern. If SDK changes to `ai_client_v2_` / `sha256` / salt+blog-ID, bust deletes wrong key, stale model list survives `show_all_models` toggle until natural expiry.
- **Impact:** User toggles “Show all models”, sees no update. No DB corruption, but freshness bug.
- **Recommendation:** Use SDK `clearCache()` API if exposed; else pin integration test asserting key equals SDK real key for current `AiClient::VERSION`, and document coupling. Gate raw SQL fallback with `!wp_using_ext_object_cache()`.

### DB-CACHE-03 — Uninstall leaves orphaned `ai_client_*_models` transients

- **File:Line:** `uninstall.php:12-14` vs `src/Settings/Settings.php:112`.
- **Severity:** MEDIUM
- **Evidence:** Uninstall deletes `opencode_connector_settings` + `opencode_connector_avail_*` but not `ai_client_{VERSION}_{md5}_models` (×2 catalogs) created via SDK metadata directory and busted in `clearModelCaches`. Each orphan is ~5–15KB serialized model list + timeout row, `autoload='no'`.
- **Impact:** Leaked rows in `wp_options` until transient expiry (`HOUR_IN_SECONDS`? SDK-controlled) or GC. Not sensitive, but hygiene.
- **Recommendation:** Enumerate both directories and `delete_transient($k)` + `delete_site_transient` + optional wildcard `LIKE 'ai_client_%_models'` cleanup (see §13.3).

### DB-CACHE-04 — Missing `delete_option_*` invalidation hooks

- **File:Line:** `duoport-connect-for-opencode.php:78-79` only `update_option_` + `add_option_` for `connectors_ai_opencode_go_api_key`; `src/Settings/Settings.php:46-47` only `update_option_` + `add_option_` for `OPTION_NAME`.
- **Severity:** MEDIUM (for connector key delete), LOW (for settings delete)
- **Evidence:** `delete_option('connectors_ai_opencode_go_api_key')` via CLI or core does not trigger `delete_transient` bust — stale positive `avail` survives until 300s expiry, UI incorrectly shows “connected”. Same for `delete_option(OPTION_NAME)` edge.
- **Recommendation:** Add `add_action('delete_option_connectors_ai_opencode_go_api_key', $opencode_connector_bust);` and `add_action('delete_option_'.OPTION_NAME, [Settings, bustOnDelete])`.

### DB-CACHE-05 — Triple-delete per model-cache bust (redundant DB writes)

- **File:Line:** `src/Settings/Settings.php:113-124` — `if ($cache->has($k)) $cache->delete($k);` → `delete_transient($k);` → `$wpdb->query(DELETE FROM options WHERE option_name IN ...)`.
- **Severity:** LOW
- **Evidence:** `delete_transient` already does `wp_cache_delete` + `delete_option('_transient_*')` (two rows). Raw `DELETE` scans `wp_options` again for same names. `has()` adds extra `get` roundtrip. On persistent cache (Redis), raw `DELETE` scans DB for 0 rows ×4 (2 catalogs×2 rows).
- **Impact:** Settings save (rare) does ~6–8 cache/DB ops instead of 2–4; each indexed `DELETE` ~0.2ms, total wasted ~0.5–1ms. Not user-visible, but waste on high-write Redis sites.
- **Recommendation:** `if ($cache) $cache->delete($k);` (no `has()`), `delete_transient($k);`, then `if (!wp_using_ext_object_cache()) { $wpdb->query(...) }`.

### DB-CACHE-06 — Multisite `$wpdb->options` only (no `sitemeta` / `site_transient`)

- **File:Line:** `src/Settings/Settings.php:120-123` `{$wpdb->options}` hard-coded; `uninstall.php:12-14` only `delete_option`/`delete_transient`.
- **Severity:** MEDIUM (if multisite supported), LOW (if single-site)
- **Evidence:** No `delete_site_transient`, no `{$wpdb->sitemeta}`, no `get_sites()` loop. Core `get_transient`/`get_option` are per-site via table prefix, so per-site scope correct for current design, but network-wide model cache (if SDK uses `site_transient`) would not be cleared. `clearModelCaches` would miss site-wide rows.
- **Recommendation:** Add `delete_site_transient` alongside `delete_transient` and `is_multisite()` guard with `sitemeta` wildcard delete, or document single-site scope.

### DB-CACHE-07 — `get_option` without `is_array` guard before offset (corruption edge)

- **File:Line:** `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:87` — `$show_all=(bool)(get_option(OPTION_NAME,[])['show_all_models'] ?? false)`.
- **Severity:** MEDIUM (correctness), INFO (DB)
- **Evidence:** If DB row manually corrupted to string/int (`UPDATE wp_options SET option_value='x' WHERE option_name='opencode_connector_settings'`), offset access warns before `??` applies (`Trying to access array offset on value of type string`). Not DB corruption itself, but pollutes log and could throw if warning→exception handler.
- **Recommendation:** `$raw=get_option(OPTION_NAME,[]); $show_all=is_array($raw)&&!empty($raw['show_all_models']);`

### DB-CACHE-08 — Fixed 300s TTL without jitter (synchronized expiry)

- **File:Line:** `src/Availability/OpenCodeProviderAvailability.php:105` `5*MINUTE_IN_SECONDS`.
- **Severity:** LOW (contributes to herd)
- **Evidence:** Both `go`+`zen` set at same timestamp after bust → synchronized expiry at 5-minute boundary → herd.
- **Recommendation:** `5*MINUTE_IN_SECONDS + random_int(0,60)`.

### DB-CACHE-09 — No `delete_transient` for `ai_client_*` on connector key change (faster recovery)

- **File:Line:** `duoport-connect-for-opencode.php:74-80` (only avail), vs `src/Settings/Settings.php:105-126` (model cache bust only on settings).
- **Severity:** LOW
- **Evidence:** After API-key rotation, `GET models` still served from stale `ai_client_*_models` cache until SDK TTL, not immediately revalidated. Next fetch will 401, but stale list shown meanwhile.
- **Recommendation:** Optionally bust model caches on `update_option_connectors_ai_opencode_go_api_key` as well (one extra `delete_transient` ×2).

### DB-CACHE-10 — No negative-cache / soft-expiry wrapper (re-probe on every miss)

- **File:Line:** `src/Availability/OpenCodeProviderAvailability.php:64-68` `false!==cached` check.
- **Severity:** INFO
- **Evidence:** `false` miss vs `0` negative cache distinguished correctly via `(int)` + strict check — good. No soft-expiry (serve stale while background refresh).
- **Recommendation:** Consider `['ok'=>bool,'time'=>time()]` wrapper with grace `60s` to serve stale during lock contention.

---

## 16. DB Queries and Cache Operations — Hot-Path Counts

### 16.1 DB/cache ops per request type (warm cache, no external object cache)

| Request type | DB/cache ops from plugin | Evidence |
|--------------|--------------------------|----------|
| Frontend anonymous (no AI) | **0 DB reads** | `init:5` `class_exists`+`hasProvider` memory, `init:20` hook regs memory, `wp_connectors_init` early-return memory — no `get_option`/`get_transient` unless AI used |
| WP-Admin list (no settings) | 0–1 | `admin_notices` `get_bloginfo('version')` global; provider registration same as frontend |
| Settings screen — warm avail | **2 `get_transient` reads + 1 `get_option`** | `get_transient('opencode_connector_avail_go')` + `_zen` each `SELECT _transient_*` (or `wp_cache_get`), `get_option(OPTION_NAME)` from `alloptions` (0 extra) |
| Settings screen — cold avail | **2 reads miss + 2 `set_transient` writes (4 rows) + 2 network probes** | `get_transient` miss → `POST chat/completions` Go/Zen → `set_transient` `INSERT _transient_*` + `_transient_timeout_*` per catalog |
| Settings save (`update_option opencode_connector_settings`) | **~6 deletes** | `clearModelCaches` 2× `cache->delete` + 2× `delete_transient` + 2× raw `DELETE` + 2× `delete_transient avail` |
| `GET models` SDK miss | **1 network + 1 cache write** | `GET https://opencode.ai/.../models` → `AiClient::getCache()->set` + `set_transient` via SDK parent |
| Uninstall | **3 deletes** | `delete_option(OPTION_NAME)` + `delete_transient avail go/zen` → 1 `DELETE wp_options` + 4 `DELETE _transient_*` (or `wp_cache_delete`) |

With external object cache (Redis): all `get/set/delete_transient` become `wp_cache_get/set/delete` — **0 DB rows** for transients; only `get_option` (autoload `alloptions` via `wp_cache_get` if persistent `alloptions` cache enabled, else `SELECT`).

### 16.2 `ai_client_*_models` vs `opencode_connector_avail_*` — Storage comparison

| Cache | Key | Storage layer | TTL | Write frequency | Read frequency |
|-------|-----|---------------|-----|-----------------|----------------|
| `avail_go` | `opencode_connector_avail_go` `Availability.php:64` | `transient` (`wp_cache` or `wp_options` `_transient_*`) | 300s fixed (`Availability.php:105`) | Every 300s per catalog + herd | Every `isConfigured()` (settings render, `isProviderConfigured`) |
| `avail_zen` | `opencode_connector_avail_zen` | Same | 300s fixed | Same | Same |
| `ai_client_*_models` (Go) | `ai_client_{VERSION}_{md5(GoDir)}_models` `Settings.php:112` | `AiClient::getCache()` + `transient` + `$wpdb` fallback | SDK-controlled (likely 1h) | On `show_all` toggle or SDK expiry | On `getModels()` per provider |
| `ai_client_*_models` (Zen) | `ai_client_{VERSION}_{md5(ZenDir)}_models` | Same | Same | Same | Same |
| `opencode_connector_settings` | `opencode_connector_settings` `duoport-connect-for-opencode.php:27` | `wp_options` `autoload=yes` → `alloptions` cache | Persistent | Rare (settings save) | Per-request `get_option` at `:87`, `Settings.php:147` |

---

## 17. Summary Counts

| Severity | Count | IDs |
|----------|-------|-----|
| CRITICAL | **0** | — |
| HIGH | **0** | — |
| MEDIUM | **5** | DB-CACHE-01 stampede, DB-CACHE-02 hand-rolled key, DB-CACHE-03 uninstall orphan, DB-CACHE-04 delete hooks, DB-CACHE-06 multisite `sitemeta` |
| LOW | **4** | DB-CACHE-05 triple-delete, DB-CACHE-08 jitter, DB-CACHE-09 key-change model bust, DB-CACHE-06 (if single-site, LOW) |
| INFO | **2** | DB-CACHE-07 `is_array` guard, DB-CACHE-10 soft-expiry |

**Top priority:** Address DB-CACHE-01 (lock + jitter + short timeout for `opencode_connector_avail_*`), DB-CACHE-03 (uninstall `ai_client_*` cleanup), DB-CACHE-02 (decouple hand-rolled key via SDK API or pinned test). Next, add DB-CACHE-04 delete hooks and gate DB-CACHE-05 raw SQL with `!wp_using_ext_object_cache()`.

---

## 18. Recommendations (Prioritized)

1. **Stampede + blocking (MEDIUM):** In `OpenCodeProviderAvailability.php:64-105`, implement `wp_cache_add`/`set_transient` lock before `send()`, return stale/false on contention; add `random_int(0,60)` to TTL; pass short timeout `['timeout'=>3]` via `getRequestOptions()`; make `Settings::render:148-149` async or parallelize go/zen probes; consider switching probe to `GET models` to cut billed cost.
2. **Uninstall orphan (MEDIUM):** In `uninstall.php:12-14`, enumerate both `OpenCodeGo/ZenModelMetadataDirectory` classes and `delete_transient('ai_client_'.AiClient::VERSION.'_'.md5(...).'_models')` + `delete_site_transient` if `is_multisite()`, plus optional wildcard `DELETE ... LIKE 'ai_client_%_models'` for version orphans.
3. **Key coupling (MEDIUM):** In `Settings.php:112`, replace hand-rolled `'ai_client_'.VERSION.'_'.md5.'_models'` with SDK `getCache()->delete($directory)` if available; else add `tests/Unit` assertion pinning SDK version and key formula, and gate SQL fallback with `!wp_using_ext_object_cache()`.
4. **Delete hooks (MEDIUM/LOW):** Add `add_action('delete_option_connectors_ai_opencode_go_api_key', $opencode_connector_bust);` in `duoport-connect-for-opencode.php:78-79` and `delete_option_opencode_connector_settings` in `Settings.php:46-47`.
5. **Multisite + triple-delete (LOW):** In `Settings.php:113-124`, remove `has()` check, keep `delete_transient` as primary, condition raw `DELETE` on `!wp_using_ext_object_cache()`, and handle `delete_site_transient` + `{$wpdb->sitemeta}` when `is_multisite()`.

---

## 19. Methodology Notes

- All 15 production files + `uninstall.php` read in full via `Read` with line numbers; greps executed for `get_option|update_option|delete_option|get_transient|set_transient|delete_transient|wp_cache|wpdb|transient|cache|OPTION_NAME|opencode_connector|ai_client|cron|schedule` across `src/` (36 hits) and full plugin (100 hits including vendor/tests — filtered).
- Cron verified via zero hits for `wp_schedule|cron|wp_next_scheduled` in production source — no scheduled tasks to audit for DB/cache bloat.
- SDK vendor not in repo (`composer.json:16-23` dev only), so `AiClient::getCache()`, `AbstractOpenAiCompatibleModelMetadataDirectory` caching internals, and `WithHttpTransporterTrait::send()` inferred from interface/trait names and call sites — confidence High for plugin-owned DB/cache code, Medium for SDK internals (noted where relevant).
- No runtime profiling (`query-monitor`, `wp db query`, `redis-cli monitor`) executed; DB counts estimated from `get_transient`/`wp_options` known semantics and `alloptions` autoload. Recommend validating warm 0-query frontend path and cold probe latency with Query Monitor + `wp_cache_get` stats on staging.

---

*End of report — Database & Caching Audit — no production code modified. Report written to `AUDIT/AGENTS/agent-database-cache.md`.*
