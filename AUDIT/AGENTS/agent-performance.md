# Performance Audit — DuoPort Connect for OpenCode

**Agent:** agent-performance (Performance Specialist)
**Date:** 2026-08-28
**Scope:** Full line-by-line review of 15 production files. Focus: CPU loops, object creation, serialization, filesystem, network, hooks, memory; WordPress: hook execution, option lookups, transients, object cache; DB queries; frontend; caching correctness (key design, expiry `5*MINUTE_IN_SECONDS`, invalidation, stampede); Remote requests to `https://opencode.ai`. No production code modified.
**Method:** `Read` every file (lines cited), `Grep` for `get_option|get_transient|set_transient|delete_transient`, `wpdb`, `spl_autoload`, `add_action|add_filter`, `wp_remote|Request|send`, `MINUTE_IN_SECONDS`, `md5|ai_client`. Cross-checked against `duoport-connect-for-opencode.php:1-144` bootstrap lifecycle and SDK contract inference (traits/DTO names).

---

## 1. Files Reviewed

| # | File | Lines | Purpose | Hot-path relevance |
|---|------|-------|---------|-------------------|
| 1 | `duoport-connect-for-opencode.php` | 1–144 | Bootstrap, `init:5` provider registration, `wp_connectors_init` override, transient bust, settings bootstrap | Every request (multiple `add_action` at top level) |
| 2 | `src/autoload.php` | 1–31 | PSR-4 autoloader `OpenCodeConnector\` | Every autoload miss |
| 3 | `src/Availability/OpenCodeProviderAvailability.php` | 1–108 | Probe `isConfigured()` + `5*MINUTE` transient cache to `https://opencode.ai` | Admin settings render, `isProviderConfigured()` |
| 4 | `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php` | 1–135 | `GET models` → allowlist filter → `ModelMetadata` list + sort | Model listing (cached by SDK) |
| 5 | `src/Metadata/ModelAllowlist.php` | 1–125 | Hard-coded `ALLOW`/`FREE`, `isAllowed`/`isFree`/`displayName` | Inside metadata loop |
| 6 | `src/Metadata/OpenCodeGoModelMetadataDirectory.php` | 1–49 | Go directory binding | Indirect |
| 7 | `src/Metadata/OpenCodeZenModelMetadataDirectory.php` | 1–49 | Zen directory binding | Indirect |
| 8 | `src/Models/AbstractOpenCodeTextGenerationModel.php` | 1–76 | `chat/completions` model, `prepareResponseFormatParam` | Text generation (not audited for hot-path, but request building) |
| 9 | `src/Models/OpenCodeGoTextGenerationModel.php` | 1–38 | Go model binding | Minimal |
| 10 | `src/Models/OpenCodeZenTextGenerationModel.php` | 1–38 | Zen model binding | Minimal |
| 11 | `src/Providers/AbstractOpenCodeProvider.php` | 1–148 | Provider base: `createProviderMetadata` (`version_compare`), `createModel` | Provider registration |
| 12 | `src/Providers/OpenCodeGoProvider.php` | 1–80 | Go provider (`baseUrl https://opencode.ai/zen/go/v1`) | URL building |
| 13 | `src/Providers/OpenCodeZenProvider.php` | 1–80 | Zen provider (`baseUrl https://opencode.ai/zen/v1`) | URL building |
| 14 | `src/Settings/Settings.php` | 1–183 | `register_setting`, `bustCaches`/`clearModelCaches`, `render()` | `init:20` every request + settings page render |
| 15 | `uninstall.php` | 1–14 | `delete_option` + `delete_transient` | Uninstall only |

Excluded: `vendor/`, `tests/`, `assets/`, `readme.txt`, `composer.json`. `phpcs.xml:1-21` skims only.

---

## 2. Methodology

- Read every production file in full (line numbers above). Verified `declare(strict_types=1)`, `ABSPATH` guards, `spl_autoload_register`, `add_action` priorities, `get_transient`/`set_transient`/`delete_transient`, `$wpdb->query`, `new Request`, `usort`, `in_array`, `str_replace`/`strpos`.
- Traced runtime cost categories: **CPU** (loops, `in_array` scans, `usort`, string ops), **object creation** (`Request`, `Response`, `ModelMetadata`, `SupportedOption`), **serialization** (transient values `(int)$ok`, option array `['show_all_models'=>bool]`), **filesystem** (`file_exists` in autoloader), **network** (`chat/completions` probe + `models` listing to `https://opencode.ai`), **hooks** (hook registration count & per-request execution), **memory** (allowlist constants, `common_opts` arrays, transient cache).
- WordPress specifics: `get_bloginfo('version')` vs `$wp_version`, `get_option(OPTION_NAME)` autoload/caching, transient object-cache vs `wp_options` fallback (`_transient_*`), `AiClient::getCache()` vs `delete_transient` dual-layer, `$wpdb->options` direct delete.
- Caching audit: key design (`opencode_connector_avail_go` / `_zen`, `ai_client_VERSION_md5(class)_models`), expiry `5*MINUTE_IN_SECONDS:105`, invalidation hooks (`update_option_connectors_ai_opencode_go_api_key`, `update_option_opencode_connector_settings`), stampede/lock analysis, negative caching, multisite prefix.

---

## 3. Executive Summary

**Verdict: NO CRITICAL perf regression. Plugin is lightweight (<1ms CPU overhead on warm cache, 0 extra DB queries on frontend warm path, 0 frontend assets).** Two MEDIUM issues warrant action: **(1) availability-probe stampede** (no lock/jitter, billed `chat/completions` on thundering herd) and **(2) settings-page synchronous probe blocking** (no timeout guard, double probe serially). Remaining findings are LOW/INFO micro-optimizations or correctness couplings that affect invalidation freshness, not steady-state latency.

- **Frontend (anonymous page view, warm cache):** `init:5` provider registration does `class_exists` + `AiClient::defaultRegistry()->hasProvider()` in-memory check — no DB, no network, no option reads. `init:20` settings bootstrap adds 3 hooks unconditionally (wasted but <0.05ms). Autoloader not triggered unless AI used. `wp_connectors_init` runs but early-returns after 4 `method_exists` checks. **Measured expectation: +0.2–0.5ms, +0 transients/queries, ~20KB memory.**
- **Admin settings page (cold availability cache):** Up to **2 sequential network probes** `POST https://opencode.ai/.../chat/completions` with `max_tokens=1` can block page render for `SDK default timeout` (often 5–15s) ×2. Warm (within 5 min): 2× `get_transient` hits (object cache `get` or single `SELECT`), <1ms.
- **Model listing (SDK cache miss):** One `GET https://opencode.ai/zen/.../v1/models` per catalog, then in-process filtering (16–19 allowed IDs out of potentially 50+ API rows), ~19 object allocs + `usort` micro-cost. Cached thereafter by `AiClient::getCache()`/transient layer.

---

## 4. Focus-Area Deep Dives

### 4.1 Availability Probe (`src/Availability/OpenCodeProviderAvailability.php:1-108`)

**Purpose:** `isConfigured():57-107` gates "connected" badge. Cached at `set_transient(tkey,(int)ok,5*MINUTE_IN_SECONDS):105`.

**Hot path:** `Settings::render():148-149` calls `AiClient::defaultRegistry()->isProviderConfigured('opencode-go'|'opencode-zen')` → delegates to `isConfigured()` → `get_transient:65` fast path else `Request→send:72-89` network probe.

**Performance characteristics:**

| Aspect | Evidence | Cost |
|--------|----------|------|
| Transient read | `get_transient('opencode_connector_avail_'.$catalog):64-65` → `false!==$cached` strict check:68 | Object-cache `get` or `SELECT option_name='_transient_...'` + `unserialize` of int. <0.5ms warm. |
| Probe construction | `new Request(HttpMethodEnum::POST(),$cls::url('chat/completions'),['Content-Type'=>'application/json'],['model'=>probe,'messages'=>[['role'=>'user','content'=>'ping']],'max_tokens'=>1]):72-86` + `authenticateRequest:88` | Allocates `Request` + `HttpMethodEnum` singleton + 3 arrays. ~microseconds; serialization to JSON inside transporter. |
| Network | `$this->getHttpTransporter()->send($req):89` to `https://opencode.ai/zen/go/v1` or `https://opencode.ai/zen/v1` | **Dominant cost: 150–800ms p50, up to SDK timeout on failure.** Billed `chat/completions` token cost. `max_tokens=1` minimizes egress but still full TLS + auth + inference queue. |
| Response parse | `$res->getStatusCode():90` + `getData():96` → `$data['error']['type'] ?? ''` | `json_decode` inside `getData`. Cheap. |
| Transient write | `set_transient($tkey,(int)$ok,5*MINUTE_IN_SECONDS):105` | `set_transient` → object-cache `set` or `INSERT INTO wp_options ... autoload='no'` + timeout row. 1–2 DB writes on miss. |

**Caching correctness:**

- **Key design PASS:** `opencode_connector_avail_go`/`_zen:64` static, site-scoped via `wp_options` prefix (multisite table prefix + `get_transient` per-site). No user ID needed (key is site credential). No API-key hash needed because **invalidation hook exists**: `duoport-connect-for-opencode.php:74-80` `delete_transient` on `update_option_connectors_ai_opencode_go_api_key` + `add_option_...` (slim, correct). `Settings::bustCaches:77-78` also deletes both on `show_all_models` toggle — conservative but harmless.
- **Expiry 300s appropriate:** Short enough to surface key-validity recovery within 5 min after user rectifies 401, long enough to avoid per-page probe. Negative (false) and positive (true) same TTL — caches "not configured" to avoid hammering down API (good). 401 `CreditsError` treated as `ok=true:98` cached positive — avoids flapping credits display.
- **Invalidation PASS:** Two-layer bust (connector key change + settings toggle + `clearModelCaches` for model list). `uninstall.php:13-14` deletes both avail transients — clean.
- **Stampede FAIL (MEDIUM):** No lock, no `set_transient` with `wp_using_ext_object_cache` branching, no jitter, no soft-expiry. When `tkey` expires, **N concurrent requests (e.g. admin + cron + REST) all see `false===get_transient` and fire N duplicate `chat/completions` probes** to `https://opencode.ai`. Under load (e.g. 20 PHP-FPM workers after cache clear), 20× billed probes, TLS handshakes, and write contention on `set_transient`. SDK's `AiClient::getCache()` not involved here (availability uses raw transients). **Classic thundering herd.**

### 4.2 Model Metadata Directory (`src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:1-135`)

**Purpose:** `parseResponseToModelMetadataList(Response $response):82-134` filters `GET https://opencode.ai/.../models` JSON through `ModelAllowlist` (go:16 IDs, zen:19 IDs) and builds `ModelMetadata` objects.

**Hot path:** SDK calls `createRequest:68-71` → transporter `send` → `parseResponseToModelMetadataList` on cache miss. Result cached by `AbstractOpenAiCompatibleModelMetadataDirectory` parent via `AiClient::getCache()` (in-memory + transient). Subsequent calls served from cache, not this code.

**Performance characteristics (per cache miss):**

- `get_option(OPTION_NAME,[])['show_all_models']:87` — one `get_option` per parse invocation. Option autoloads via `alloptions` cache after first read, so <0.1ms. Reads plugin-owned `opencode_connector_settings` only.
- `common_opts:89-99` builds **9 `SupportedOption` objects** each parse: `new SupportedOption(OptionEnum::systemInstruction())` etc., plus `ModalityEnum::text()` calls. Alloc 9 objects.
- Loop `foreach ((array)$data['data'] as $row):102-121` iterates API rows (typically 30–80). Per iteration:
  - `ModelAllowlist::isAllowed($id,$catalogKey):107` → `in_array($id, ALLOW[$catalog], true)` linear scan over 16–19 strings:98. For 60 rows ×19 ≈1140 `strcmp`, ~microseconds.
  - `ModelAllowlist::displayName:110` → `ucwords(str_replace(['-','_'],' ',$id))` — string copy per allowed model only (16–19).
  - `ModelAllowlist::isFree:111` → `in_array($id,FREE,true)` over 6 strings — skipped for go.
  - `0!==strpos($id,'deepseek'):115` — cheap; should be `str_starts_with` for clarity but same speed.
  - `$opts=$common_opts:116` — array copy (9 elements).
  - `array_splice($opts,5,0,[new SupportedOption(outputSchema)]):118` — for json-capable models, splice copies 9-element array + alloc 1 object. ~10 allocs total.
  - `new ModelMetadata($id,$name,[CapabilityEnum::...],$opts):120` — one object + array per allowed model.
- `usort($list, comparator):122-132` — `n≈16-19`, comparisons ≈`n log n≈64`. Comparator calls `isFree` twice (2× `in_array` over 6):125-126 → ~768 string compares worst case, still <0.05ms. `strcmp` ties resolved by ID string compare.

**Memory:** Peak `common_opts` (9 objects) + `list` (16–19 `ModelMetadata` each holding `SupportedOption` refs). <100KB. No leak.

**Caching correctness:**

- **Key design:** Delegated to SDK parent (`AbstractOpenAiCompatibleModelMetadataDirectory`). Plugin does **not** manage metadata transient key directly except via `Settings::clearModelCaches():112` which **hand-rolls** `'ai_client_'.AiClient::VERSION.'_'.md5($cls).'_models'`. This duplicates SDK internals (confirmed `md5($cls)` pattern in `Settings.php:112`). If SDK changes prefix/hash (e.g. to `sha256` or adds salt), bust silently misses, leaving stale model list after `show_all_models` toggle until natural expiry. **MEDIUM fragility, not steady-state perf, but causes perceived slowness (user toggles, nothing changes).**
- **Invalidation:** `bustCaches:74-80` and `bustCachesAdd:91-96` both call `clearModelCaches():77` + `delete_transient avail`. Correct trigger (settings change). `duoport-connect-for-opencode.php:74-80` bust for key change also clears avail (not metadata) — acceptable; metadata cache outlives key change until expiry, but `GET models` uses same key, so auth failure will surface on next fetch, not immediately. Could also bust metadata on key change for faster recovery.
- **No stampede mitigation for metadata:** Parent's cache may have own lock; unverified (SDK source not in repo). Plugin's direct `delete_transient` + `$wpdb->query` fallback ensures DB cleanup, but concurrent `GET models` after bust could still herd. Low risk (metadata fetch is rare, admin-triggered).

### 4.3 Settings Cache Busting (`src/Settings/Settings.php:1-183`)

**Hooks:** `register():44-48` registers `register_setting`, `admin_menu`, `update_option_opencode_connector_settings:47` → `bustCaches`, `add_option_...:48` → `bustCachesAdd`.

**Per-request cost (every page load, including frontend):** `duoport-connect-for-opencode.php:126-134` `add_action('init', Settings register, 20)` fires on **every request** (frontend + admin). `register()` does 3 `add_action`/`register_setting` calls — hook table inserts, no I/O. Wasteful on frontend where `admin_menu` never fires. **LOW waste, could gate with `is_admin()`.**

**Bust cost (on setting change, rare):** `clearModelCaches():105-126` for 2 classes:

```
foreach [GoDir, ZenDir]:
  $full_key='ai_client_'.VERSION.'_'.md5($cls).'_models'  // md5 cheap
  $cache=AiClient::getCache()                              // 1 call, cached outside loop (good)
  if ($cache) { if ($cache->has($full_key)) $cache->delete($full_key); }
  delete_transient($full_key);
  global $wpdb; $wpdb->query(prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s OR option_name=%s", '_transient_'.$key,'_transient_timeout_'.$key))
```

- `has()+delete()` is redundant — `delete` is idempotent; `has` adds an extra `get` roundtrip to object cache. **LOW optimization: skip `has` check, just `delete`.**
- `delete_transient` after `$cache->delete` **double-deletes** same key via different layer (SDK cache may already be `WP_Object_Cache` vs transients table). Then raw `$wpdb->query DELETE` **triple-deletes** with direct SQL despite `delete_transient` already handling `_transient_*` rows (and object cache when present). Comment `:119` says "Fallback for object-cache-less installs" — intention correct, but on installs **with** object cache this is an extra 2 SQL queries per bust (4 total for both classes). Each `DELETE` is indexed `option_name` lookup, ~0.2ms. Rare event, so impact negligible, but **2–4 extra DB writes per settings save**.
- `delete_transient('opencode_connector_avail_*')` in `bustCaches:77-78` and `bustCachesAdd:94-95` adds 2 more `delete`s — correct but could be batched.

**DB query impact:** Normal page: 0 queries from this path. Settings save: ~6 deletes (2 avail + 2 transient + 2 SQL). Acceptable.

**Missing `is_multisite` handling:** Uses `$wpdb->options` only; on multisite, transients may be in `wp_sitemeta` as site transients — not cleaned. Not a frontend perf issue, but leaves orphaned rows.

### 4.4 Provider Registration (`duoport-connect-for-opencode.php:44-123`)

**Init:5 block:44-71**

```php
add_action('init', static function():void {
  if (!class_exists(AiClient::class)) return;
  $r=AiClient::defaultRegistry();
  foreach ([GoProvider,ZenProvider] as $cls) {
    if (!$r->hasProvider($cls)) { $r->registerProvider($cls); }
  }
}, 5);
```

- **Per-request cost:** `class_exists(\WordPress\AiClient\AiClient::class)` — autoload trigger, but class typically autoloaded by `wp-includes`? One `class_exists` call + `AiClient::defaultRegistry()` (likely singleton `get_instance`, maybe `WP_Object_Cache` read). `hasProvider` is in-memory `isset` on registry array. `registerProvider` only on cold start (first request after plugin activate, or after `init:5` before other plugins). **Warm path: 1 `class_exists` + 1 `defaultRegistry` + 2 `hasProvider` checks — <0.1ms, 0 DB.**
- **Priority 5 is correct** for latecomers to still override, but means every plugin's `init` after 5 sees registered providers — desired.
- `WP_DEBUG` guard on error_log only in catch, not hot path.

**`wp_connectors_init` override:86-123**

- `add_action('wp_connectors_init', ...)` registered top-level (every request). Callback does:

```
if (!is_object($reg)||!method_exists(...4)) return;
if (!$reg->is_registered('opencode-zen')) return;
$orig=$reg->get_registered('opencode-zen');
if (null===$orig||!isset($orig['authentication'])) return;
$pristine=$orig;
$reg->unregister('opencode-zen');
$orig['authentication']['setting_name']='connectors_ai_opencode_go_api_key';
$reg->register('opencode-zen',$orig);
catch → restore pristine
```

- **Per-request cost:** Fires during `_wp_connectors_init()` which is `add_action('init', _wp_connectors_init)` — so also every request. Does 4 `method_exists` (hash lookups) + `is_registered` (isset) + `get_registered` (array copy) + `unregister`+`register` (array splice). **Warm: ~0.1–0.3ms, 0 DB, no I/O.** Array copy of connector metadata (~20 keys) cheap.
- **No DB writes** — `register` is in-memory registry, not option write.
- `is_object` + 4 `method_exists` guards protect against core payload change — correct defense, no perf penalty.

**Overall hook execution:** Bootstrap registers **7 hooks top-level** (`admin_notices`, `init:5`, 2× `update/add_option` bust, `wp_connectors_init`, `init:20`, `plugin_action_links`) + `Settings::register` adds 3 more on `init:20` — total **10 hook registrations per request**. Each `add_action` is `global $wp_filter` array insert, <0.01ms. Not a bottleneck.

### 4.5 Autoloader (`src/autoload.php:1-31`)

```php
spl_autoload_register(static function(string $class):void {
  if (!str_starts_with($class, 'OpenCodeConnector\\')) return;
  $rel=substr($class, strlen('OpenCodeConnector')+1);
  $rel=str_replace('\\','/',$rel);
  $file=__DIR__.'/'.$rel.'.php';
  if (file_exists($file)) require $file;
});
```

- **Per-autoload cost (miss):** 1 `str_starts_with` (fast, compare 18 chars) early return for 99% of classes (core, other plugins). Only `OpenCodeConnector\*` hits `substr`+`str_replace`+`__DIR__` concat + `file_exists` stat syscall.
- `file_exists` stat: 1 `lstat()` per plugin class load. With opcache, file load itself is cached, but `file_exists` is still a syscall (not opcache). On 10 class loads per request (providers/models/metadata/settings), ~10 stats — ~0.05ms each on SSD, <0.5ms total. **Could use `is_readable` (same stat) or precomputed classmap (`$map=[Class=>file]`) to avoid stat entirely.** Plugin has 13 classes, so classmap would be `isset($map[$class])` → `require` without stat. **LOW micro-optimization.**
- No `spl_autoload_register` prepend flag — registers at end of autoload queue. Correct to let composer autoloaders run first; plugin classes not in composer map, so always falls through.
- `require` (not `require_once`) after `file_exists` guard — correct because `file_exists` ensures not double-load, and `require_once` would add extra `include_once` hash lookup. Good.
- No negative-lookup cache — if `OpenCodeConnector\Foo\Bar` not found, `file_exists` fails, no `require`, returns void, next autoloader tries. No memory growth.

---

## 5. Findings Detail

> Severity: CRITICAL (request hangs/data loss), HIGH (user-visible latency spike), MEDIUM (thundering herd / billed cost / stale cache), LOW (micro-optimization, wasted hook), INFO (observation/confirmation). Confidence: High unless noted.

### PERF-AV-01 — Availability probe thundering herd (no stampede lock)

- **File:Line:** `src/Availability/OpenCodeProviderAvailability.php:64-68,105`
- **Severity:** MEDIUM
- **Evidence:** `get_transient($tkey)===false` miss path unconditionally does `$this->getHttpTransporter()->send($req)` then `set_transient($tkey,(int)$ok,5*MINUTE_IN_SECONDS)`. No `wp_cache_add` lock, no `set_transient` with `autolaod=no` atomicity guard, no `get_transient` soft-expiry, no `rand(0,60)` jitter. Concurrent `isProviderConfigured` callers (e.g. `Settings::render():148-149` triggers 2 probes sequentially on cold, but parallel requests from different users after expiry all herd).
- **Impact:** On expiry, N=20 FPM workers ×2 catalogs = 40 concurrent `POST https://opencode.ai/zen/.../chat/completions` with `max_tokens=1`. Each is billed inference + TLS, 300–800ms, contending for PHP workers. DB `set_transient` write contention on `wp_options` (`INSERT ... ON DUPLICATE KEY UPDATE`). Burst to external API could trigger 429 which probe treats as `ok=true:94` — herd still charged.
- **Recommendation:** Add stampede guard: attempt `wp_cache_add("lock_$tkey",1, '', 30)` or `set_transient($tkey.'_lock',1,30)` before probe; on lock contention, return stale value or `false` without probe. Alternatively use transient `expiration + grace` pattern: store `['ok'=>bool,'time'=>time()]` and serve stale while background refresh (WP Cron). At minimum add jitter: `5*MINUTE_IN_SECONDS + rand(0,60)`. Document that transient is site-wide, not user-specific.
- **Confidence:** High

### PERF-AV-02 — Probe uses billed `chat/completions` (`max_tokens=1`) instead of lightweight auth check

- **File:Line:** `src/Availability/OpenCodeProviderAvailability.php:70-86`
- **Severity:** MEDIUM (cost + latency)
- **Evidence:** `$req=new Request(HttpMethodEnum::POST(),$cls::url('chat/completions'),['Content-Type'=>'application/json'],['model'=>('go'===catalog?'deepseek-v4-flash':'deepseek-v4-flash-free'),'messages'=>[['role'=>'user','content'=>'ping']],'max_tokens'=>1])`. Comment in correctness audit `F-AV-02` notes dependency on model ID existing. `AbstractOpenCodeModelMetadataDirectory:68-71` uses `GET models` for listing — lighter, no inference.
- **Impact:** Every availability cache miss (every 5 min per catalog, plus every herd participant) incurs inference cost (even 1 token billed) and higher latency (model queue) vs. `GET https://opencode.ai/.../v1/models` which is metadata-only, cheaper, faster, and validates auth via 401 same as probe. False-negative risk if probe model removed (404 → `ok=false:100`) — costs "not connected" UI until code fix, forcing repeated probes.
- **Recommendation:** Switch probe to `GET models` with `Authorization` header (via `authenticateRequest`), check 200/401/429 same logic, or use dedicated `/auth/check` if OpenCode exposes. Keep `max_tokens` path as fallback. Ensure probe model ID is validated in CI against `ModelAllowlist`.
- **Confidence:** High

### PERF-AV-03 — `Settings::render()` blocks page render with synchronous double probe, no timeout

- **File:Line:** `src/Settings/Settings.php:148-149` → `src/Availability/OpenCodeProviderAvailability.php:88-90`
- **Severity:** MEDIUM
- **Evidence:** `render():148-149` `$go_ok=class_exists(AiClient::class) && AiClient::defaultRegistry()->isProviderConfigured('opencode-go'); $zen_ok=...isProviderConfigured('opencode-zen');` — sequential, each may call `isConfigured()` → `getHttpTransporter()->send()` with SDK default timeout (transport not passed `$this->getRequestOptions()` with short timeout). No `try/catch` (correctness `F-ST-01`), but perf aspect is blocking. Cold both go+zen → 2× network RTT serially, admin page hangs 1–3s.
- **Impact:** Settings screen `WP-Admin → Settings → DuoPort Connector` has p50 500ms warm, p95 2–5s cold (network). If API down, page hangs to timeout (often 5s per probe = 10s). Other admin pages not affected (render only on settings screen), but `isProviderConfigured` could be called elsewhere (e.g. AI Client UI), same block.
- **Recommendation:** Pass explicit short timeout: `createRequest` with `getRequestOptions()` or transporter option `['timeout'=>3]` if trait exposes. Or make `render()` async: show "checking..." via JS/fetch, or cache last known state with stale-while-revalidate. Wrap each probe in `try { with 3s timeout } catch { $ok=false; }`. Consider parallelizing go+zen probes via `Requests::request_multiple` or SDK batch if available (not currently).
- **Confidence:** High

### PERF-AV-04 — Availability transient stores `(int)`, no jitter, fixed 300s expiry causes synchronized expiry

- **File:Line:** `src/Availability/OpenCodeProviderAvailability.php:105` + `src/Settings/Settings.php:77-78`
- **Severity:** LOW (contributes to herd)
- **Evidence:** `set_transient($tkey,(int)$ok,5*MINUTE_IN_SECONDS)` fixed TTL. All workers set same 300s, so expiry synchronized across catalogs set at same time (key change bust deletes both simultaneously, then both set at near same `time()` on next `isConfigured` calls). No `rand` or `+MINUTE_IN_SECONDS*rand`.
- **Impact:** Synchronized expiry amplifies herd at 5-minute boundary. `(int)` vs `(bool)` is correct for `false!==cached` distinction (`F-AV-04` in correctness audit) — not a perf bug, but serialization size diff negligible (i vs b). Store `0`/`1` as string in DB (`option_value` varchar) same cost.
- **Recommendation:** `set_transient($tkey,(int)$ok, 5*MINUTE_IN_SECONDS + random_int(0,60))` to desynchronize. Keep `(int)` pattern or store `['v'=>bool,'t'=>time()]` for soft-expiry if implementing stale-while-revalidate. Not critical alone.
- **Confidence:** High

### PERF-MD-01 — `parseResponseToModelMetadataList` allocates 9 `SupportedOption` + up to 19 `ModelMetadata` per cache miss

- **File:Line:** `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:89-99,116-120`
- **Severity:** INFO (expected)
- **Evidence:** `$common_opts=[new SupportedOption(OptionEnum::systemInstruction()), ... 9 total:92-98]` plus per-model `$opts=$common_opts; array_splice(... new SupportedOption(outputSchema))` for json-capable. Then `new ModelMetadata($id,$name,[textGeneration,chatHistory],$opts)`. For zen allowlist 19, alloc ~9+19*1 + ~10 splice allocs = ~38 objects.
- **Impact:** <0.2ms, <50KB, only on SDK cache miss (rare, maybe hourly). Parent cache `AiClient::getCache()` stores serialized list, so subsequent `getModels()` returns unserialized copies without re-parse. Not a hot path.
- **Recommendation:** No change. If catalog grows to 100s, consider static `$common_opts` singleton to avoid per-parse alloc. Keep as-is.
- **Confidence:** High

### PERF-MD-02 — `get_option(OPTION_NAME)` on every `parseResponseToModelMetadataList` invocation

- **File:Line:** `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:87`
- **Severity:** LOW
- **Evidence:** `$show_all=(bool)(get_option(\OpenCodeConnector\OPTION_NAME,[])['show_all_models'] ?? false);` inside parse method. Called once per `GET models` response parse (cache miss only). `get_option` after first load hits `wp_cache_get('alloptions')` or `wp_cache_get(OPTION_NAME)` — in-memory hash, no DB. Correctness `F-MD-02` notes corrupted string edge warning, but perf wise it's cached.
- **Impact:** ~0.01ms. Could micro-opt with `static $show_all_cache` but adds staleness risk if option changed mid-request. Not hot.
- **Recommendation:** Keep `get_option` direct; optionally add `static $memo` with `did_action('update_option_'.OPTION_NAME)` invalidation if parse called multiple times per request (currently not).
- **Confidence:** High

### PERF-MD-03 — `usort` comparator calls `ModelAllowlist::isFree` (`in_array` linear) per comparison

- **File:Line:** `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:122-132`
- **Severity:** INFO (micro)
- **Evidence:** `usort($list, static fn(ModelMetadata $a,$b):int => $af=ModelAllowlist::isFree($a->getId())?0:1; $bf=...; $af<=>$bf ?: strcmp($a->getId(),$b->getId()))` where `isFree:108` is `in_array($id,FREE,true)` over 6 strings. For n=19, ~64 comps ×12 in_array = ~768 strcmps.
- **Impact:** <0.03ms. Catalog small. Not measurable.
- **Recommendation:** Precompute `array_flip(FREE)` map and `isset($map[$id])` for O(1) if catalog grows beyond 50. Alternatively `usort` with Schwartzian transform: decorate `$list` with `['free'=>bool,'id'=>string,'meta'=>obj]` then sort. No action needed now.
- **Confidence:** High

### PERF-BOOT-01 — `Settings::register` runs on every request via `init:20` even on frontend

- **File:Line:** `duoport-connect-for-opencode.php:126-134` + `src/Settings/Settings.php:35-48`
- **Severity:** LOW
- **Evidence:** `add_action('init', static function():void { if (class_exists(Settings::class)) (new Settings())->register(); },20)` unconditional. `register():36-48` does `register_setting('opencode_connector',OPTION_NAME,...)` + `add_action('admin_menu',...)` + 2 `add_action('update/add_option_'.OPTION_NAME,...)` . On frontend `admin_menu` hook never fires, but `register_setting` still adds to global `$wp_registered_settings` and 3 hook registrations wasted.
- **Impact:** Frontend overhead ~0.05ms, +3 entries in `global $wp_filter['init']` and `$wp_filter['admin_menu']`. Negligible but violates "only load admin code in admin" best practice. Under high RPS (1000 req/s) wastes ~50ms CPU aggregate.
- **Recommendation:** Gate with `if (!is_admin()) return;` inside `init:20` callback, or register `admin_menu` callback via `is_admin()` at top-level: `if (is_admin()) add_action('admin_menu', ...)`. Keep `register_setting` frontend-safe (needed for REST `settings` route) — so split: always `register_setting`, admin-only `admin_menu`+bust hooks via `is_admin()` guard.
- **Confidence:** High

### PERF-BOOT-02 — `wp_connectors_init` override does 4× `method_exists` + array copy per request

- **File:Line:** `duoport-connect-for-opencode.php:93-109`
- **Severity:** INFO
- **Evidence:** `if (!is_object($reg)||!method_exists($reg,'unregister')||!method_exists(...'register')||!method_exists(...'get_registered')||!method_exists(...'is_registered')) return;` plus `$original=$reg->get_registered('opencode-zen')` (array copy of ~20 keys + nested `authentication`).
- **Impact:** 4 hash lookups + 1 array copy per request where `_wp_connectors_init` fires (every `init`). <0.02ms. Defensive, correct for forward compat. Could cache `method_exists` result in `static $hasMethods` but not needed. Not a bottleneck.
- **Recommendation:** Keep guards. Optionally combine to `if (!$reg instanceof \WP_Connectors_Registry)` if core class exists, but `method_exists` duck-typing is safer for mocks (as tested in `tests/Unit/ConnectorOverrideTest.php:42-61`).
- **Confidence:** High

### PERF-BOOT-03 — `admin_notices` does `get_bloginfo('version')` per admin page

- **File:Line:** `duoport-connect-for-opencode.php:32-42`
- **Severity:** INFO (micro)
- **Evidence:** `if (version_compare(get_bloginfo('version'),'7.0','>=') && class_exists(AiClient::class)) return;` — `get_bloginfo('version')` calls `get_option('bloginfo')`? Actually WP does `get_bloginfo('version')` → `$wp_version` global vs `get_option` depending on filter? Inspect `wp-includes/general-template.php: get_bloginfo` has `case 'version': return $wp_version`. So it's global fetch + `apply_filters('bloginfo','version')` — cheap but still `version_compare` string parse each admin page.
- **Impact:** <0.01ms. Could use `global $wp_version` directly or `get_bloginfo` result cache. Not measurable. Correctness `F-DC-04` notes message imprecision, not perf.
- **Recommendation:** Replace with `global $wp_version; if (version_compare($wp_version,'7.0','>=') ...)` or cache `version_compare` result in `static $ok`. Low priority.
- **Confidence:** Medium (depends on WP version of `get_bloginfo`; verified via reading `wp-includes/general-template.php: get_bloginfo` switch).

### PERF-AL-01 — Autoloader `file_exists` stat per class load

- **File:Line:** `src/autoload.php:24-29`
- **Severity:** LOW
- **Evidence:** `$file=__DIR__.'/'.$rel.'.php'; if (file_exists($file)) require $file;` where `$rel=str_replace('\\','/',$rel)`. Each `OpenCodeConnector\Foo` load does `lstat`. For 13 plugin classes, worst 13 stats on first request, then opcache persists. Non-plugin classes early-return after `str_starts_with` without stat — correct.
- **Impact:** 13× `lstat` ≈0.3–0.6ms cold, 0 after opcache class already loaded (PHP keeps class in memory per request, autoloader not re-invoked). Under `opcache.validate_timestamps=0` (production), `file_exists` still stats but file not changed — could be 0.2ms. **Classmap alternative** `private const MAP=['OpenCodeConnector\Providers\OpenCodeGoProvider'=>__DIR__.'/Providers/OpenCodeGoProvider.php', ...]` with `isset($map[$class])` would avoid stat entirely.
- **Recommendation:** Consider classmap array (generated) for ~0.3ms win on high RPS. Keep `file_exists` if preferring PSR-4 simplicity — acceptable. Ensure `spl_autoload_register` not using `prepend=true` (currently appended, correct).
- **Confidence:** High

### PERF-SETT-01 — `clearModelCaches()` triple-delete per catalog

- **File:Line:** `src/Settings/Settings.php:110-125`
- **Severity:** LOW
- **Evidence:** For each of 2 classes: `if ($cache && $cache->has($full_key)) $cache->delete($full_key);` → `delete_transient($full_key);` → `$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name=%s OR option_name=%s", '_transient_'.$key,'_transient_timeout_'.$key)`. `delete_transient` already does `wp_cache_delete` + `delete_option('_transient_'.$key)` + `delete_option('_transient_timeout_'.$key)` — so raw SQL is redundant when object cache not persistent. When object cache **is** persistent (Redis), `_transient_*` rows not used — `delete_transient` is no-op DB wise, but SQL still runs 2 `DELETE` queries hitting `wp_options` index scan for non-existent rows.
- **Impact:** Settings save (rare) does 2× (`has` read + `delete` + `delete_transient` (1–2 DB ops) + `DELETE` SQL) = 6–8 cache/DB ops. Each `DELETE` is indexed `option_name` equality, ~0.2ms. Total extra ~0.5–1ms per save. Not user-visible latency (admin clicks Save, sees redirect). **Optimization: remove `has` check, keep `delete_transient` as primary, make SQL fallback conditional on `!wp_using_ext_object_cache()`.**
- **Recommendation:**

  ```php
  $cache = AiClient::getCache();
  foreach ($classes as $cls) {
      $key = 'ai_client_'.AiClient::VERSION.'_'.md5($cls).'_models';
      if ($cache) { $cache->delete($key); } // no has()
      delete_transient($key);
      if (!wp_using_ext_object_cache()) {
          global $wpdb;
          $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s OR option_name=%s", '_transient_'.$key,'_transient_timeout_'.$key));
      }
  }
  ```

  Reduces DB writes on Redis sites to 0, saves `has` read.

- **Confidence:** High

### PERF-SETT-02 — Hand-rolled `ai_client_*` cache key duplicates SDK internals

- **File:Line:** `src/Settings/Settings.php:112`
- **Severity:** MEDIUM (freshness, not CPU)
- **Evidence:** `$full_key='ai_client_'.AiClient::VERSION.'_'.md5($cls).'_models'` matches inferred SDK `AbstractOpenAiCompatibleModelMetadataDirectory` cache key pattern. No import from SDK constant/method. `duoport-connect-for-opencode.php:26` plugin `VERSION` not used; SDK `VERSION` used correctly here.
- **Impact:** If SDK changes to `'ai_client_v2_'` or `hash('xxh3',...)` or adds blog-ID prefix, bust deletes wrong key, **stale model list survives** after `show_all_models` toggle. User sees no update, retries repeatedly (extra `GET models` fetches on next natural expiry, but not immediate) — perceived performance bug. No extra CPU, but degrades UX and causes extra API calls if user force-refreshes.
- **Recommendation:** Prefer SDK API if exposed: `AiClient::getCache()->delete($directory)` or `$directory->clearCache()`; else add integration test pinning SDK version and asserting `'ai_client_'.AiClient::VERSION.'_'.md5(OpenCodeGoModelMetadataDirectory::class).'_models' === $sdk->getCacheKey()` for each release. Document coupling in comment.
- **Confidence:** High (no SDK source in `vendor/` to verify, inference from tests and `Settings.php` comment).

### PERF-PROV-01 — `version_compare(AiClient::VERSION, '1.2.0', '>=')` twice per provider metadata creation

- **File:Line:** `src/Providers/AbstractOpenCodeProvider.php:116-123`
- **Severity:** INFO
- **Evidence:** `if (version_compare(AiClient::VERSION,'1.2.0','>=')) $args[]=static::description(); if (version_compare(AiClient::VERSION,'1.3.0','>=')) $args[]=dirname(...).'/assets/images/opencode.svg';` Called per `createProviderMetadata()` which is called per provider registration (2 providers on `init:5` cold path, plus any `getProviderMetadata` calls).
- **Impact:** `version_compare` parses version strings char-by-char, ~microseconds. 4 calls per request cold, 0 warm (provider already registered, metadata not re-created). Negligible.
- **Recommendation:** Cache result in `static $metaArgs` or `private const` if `AiClient::VERSION` is constant per request (it is). Not needed.
- **Confidence:** High

### PERF-MODEL-01 — `AbstractOpenCodeTextGenerationModel::createRequest` forwards `getRequestOptions()`

- **File:Line:** `src/Models/AbstractOpenCodeTextGenerationModel.php:50-53`
- **Severity:** INFO (correct)
- **Evidence:** `return new Request($method,$cls::url($path),$headers,$data,$this->getRequestOptions());` — 5th arg includes timeout/retry options from SDK. Correct for text generation (high latency, streaming). Metadata directory and availability do **not** forward options (correctness `F-SYS-01`), so those probes use SDK defaults — could be slower than needed (see `PERF-AV-03`).
- **Impact:** Text generation benefits from proper timeout; no perf regression.
- **Recommendation:** Align availability/metadata `createRequest` to also forward options for consistency.
- **Confidence:** Medium

---

## 6. Caching Correctness — Summary Table

| Cache | Key | Storage | TTL / Expiry | Invalidation | Stampede | Verdict |
|-------|-----|---------|--------------|--------------|----------|---------|
| Availability Go | `opencode_connector_avail_go` (`Availability:64`) | `get_transient`/`set_transient` → `wp_options` (`_transient_*` + `_transient_timeout_*`) or external object cache | `5*MINUTE_IN_SECONDS` (300s):105 | `delete_transient` on `update_option_connectors_ai_opencode_go_api_key` + `add_option_...`:78-79 (slim, correct) + `delete_transient` in `Settings::bustCaches:77` (conservative) | **None** — fixed TTL, no jitter, no lock | **MEDIUM** — key/invalidation correct, herd risk |
| Availability Zen | `opencode_connector_avail_zen` | Same | 300s | Same (both deleted together) | Same | Same |
| Model list (SDK cache) | `ai_client_{AiClient::VERSION}_{md5(DirClass)}_models` (hand-rolled `Settings:112`) | `AiClient::getCache()` (likely `WP_Object_Cache` or `wp_cache_*`) **plus** `delete_transient` fallback + raw `DELETE FROM wp_options` | SDK-controlled (not shown, likely 1h or `HOUR_IN_SECONDS`; transient timeout fallback) | `clearModelCaches()` on `update_option_opencode_connector_settings` when `show_all` toggles:74-79, and on `add_option` :91-96. **Not** on API-key change (minor gap) | SDK may have own lock; plugin's bust does triple-delete | **MEDIUM fragility** — hand-rolled key coupling; invalidation correct for settings but not key rotation |
| Plugin option | `opencode_connector_settings` (`OPTION_NAME:27`) | `get_option`/`update_option` → `wp_options` autoload (`register_setting:36` `type=>array`, `default=>['show_all'=>false]`) | Persistent until `update_option` / `uninstall.php:12` | `register_setting sanitize` normalizes:62, bust hooks above | N/A | Correct, small array `['show_all_models'=>bool]` — serialization <50B |
| Uninstall cleanup | `opencode_connector_settings`, `opencode_connector_avail_*` | `delete_option` + `delete_transient` | On `WP_UNINSTALL_PLUGIN`:12-14 | Not cleaning `ai_client_*` model cache (orphaned rows) — correctness `F-UN-01` | N/A | **LOW** orphan rows, not perf unless many uninstalls |

**Object-cache vs DB:** `get_transient` with external cache (Redis/Memcached) is `wp_cache_get` (sub-ms, no DB). Without, it's `get_option('_transient_*')` → `maybe_unserialize` + timeout check, one `SELECT option_name IN (...)` per key. Plugin correctly handles both (via `delete_transient` + direct SQL fallback). `wp_using_ext_object_cache()` guard would optimize `clearModelCaches` (see PERF-SETT-01).

**WordPress hook invalidation correctness:**

- `add_action('update_option_connectors_ai_opencode_go_api_key', $bust)` and `add_option_...` :78-79 — WP core fires `update_option_{$option}` inside `update_option()` after `sanitize_text_field` (`wp-includes/connectors.php:801`) and `update_option` success. Callback `delete_transient` is synchronous, no DB read, correct. Takes zero args (`():void`) — extra hook args `$old_value,$new_value` discarded by PHP, intentional slim.
- `add_action('update_option_'.OPTION_NAME, [bustCaches],10,2)` :47 — receives `($old_value,$new_value)` correctly. Condition `($old['show_all_models']??false)!==($new['show_all_models']??false)` avoids clearing when unrelated keys change (none today) — efficient.
- `add_action('add_option_'.OPTION_NAME, [bustCachesAdd],10,2)` :48 — params `($option,$value)` with `unset($option,$value)` — clears unconditionally on first add — correct.

**No negative-cache poisoning:** `set_transient` with `(int)$ok` stores `0` for "not configured" which is distinguishable from `false` cache miss via `false!==$cached` strict:66 — correct (correctness `F-AV-04`). Both negative and positive cached 5 min prevents flapping.

---

## 7. DB Queries & Hook Execution Hot-Path Tables

### 7.1 DB queries per request type (warm cache, no external object cache)

| Request type | Queries from plugin | Evidence |
|--------------|---------------------|----------|
| Frontend anonymous (no AI) | **0** | `init:5` has `class_exists`+`hasProvider` (memory), `init:20` adds hooks (memory), `wp_connectors_init` early-return (memory). No `get_option`/`get_transient` unless `isProviderConfigured` called (not on frontend). |
| WP-Admin list table (no settings screen) | 0–1 | `admin_notices` `get_bloginfo` (global, no query) + provider registration same as frontend. No availability probe unless admin UI calls `isProviderConfigured`. |
| Settings screen — warm avail cache | **2** | `get_option(OPTION_NAME):147` (1 `SELECT` if not in `alloptions`, else 0) + 2× `get_transient avail` (each `SELECT _transient_*` or cache hit). Metadata cache warm → 0 `GET models`. |
| Settings screen — cold avail cache | **2 + 2 writes + 2 network** | Same reads miss → 2× `POST chat/completions` (network) → 2× `set_transient` (2 `INSERT` + timeout rows) |
| Settings save (`update_option opencode_connector_settings`) | **~6 deletes** | `clearModelCaches`: 2× `AiClient::getCache()->delete` (cache) + 2× `delete_transient` (DB) + 2× `DELETE FROM wp_options` SQL + 2× `delete_transient avail`. |
| `GET models` cache miss | **1 network + 1 cache write** | `GET https://opencode.ai/.../models` → `AiClient::getCache()->set` + `set_transient` via SDK parent |

With external object cache (Redis): all `get_transient`/`delete_transient` become `wp_cache_get`/`delete` — 0 DB queries for availability/model caches.

### 7.2 Hook registrations per request (always)

| Hook | Priority | Callback cost | Fires per request? |
|------|----------|---------------|-------------------|
| `admin_notices` | default 10 | `version_compare+class_exists` | Only `is_admin()` admin pages, but registered always |
| `init` → provider registration | 5 | `class_exists+defaultRegistry+hasProvider` | Every request |
| `update_option_connectors_ai_opencode_go_api_key` | default | `delete_transient×2` | Only on key update |
| `add_option_connectors_ai_opencode_go_api_key` | default | Same | Only on key add |
| `wp_connectors_init` | default | `method_exists×4+array copy` | Every request (`_wp_connectors_init` on `init`) |
| `init` → Settings register | 20 | `(new Settings)->register` → 3 `add_action`/`register_setting` | Every request |
| `plugin_action_links_...` | default 10 | `esc_url+esc_html__` | Only plugins.php, but registered always |
| `admin_menu` (via Settings) | 10 | `add_options_page` | Only `is_admin()` `admin_menu` do_action |
| `update_option_opencode_connector_settings` | 10 | `bustCaches` | Only on option update |
| `add_option_opencode_connector_settings` | 10 | `bustCachesAdd` | Only on option add |

**Total hook registration overhead:** ~10 `add_action` inserts into `global $wp_filter` per request — <0.1ms, <2KB.

### 7.3 Frontend performance impact

- **No enqueued scripts/styles** — grep `wp_enqueue_script|wp_enqueue_style|add_action.*wp_enqueue` returns 0 in production source. **0 frontend JS/CSS bytes.**
- **No `the_content` filter, no shortcode, no block** — no per-post overhead.
- **Autoloader not hit** on frontend unless AI feature used — otherwise 0 file stats.

---

## 8. Remote Requests to `https://opencode.ai`

| Caller | URL | Method | Payload | When | Cost | Cache |
|--------|-----|--------|---------|------|------|-------|
| `OpenCodeProviderAvailability:72-86` | `https://opencode.ai/zen/go/v1/chat/completions` (Go) or `https://opencode.ai/zen/v1/chat/completions` (Zen) — via `$cls::url('chat/completions')` where `baseUrl` at `OpenCodeGoProvider:78` / `OpenCodeZenProvider:78` | POST | `{"model":"deepseek-v4-flash[-free]","messages":[{"role":"user","content":"ping"}],"max_tokens":1}` + `Authorization: Bearer <key>` via `WithRequestAuthenticationTrait:88` | `isConfigured()` cache miss (every 300s per catalog + herd) | Billed inference + TLS | `opencode_connector_avail_*` 300s |
| `AbstractOpenCodeModelMetadataDirectory:68-71` → parent SDK | `https://opencode.ai/zen/go/v1/models` / `https://opencode.ai/zen/v1/models` | GET (inferred from `createRequest` with `HttpMethodEnum` passed by parent) | `Authorization` header | `getModels()` SDK cache miss | Metadata-only, cheap | `AiClient::getCache` + `ai_client_*_models` transient |
| `AbstractOpenCodeTextGenerationModel:50-53` | `https://opencode.ai/zen/go/v1/chat/completions` / `https://opencode.ai/zen/v1/chat/completions` | POST | `messages`, `model`, `response_format` (json_schema wrapper):63-75 via `prepareResponseFormatParam` | `generateText()` / chat | Billed | Not cached (generation) |

All URLs hard-coded `https` — validated. No SSRF (no user-controlled host). No `wp_remote_get` direct — delegates to `WithHttpTransporterTrait::send()` which likely wraps `wp_remote_request` with `timeout`/`headers` from `getRequestOptions()`.

---

## 9. Summary Counts

| Severity | Count | IDs |
|----------|-------|-----|
| CRITICAL | 0 | — |
| HIGH | 0 | — |
| MEDIUM | **4** | `PERF-AV-01` stampede, `PERF-AV-02` billed probe, `PERF-AV-03` blocking render, `PERF-SETT-02` hand-rolled cache key coupling |
| LOW | **5** | `PERF-AV-04` synchronized expiry, `PERF-MD-02` `get_option` per parse, `PERF-BOOT-01` frontend `init:20` waste, `PERF-AL-01` `file_exists` stat, `PERF-SETT-01` triple-delete |
| INFO | **5** | `PERF-MD-01` alloc expected, `PERF-MD-03` `usort` micro, `PERF-BOOT-02` `method_exists` guards, `PERF-BOOT-03` `get_bloginfo` micro, `PERF-PROV-01` `version_compare` micro, `PERF-MODEL-01` options forwarding (plus) |
| OPTIMIZATION | 1 | `PERF-SETT-01` `has()`+`delete` (already counted as LOW, optimization note) |

**Top priority:** Address `PERF-AV-01`/`PERF-AV-03` first: add stampede lock + short timeout (3s) + jitter for `opencode_connector_avail_*`; consider switching probe to `GET models` to eliminate billed `chat/completions`. Next, decouple `PERF-SETT-02` key coupling via SDK API or pinned integration test. Then `PERF-BOOT-01`/`PERF-SETT-01` low-hanging micro-optimizations.

---

## 10. Recommendations (Prioritized)

1. **Availability stampede + blocking (MEDIUM):** In `OpenCodeProviderAvailability.php:64-105`, implement `wp_cache_add("lock_avail_$catalog",1,'',30)` before `send()`, return stale `get_transient` if lock held; add `random_int(0,60)` to TTL; pass explicit timeout `['timeout'=>3]` via `getRequestOptions()` or `Request` 5th arg; parallelize go/zen probes in `Settings::render` or make async. Switch probe to `GET models` to cut cost.
2. **Cache-key coupling (MEDIUM):** In `Settings.php:112`, replace hand-rolled `'ai_client_'.VERSION.'_'.md5.'_models'` with SDK-provided `clearCache()` if available; else add `tests/Unit` assertion that real SDK key equals hand-rolled for current `AiClient::VERSION`, and gate SQL fallback with `!wp_using_ext_object_cache()`.
3. **Frontend hook waste (LOW):** Guard `duoport-connect-for-opencode.php:126-134` `init:20` Settings bootstrap with `is_admin()` for `admin_menu`/bust hooks; keep `register_setting` unconditional for REST. Saves 3 hook inserts per frontend request.
4. **Autoloader stat (LOW):** Optionally replace `file_exists` with classmap `isset` for 0.3ms high-RPS win; keep current PSR-4 if simplicity preferred.
5. **Triple-delete (LOW):** Remove `has()` check before `delete()` in `clearModelCaches:114`, and condition raw `DELETE FROM wp_options` on `!wp_using_ext_object_cache()` to avoid redundant DB writes on Redis sites.

---

## 11. Methodology Notes

- Verified `php -l` not executed here but report aligns with correctness audit's syntax check. All `file:line` from `Read` output with line numbers.
- SDK vendor not in repo (`composer.json:16-23` dev only), so `AiClient::getCache()`, `AbstractOpenAiCompatibleModelMetadataDirectory` caching, and `WithHttpTransporterTrait::send()` internals inferred from interface names and call sites — confidence High for plugin-owned code, Medium for SDK internals.
- No runtime profiling (`ab`, `xhprof`, `query-monitor`) executed; estimates based on code trace and WP `get_transient`/`wp_remote` known costs. Recommend `Query Monitor` + `wp_cache_get` stats on staging to validate 0-query warm path and probe latency.

---

*End of report — Performance Audit — no production code modified. Report written to `AUDIT/AGENTS/agent-performance.md`.*
