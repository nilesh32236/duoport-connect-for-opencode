# PHP Correctness Audit — DuoPort Connect for OpenCode

**Agent:** agent-php-correctness (PHP Correctness Specialist)
**Date:** 2026-08-28
**Scope:** Production PHP only — 15 files, line-by-line trace. No production code modified.
**Methodology:** Read every file completely, traced signatures/return types/`strict_types`/Throwable handling/edge cases/WordPress AI Client SDK usage. Skimmed `tests/` for context.

---

## 1. Files Reviewed

| # | File | Lines | `strict_types` | `ABSPATH` Guard | Purpose |
|---|------|-------|----------------|-----------------|---------|
| 1 | `duoport-connect-for-opencode.php` | 1–144 | Yes `:18` | Yes `:22–24` | Plugin bootstrap, provider registration, connector override, settings bootstrap, action link |
| 2 | `src/autoload.php` | 1–31 | Yes `:11` | Yes `:15–17` | PSR-4 autoloader for `OpenCodeConnector\` |
| 3 | `src/Availability/OpenCodeProviderAvailability.php` | 1–108 | Yes `:11` | Yes `:15–17` | Probe-based `isConfigured()` with transient cache |
| 4 | `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php` | 1–135 | Yes `:11` | Yes `:15–17` | Filtered model-directory, allowlist, sorting |
| 5 | `src/Metadata/ModelAllowlist.php` | 1–125 | Yes `:11` | Yes `:15–17` | Hard-coded allowlist + free labeling + display name |
| 6 | `src/Metadata/OpenCodeGoModelMetadataDirectory.php` | 1–49 | Yes `:11` | Yes `:15–17` | Go directory (providerClass/catalogKey) |
| 7 | `src/Metadata/OpenCodeZenModelMetadataDirectory.php` | 1–49 | Yes `:11` | Yes `:15–17` | Zen directory (providerClass/catalogKey) |
| 8 | `src/Models/AbstractOpenCodeTextGenerationModel.php` | 1–76 | Yes `:11` | Yes `:15–17` | Chat-completions model + `prepareResponseFormatParam` |
| 9 | `src/Models/OpenCodeGoTextGenerationModel.php` | 1–38 | Yes `:11` | Yes `:15–17` | Go model binding |
| 10 | `src/Models/OpenCodeZenTextGenerationModel.php` | 1–38 | Yes `:11` | Yes `:15–17` | Zen model binding |
| 11 | `src/Providers/AbstractOpenCodeProvider.php` | 1–148 | Yes `:11` | Yes `:15–17` | Shared provider base (model factory, metadata, availability) |
| 12 | `src/Providers/OpenCodeGoProvider.php` | 1–80 | Yes `:11` | Yes `:15–17` | Go provider (ID/catalog/name/URL) |
| 13 | `src/Providers/OpenCodeZenProvider.php` | 1–80 | Yes `:11` | Yes `:15–17` | Zen provider (ID/catalog/name/URL) |
| 14 | `src/Settings/Settings.php` | 1–183 | Yes `:11` | Yes `:15–17` | Settings registration, sanitization, cache-bust, admin UI |
| 15 | `uninstall.php` | 1–14 | No | `WP_UNINSTALL_PLUGIN` `:9–11` | Option/transient cleanup on uninstall |

Tests skimmed for SDK usage context: `tests/bootstrap.php` (16), `tests/Unit/MonkeyTestCase.php` (41), `tests/Unit/SmokeTest.php` (46), `tests/Unit/ConnectorOverrideTest.php` (279), `tests/Unit/SlimBustHooksTest.php` (128), `tests/Unit/LegacyApprovalSourceTest.php` (54).

---

## 2. Functionality Reviewed

- **Plugin lifecycle:** `ABSPATH`/`WP_UNINSTALL_PLUGIN` guards, version-gated admin notice (`duoport-connect-for-opencode.php:32-42`), dual `init` hooks for `AiClient::defaultRegistry()->registerProvider()` (`:44-71`) and `Settings::register()` (`:126-134`), transient bust on shared-key option (`:74-80`), `wp_connectors_init` connector-metadata override sharing single API key (`:86-123`), plugin action link (`:137-144`).
- **Autoloading:** PSR-4 via `spl_autoload_register` (`autoload.php:19-30`).
- **Availability:** HTTP probe to `chat/completions` with `max_tokens=1`, transient caching, status-code interpretation (`Availability:70-106`).
- **Model metadata:** `createRequest()` delegating to `Provider::url()` (`Abstract...Directory:68-71`), `parseResponseToModelMetadataList()` filtering via `ModelAllowlist`, free-labeling, `outputSchema` suppression for `deepseek`, sorting free-first (`:82-133`).
- **Allowlist:** `ALLOW`/`FREE` constants, `isAllowed()`/`isFree()`/`displayName()` (`ModelAllowlist:31-124`).
- **Model execution:** `createRequest()` with `getRequestOptions()` (`Abstract...Model:50-53`), `prepareResponseFormatParam()` wrapping SDK bare schema into OpenCode `json_schema` envelope (`:63-75`).
- **Providers:** `AbstractOpenCodeProvider` factories (`createModel:87-99`, `createProviderMetadata:108-123` with `AiClient::VERSION` gates, `createProviderAvailability:132-134`, `createModelMetadataDirectory:143-147`), concrete providers' `providerId`/`catalogKey`/`displayName`/`description`/`baseUrl` (`OpenCodeGoProvider:33-79`, `OpenCodeZenProvider:33-79`).
- **Settings:** `register_setting`/`sanitize`/`bustCaches`/`bustCachesAdd`/`clearModelCaches`/`menu`/`render` (`Settings.php:35-182`), including `AiClient::getCache()` + transient + direct `$wpdb` fallback for `ai_client_*` model caches.
- **Uninstall:** `delete_option` + `delete_transient` for plugin-owned keys (`uninstall.php:12-14`).
- **SDK contracts checked:** `ProviderAvailabilityInterface`, `WithHttpTransporterInterface`/`WithRequestAuthenticationInterface` + traits, `AbstractOpenAiCompatibleModelMetadataDirectory`, `AbstractOpenAiCompatibleTextGenerationModel`, `AbstractApiProvider`, `ProviderMetadata`, `Request`/`Response` DTOs, `RequestAuthenticationMethod`, `AiClient::defaultRegistry()/getCache()/VERSION`.

---

## 3. Findings

> Severity scale — CRITICAL (data loss/security/fatal), HIGH (correctness failure, user-visible bug), MEDIUM (edge-case error, degraded correctness, fragile contract), LOW (minor correctness/style, defensive improvement), INFO (observation/confirmation), OPTIMIZATION (perf), DUPLICATE (copy-paste), DEAD CODE (unreachable/unused).

### 3.1 `duoport-connect-for-opencode.php`

#### F-DC-01 — Outer registry exception swallows context, logs only message
- **File:Line:** `duoport-connect-for-opencode.php:64-68`
- **Category:** Error Handling
- **Severity:** LOW
- **Problem:** Outer `catch (\Throwable $e)` logs only `$e->getMessage()` without class/trace. Inner per-provider catch does the same. Debugging a corrupted core SDK (e.g. `TypeError` in `AiClient::defaultRegistry()`) loses stack.
- **Evidence:** `error_log('[duoport-connect-for-opencode] AiClient registry error: ' . $e->getMessage());` (`:66`), likewise `:59`.
- **Impact:** Harder triage; no user impact.
- **Recommendation:** Log `get_class($e) . ': ' . $e->getMessage()` or `__METHOD__` context. Keep `WP_DEBUG` guard.
- **Confidence:** High

#### F-DC-02 — Settings bootstrap has no Throwable guard
- **File:Line:** `duoport-connect-for-opencode.php:126-134`
- **Category:** Error Handling / Robustness
- **Severity:** MEDIUM
- **Problem:** Second `init` hook (`priority 20`) instantiates `Settings` and calls `register()` without `try/catch`. If `register_setting`/`AiClient::getCache()` interaction throws (e.g. during cache-bust path in tests), the exception bubbles to WP `do_action('init')` and can WSOD / break other `init` callbacks.
- **Evidence:** `if ( class_exists( Settings\Settings::class ) ) { ( new Settings\Settings() )->register(); }` (`:129-131`) — no catch, unlike the provider-registration hook `:50-68` which is wrapped.
- **Impact:** Admin init failure if settings registration throws; contradicts defensive posture elsewhere.
- **Recommendation:** Wrap in `try { … } catch (\Throwable $e) { if (WP_DEBUG…) error_log(...); }` mirroring provider block.
- **Confidence:** High

#### F-DC-03 — Untyped `$registry` parameter accepts any value
- **File:Line:** `duoport-connect-for-opencode.php:88`
- **Category:** Type Safety
- **Severity:** INFO
- **Problem:** `wp_connectors_init` callback is `static function ($registry): void` with no type. Intentionally permissive to avoid fatal on unexpected core payload, then guarded by `is_object` + `method_exists` (`:93-99`). Under `strict_types` this hides contract; a typed `object` hint would be clearer and still safe with the early return.
- **Evidence:** `:88` untyped param + defensive checks `:93-99`.
- **Impact:** None — current guard is correct. Minor type-clarity.
- **Recommendation:** Consider `object $registry` type with same guards, or keep untyped with comment (current comment at `:89-92` is adequate). No change required.
- **Confidence:** Medium

#### F-DC-04 — Admin notice message is imprecise when SDK is missing
- **File:Line:** `duoport-connect-for-opencode.php:32-42`
- **Category:** Logic / UX
- **Severity:** LOW
- **Problem:** Notice text is always `"requires WordPress 7.0+"` even when WP >=7.0 but `AiClient::class` is missing (e.g. AI Client plugin inactive). Branch (`:35`) is `version_compare(...) && class_exists(...)` — fails if *either* is false, but message mentions only WP.
- **Evidence:** `:35` `if ( version_compare(..., '7.0', '>=') && class_exists(\WordPress\AiClient\AiClient::class) ) return;` then `:39` generic message.
- **Impact:** Slightly misleading admin UX.
- **Recommendation:** Either broaden message to `"requires WordPress 7.0+ and the WordPress AI Client"` or branch message on which check failed. Low priority.
- **Confidence:** High

#### F-DC-05 — `VERSION` constant defined but never read internally
- **File:Line:** `duoport-connect-for-opencode.php:26`
- **Category:** Dead Code / Maintainability
- **Severity:** DEAD CODE (INFO)
- **Problem:** `const VERSION = '0.1.1'` is exported but not referenced inside plugin (providers use `AiClient::VERSION` for cache keys, not this constant). Not a bug — common for header sync — but worth noting as unused internal symbol.
- **Evidence:** Grep shows no `OpenCodeConnector\VERSION` usage; header `Version: 0.1.1` duplicates value.
- **Impact:** None. Drift risk if bumped in one place only.
- **Recommendation:** Either reference it (e.g. for asset versioning) or add comment that it mirrors plugin header.
- **Confidence:** High

---

### 3.2 `src/autoload.php`

#### F-AL-01 — `file_exists` without `is_readable` and no failure signal
- **File:Line:** `src/autoload.php:27-29`
- **Category:** Robustness
- **Severity:** LOW
- **Problem:** Autoloader checks `file_exists($file)` then `require $file`. If file exists but is not readable (permissions), `require` emits warning/fatal. `is_readable` would give a cleaner early-return and allow next autoloader to try.
- **Evidence:** `:27-29`.
- **Impact:** Edge case only — corrupted permissions would fatal instead of deferring.
- **Recommendation:** `if ( is_readable($file) ) { require $file; }` or keep `file_exists` + `@` suppression is not needed; `is_readable` is more precise.
- **Confidence:** Medium

#### F-AL-02 — No path-traversal hardening (defense-in-depth)
- **File:Line:** `src/autoload.php:24-26`
- **Category:** Security (defense-in-depth)
- **Severity:** INFO
- **Problem:** `$rel = substr(...)+ str_replace('\\','/',$rel)` derives filesystem path from `$class_name`. PHP class names cannot contain `/` or `..`, so injection is not exploitable via `spl_autoload` — but the code does not explicitly reject `..` segments if ever called directly.
- **Evidence:** `:24-26`.
- **Impact:** None under normal PHP autoload dispatch.
- **Recommendation:** No fix needed; optionally assert `!str_contains($rel,'..')` before `require`.
- **Confidence:** High

*No functional correctness bug in autoloader. Return type `void`, `strict_types`, namespace prefix check (`:21`), and PSR-4 mapping are correct.*

---

### 3.3 `src/Availability/OpenCodeProviderAvailability.php`

#### F-AV-01 — Fallback catalog silently maps any non-`'go'` to Zen
- **File:Line:** `src/Availability/OpenCodeProviderAvailability.php:70-71`
- **Category:** Logic / Correctness
- **Severity:** MEDIUM
- **Problem:** `$cls = 'go' === $this->catalog ? Go::class : Zen::class` and likewise `probe_model` at `:71` use else-branch for *any* non-go value. If constructor is ever called with typo (`'zenn'`, `''`, `'GO'`), it silently probes Zen instead of failing fast.
- **Evidence:** `:70-71` ternary without validation; constructor `:46` accepts any `string $catalog` with no allowlist.
- **Impact:** Misconfigured availability check could mark wrong provider as configured, hiding setup errors.
- **Recommendation:** Validate in constructor: `if (!in_array($catalog, ['go','zen'], true)) throw new InvalidArgumentException(...)`, or at call site use `match`.
- **Confidence:** High

#### F-AV-02 — Hard-coded probe model couples availability to a single model ID
- **File:Line:** `src/Availability/OpenCodeProviderAvailability.php:71`
- **Category:** Correctness / Fragility
- **Severity:** MEDIUM
- **Problem:** Probe uses `'deepseek-v4-flash'` (Go) and `'deepseek-v4-flash-free'` (Zen). If that model is removed/renamed from the API or allowlist, `chat/completions` will return `404`/`400` and `isConfigured()` returns `false` even with a valid key. Probe also incurs billed `chat/completions` call (even with `max_tokens=1`) on every cache miss.
- **Evidence:** `:71-85` builds `Request` to `chat/completions` with those IDs.
- **Impact:** False-negative "not connected" status after catalog changes; unnecessary cost/latency vs. cheaper `models` endpoint or `HEAD` check.
- **Recommendation:** Prefer `GET models` enumeration or a lightweight auth-check endpoint if available; otherwise document coupling and add test that probe IDs exist in `ModelAllowlist::ALLOW`.
- **Confidence:** High

#### F-AV-03 — Unused caught exception variables
- **File:Line:** `src/Availability/OpenCodeProviderAvailability.php:60,102`
- **Category:** Code Quality
- **Severity:** INFO
- **Problem:** `catch (\Throwable $e)` binds `$e` but never reads it (intentionally — probe returns `false`). PHP 8 allows `catch (\Throwable)` without variable; unused var triggers some linters.
- **Evidence:** `:60-62` and `:102-104` catch blocks.
- **Impact:** None.
- **Recommendation:** Use `catch (\Throwable)` or prefix `$_e` / add `unset($e)` comment to silence. No functional change.
- **Confidence:** High

#### F-AV-04 — Transient value stores `(int)$ok` but reads with loose narrowing
- **File:Line:** `src/Availability/OpenCodeProviderAvailability.php:64-68,105`
- **Category:** Correctness (verified — not a bug)
- **Severity:** INFO
- **Problem:** *Investigated as potential bug:* storing `(int)$ok` (`0`/`1`) then `if (false !== $cached) return (bool)$cached`. Since `0 !== false` in strict comparison, `0` correctly survives as cached `false`. The cast `(bool)` normalizes. Caching for 5 minutes for both positive and negative results is intentional debouncing.
- **Evidence:** `:64-68`, `:105`.
- **Impact:** None — logic is correct. Flagged here to record the hypothesis and outcome.
- **Recommendation:** No change. Optionally store `bool` directly and check `$cached !== false`.
- **Confidence:** High

#### F-AV-05 — No timeout / retry configuration on probe request
- **File:Line:** `src/Availability/OpenCodeProviderAvailability.php:88-90`
- **Category:** Robustness
- **Severity:** LOW
- **Problem:** `getHttpTransporter()->send($req)` uses SDK defaults. No explicit timeout; slow network stalls `isConfigured()` which is called on every admin page via `Settings::render` (`Settings.php:148-149`). Cached path mitigates but cold start may block.
- **Evidence:** `:88-90` no `RequestOptions` with timeout.
- **Impact:** Admin settings page could hang for default HTTP timeout on first load.
- **Recommendation:** Consider passing `getRequestOptions()` with short timeout if trait exposes it, or document reliance on SDK default. Low priority.
- **Confidence:** Medium

---

### 3.4 `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php`

#### F-MD-01 — Empty `data` array incorrectly throws `ResponseException`
- **File:Line:** `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:83-86`
- **Category:** Correctness / Edge Case
- **Severity:** HIGH
- **Problem:** `if ( ! isset($data['data']) || ! $data['data'] )` treats empty array (falsy) as missing. API returning `{"data":[]}` (legitimate empty catalog) currently throws `fromMissingData('OpenCode','data')` instead of returning `[]`. The SDK's `AbstractOpenAiCompatibleModelMetadataDirectory` expects empty list handling.
- **Evidence:** `:84-86` second disjunct `! $data['data']` is true for `[]`.
- **Impact:** Spurious exception surfaced to callers when provider has no models; breaks UI that expects empty list.
- **Recommendation:** `if ( ! isset($data['data']) || ! is_array($data['data']) ) { throw ... }` — then iterate; empty array naturally yields `[]` and correct sorting.
- **Confidence:** High

#### F-MD-02 — `get_option()` return not validated before array access
- **File:Line:** `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:87`
- **Category:** Type Safety / Edge Case
- **Severity:** MEDIUM
- **Problem:** `(bool)( get_option(\OpenCodeConnector\OPTION_NAME, array())['show_all_models'] ?? false )` assumes array. If DB is corrupted and option is stored as string/int (e.g. via direct `update_option` with wrong type), `['show_all_models']` on a string triggers `Warning: Trying to access array offset on value of type string` (PHP 8) before `??` applies, because offset access happens before null coalesce evaluation on the result.
- **Evidence:** `:87` direct offset on `get_option` result.
- **Impact:** PHP warning in model-directory path; could bubble as `Throwable` depending on error handler.
- **Recommendation:** `$raw = get_option(..., []); $show_all = is_array($raw) && !empty($raw['show_all_models']);`
- **Confidence:** High

#### F-MD-03 — `strpos` check for DeepSeek is order-sensitive and opaque
- **File:Line:** `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:115`
- **Category:** Logic / Maintainability
- **Severity:** LOW
- **Problem:** `$is_json_capable = 0 !== strpos($id, 'deepseek')` hides capability for IDs *starting* with `deepseek` only. IDs containing `deepseek` mid-string (unlikely) would be considered capable, contrary to comment "DeepSeek models return malformed JSON". Idiom is correct (`!==` guards type) but `str_starts_with($id,'deepseek')` would be clearer and matches intent exactly.
- **Evidence:** `:115`.
- **Impact:** None for current IDs (all start with `deepseek`); minor readability.
- **Recommendation:** `$is_json_capable = ! str_starts_with($id, 'deepseek');` (requires PHP 8.0+, already required 8.2). Retain comment.
- **Confidence:** High

#### F-MD-04 — `array_splice` insertion at hard-coded offset is fragile
- **File:Line:** `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:118`
- **Category:** Maintainability
- **Severity:** INFO
- **Problem:** `array_splice($opts, 5, 0, [new SupportedOption(outputSchema)])` assumes `outputMimeType` at index 5. If `common_opts` ordering changes, splice point drifts. Works today (confirmed indices: 0 systemInstruction, 1 maxTokens, 2 temperature, 3 topP, 4 stopSequences, 5 outputMimeType).
- **Evidence:** `:89-99` definition + `:118` splice.
- **Impact:** Future edit could misplace schema option silently.
- **Recommendation:** Build `$opts` conditionally or append and sort explicitly; or define insertion via array key map. Low priority.
- **Confidence:** Medium

#### F-MD-05 — Sorting comparator calls `ModelAllowlist::isFree` per comparison
- **File:Line:** `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:122-131`
- **Category:** Performance (micro)
- **Severity:** OPTIMIZATION (INFO)
- **Problem:** `usort` comparator calls `isFree()` (which does `in_array` linear scan over `FREE` (6 items)) for each comparison — O(n log n * 6). Negligible for ≤30 models but worth noting.
- **Evidence:** `:125-126`.
- **Impact:** Negligible (~microseconds).
- **Recommendation:** Precompute free-map via `array_flip` or `isset` lookup if catalog grows. No action needed now.
- **Confidence:** High

---

### 3.5 `src/Metadata/ModelAllowlist.php`

#### F-ALLOW-01 — No validation that `FREE` is subset of `ALLOW['zen']`
- **File:Line:** `src/Metadata/ModelAllowlist.php:31-85`
- **Category:** Logic / Maintainability
- **Severity:** INFO
- **Problem:** `FREE` includes `'big-pickle'` which is allowlisted in `ALLOW['zen']`, so consistent today. But there is no static assertion that every `FREE` id appears in at least one `ALLOW` entry. Drift (adding free ID without allowlisting) would label a model free that never appears.
- **Evidence:** `:77-85` vs `:50-70`.
- **Impact:** Minor — display bug only if drift occurs.
- **Recommendation:** Add test asserting `array_diff(FREE, array_merge(ALLOW['go'], ALLOW['zen'])) === []`.
- **Confidence:** Medium

#### F-ALLOW-02 — `displayName` is locale-naive but correct
- **File:Line:** `src/Metadata/ModelAllowlist.php:120-124`
- **Category:** Verdict
- **Severity:** INFO
- **Problem:** Investigated: `ucwords(str_replace(['-','_'],' ',$id))` for `'nemotron-3.5-lightning-free'` yields `'Nemotron 3.5 Lightning Free'` — version dots preserved, acceptable per comment at `:122`. No i18n needed for model ID display.
- **Impact:** None.
- **Recommendation:** No change.
- **Confidence:** High

*No correctness bug in `isAllowed`/`isFree` — strict `in_array(..., true)` and `?? []` fallback correctly handle unknown catalog (`:97`) returning `false`.*

---

### 3.6 `src/Metadata/OpenCodeGoModelMetadataDirectory.php` & `src/Metadata/OpenCodeZenModelMetadataDirectory.php`

#### F-DIR-01 — Near-duplicate classes (intentional)
- **File:Line:** `OpenCodeGoModelMetadataDirectory.php:27-48`, `OpenCodeZenModelMetadataDirectory.php:27-48`
- **Category:** Duplication
- **Severity:** DUPLICATE (INFO)
- **Problem:** Classes differ only by `providerClass()` return (`Go` vs `Zen`) and `catalogKey()` (`'go'` vs `'zen'`). Duplication is intentional for SDK registration (each directory is a distinct service class per provider). Could be templated, but current form is idiomatic for WordPress AI Client SDK.
- **Evidence:** Both files 49 lines, identical structure.
- **Impact:** None — minimal maintenance cost.
- **Recommendation:** Keep as-is; optionally share via parameterized base if third catalog added.
- **Confidence:** High

**Verdict for both directories:** No correctness, type, or error-handling issues found after reviewing all 49 lines each. `strict_types`, `ABSPATH` guard, `final` modifier, and return types are correct.

---

### 3.7 `src/Models/AbstractOpenCodeTextGenerationModel.php`

#### F-MO-01 — `createRequest` adds `getRequestOptions()` but parent metadata directory does not — potential LSP drift
- **File:Line:** `src/Models/AbstractOpenCodeTextGenerationModel.php:50-53`
- **Category:** SDK Contract
- **Severity:** LOW
- **Problem:** Model's `createRequest` passes 5th arg `getRequestOptions()`; metadata directory's `createRequest` (`AbstractOpenCodeModelMetadataDirectory:68-71`) passes 4 args. Both satisfy `Request` constructor (5th param optional), but if SDK's abstract `createRequest` signature is `createRequest(HttpMethodEnum,string,array,mixed): Request`, the 5-arg override adds a parameter not in parent — PHP allows extra optional param but static analysis may flag.
- **Evidence:** `:52` vs `AbstractOpenCodeModelMetadataDirectory:68`.
- **Impact:** None — runtime works because `Request` 5th param is optional; SDK likely expects model to forward options.
- **Recommendation:** Annotate that divergence is intentional; align both to 5 args for consistency.
- **Confidence:** Medium

#### F-MO-02 — `prepareResponseFormatParam(null)` always forces `json_object`
- **File:Line:** `src/Models/AbstractOpenCodeTextGenerationModel.php:63-75`
- **Category:** Logic / SDK Usage
- **Severity:** INFO (investigated as potential HIGH, downgraded)
- **Problem:** Hypothesis: returning `['type'=>'json_object']` when `$output_schema === null` forces JSON mode on every plain-text generation, breaking normal chat. Investigation shows SDK calls this only when `outputSchema` or `outputMimeType=application/json` is requested — per `AbstractOpenCodeModelMetadataDirectory:95-96` both mime types are advertised, and `outputSchema` is suppressed for DeepSeek. Returning `json_object` for null is therefore the documented OpenAI-compatible fallback for "JSON without schema". Behavior matches SDK expectation (see `AbstractOpenAiCompatibleTextGenerationModel` upstream).
- **Evidence:** `:74` `return array('type'=>'json_object');`, SDK enum `OptionEnum::outputSchema/outputMimeType`.
- **Impact:** None — correct for OpenCode API (`json_schema` with wrapper `name/schema/strict` at `:66-71`).
- **Recommendation:** Add doc comment clarifying null maps to `json_object` by design (mimeType fallback). No code change.
- **Confidence:** High

*No other issues: `strict_types`, `abstract providerClass()`, `declare` correct. `final` leaf models simply bind provider class.*

---

### 3.8 `src/Models/OpenCodeGoTextGenerationModel.php` & `src/Models/OpenCodeZenTextGenerationModel.php`

**No issues found after reviewing all 38 lines each.** Both files correctly declare `strict_types`, `ABSPATH` guard, `final`, and single-method `providerClass()` returning the matching provider FQCN. No logic to audit.

---

### 3.9 `src/Providers/AbstractOpenCodeProvider.php`

#### F-PR-01 — `createProviderMetadata` variadic construction is version-fragile
- **File:Line:** `src/Providers/AbstractOpenCodeProvider.php:108-123`
- **Category:** SDK Contract / Correctness
- **Severity:** MEDIUM
- **Problem:** Builds `$args` array then `new ProviderMetadata(...$args)` with conditional pushes based on `AiClient::VERSION >=1.2.0` (description) and `>=1.3.0` (icon). If SDK's `ProviderMetadata::__construct` signature changes order in a minor (e.g. adds `website` before `description`), positional spread will misalign. Current SDK history shows additive trailing params, so risk is low but real.
- **Evidence:** `:116-121` version gates; SDK `ProviderMetadata` is outside plugin control.
- **Impact:** Future AI Client minor could cause `ArgumentCountError` or swapped fields, breaking provider registration (caught only by outer `Throwable` in bootstrap).
- **Recommendation:** If SDK provides named constructors or builders, prefer them. Alternatively probe `ReflectionClass::getConstructor()->getParameters()` to map by name. At minimum, pin tested `AiClient::VERSION` range in CI.
- **Confidence:** Medium

#### F-PR-02 — Missing explicit `abstract protected static function baseUrl(): string`
- **File:Line:** `src/Providers/AbstractOpenCodeProvider.php:39-148`
- **Category:** Contract / Type Safety
- **Severity:** LOW
- **Problem:** Intermediate abstract does not declare `abstract protected static function baseUrl(): string`, yet `AbstractApiProvider` (SDK parent) requires it and both concretes implement it. Omitting declaration hides the contract from static analysis and allows a future concrete to forget `baseUrl` and get a late `Error` from SDK parent.
- **Evidence:** `AbstractOpenCodeProvider` declares 4 abstracts but not `baseUrl` (`:42-76`), while `OpenCodeGoProvider:77-79` and `OpenCodeZenProvider:77-79` each implement it.
- **Impact:** Minor — no runtime bug today, but weaker compile-time safety.
- **Recommendation:** Add `abstract protected static function baseUrl(): string;` to `AbstractOpenCodeProvider`.
- **Confidence:** High

#### F-PR-03 — `createModel` `isTextGeneration()` loop assumes single capability
- **File:Line:** `src/Providers/AbstractOpenCodeProvider.php:87-99`
- **Category:** Logic
- **Severity:** INFO
- **Problem:** Loop `foreach ($caps as $cap) if ($cap->isTextGeneration()) return new …` picks text-generation even if model also advertises other capabilities. Since current `ModelMetadata` at `AbstractOpenCodeModelMetadataDirectory:120` only advertises `textGeneration` + `chatHistory`, this is fine. If SDK adds `imageGeneration` etc., first-match is still text model — correct.
- **Evidence:** `:89-94`.
- **Impact:** None.
- **Recommendation:** No change; loop is defensive.
- **Confidence:** High

---

### 3.10 `src/Providers/OpenCodeGoProvider.php` & `src/Providers/OpenCodeZenProvider.php`

**No correctness issues found after reviewing all 80 lines each.** Both providers correctly implement required abstracts, return well-formed IDs (`opencode-go`/`opencode-zen`), display names, descriptions, and base URLs (`https://opencode.ai/zen/go/v1` vs `https://opencode.ai/zen/v1`). `strict_types`, `ABSPATH`, `final` are correct. Base URL strings are validated as `https` absolute URLs (no trailing slash handling needed — `Provider::url()` appends path).

*Duplication note:* The two providers are structurally duplicate (DUPLICATE/INFO) but separation is required per SDK provider registry. No merge recommended.

---

### 3.11 `src/Settings/Settings.php`

#### F-ST-01 — `render()` calls `isProviderConfigured` without try/catch — settings page can WSOD
- **File:Line:** `src/Settings/Settings.php:148-149`
- **Category:** Error Handling
- **Severity:** MEDIUM
- **Problem:** `$go_ok = class_exists(AiClient::class) && AiClient::defaultRegistry()->isProviderConfigured('opencode-go');` — if `defaultRegistry()` or `isProviderConfigured()` throws (`RuntimeException`, `TypeError` from corrupted SDK state), exception propagates out of `render()` (called synchronously during `admin_menu` page render) and aborts the settings page with a fatal, despite `duoport-connect-for-opencode.php:44-71` protecting registration path.
- **Evidence:** `:148-149` no try/catch; short-circuit protects `class_exists` false, not the `&&` right side when class exists but registry is broken.
- **Impact:** Admin viewing "DuoPort Connector" settings sees white screen instead of "not connected".
- **Recommendation:** Wrap each in `try { … } catch (\Throwable) { $go_ok=false; }`.
- **Confidence:** High

#### F-ST-02 — `clearModelCaches()` reconstructs SDK cache key by hand — brittle coupling
- **File:Line:** `src/Settings/Settings.php:105-126`
- **Category:** Correctness / Fragility
- **Severity:** MEDIUM
- **Problem:** `$full_key = 'ai_client_' . AiClient::VERSION . '_' . md5($cls) . '_models'` (`:112`) duplicates SDK's internal key formula. If SDK changes prefix (`ai_client_`), or hashing (e.g. `sha1`), or adds salt, this cleanup will miss the real cache entry, leaving stale model lists after toggling "Show all models".
- **Evidence:** `:112` hand-rolled key vs SDK's `AbstractOpenAiCompatibleModelMetadataDirectory` internal caching.
- **Impact:** User toggles checkbox, sees no model-list change until natural cache expiry; contradicts `bustCaches` intent.
- **Recommendation:** Prefer SDK-provided `clearCache()`/`invalidate()` API if exposed; otherwise add integration test that asserts key formula matches SDK version for each AI Client release, and fall back to `delete_transient` enumeration (`$wpdb->query` fallback already covers DB fallback at `:122`).
- **Confidence:** High

#### F-ST-03 — Direct `$wpdb->query DELETE` after `delete_transient` is redundant and flagged by PHPCS
- **File:Line:** `src/Settings/Settings.php:118-124`
- **Category:** Performance / Standards
- **Severity:** INFO (OPTIMIZATION)
- **Problem:** `delete_transient($full_key)` already deletes `_transient_$key` and `_transient_timeout_$key` (or object cache entry). The subsequent `$wpdb->query( DELETE FROM {$wpdb->options} WHERE option_name = %s OR option_name = %s …)` (`:123`) is a defensive fallback for "object-cache-less installs" per comment `:119`. It's harmless but PHPCS flags `DirectDatabaseQuery.DirectQuery` (suppressed via `phpcs:ignore` at `:122`) and doubles DB writes.
- **Evidence:** `:118` + `:122-123`.
- **Impact:** Negligible — one extra query per cache bust.
- **Recommendation:** Keep fallback but document that `delete_transient` is primary; consider removing raw query if `delete_transient` proves sufficient in target WP versions (WP 6.5+).
- **Confidence:** Medium

#### F-ST-04 — `sanitize()` discards extra keys (intentional) but lacks type narrowing for `show_all_models`
- **File:Line:** `src/Settings/Settings.php:58-63`
- **Category:** Correctness
- **Severity:** LOW
- **Problem:** `return array('show_all_models' => ! empty($value['show_all_models']))` correctly normalizes to bool, but if `$value` is array with non-scalar `show_all_models` (e.g. `array()`), `!empty` is `true` for non-empty array, yielding `true` unexpectedly. Should coerce to bool via explicit check.
- **Evidence:** `:62`.
- **Impact:** Edge — admin form only sends `"1"` or absent, but programmatic `update_option` with array value could toggle.
- **Recommendation:** `!empty` is acceptable given form context; alternatively `(bool)($value['show_all_models'] ?? false)` or `filter_var`. No change required.
- **Confidence:** Low

#### F-ST-05 — `global $wpdb` guard is correct for tests but misses multisite
- **File:Line:** `src/Settings/Settings.php:120-124`
- **Category:** Edge Case
- **Severity:** LOW
- **Problem:** Cache cleanup uses `$wpdb->options` (single-site table). On multisite, transients for network admin may live in `sitemeta`/`site-transient`. Plugin is not network-activated aware (no `delete_site_transient`).
- **Evidence:** `:120-123`.
- **Impact:** On multisite, stale `ai_client_*` cache may survive in site transient table.
- **Recommendation:** If multisite support intended, also `delete_site_transient($full_key)` or iterate over sites. Document single-site scope if not.
- **Confidence:** Medium

---

### 3.12 `uninstall.php`

#### F-UN-01 — Does not clean AI Client model-metadata caches (`ai_client_*`)
- **File:Line:** `uninstall.php:12-14`
- **Category:** Correctness / Cleanup
- **Severity:** MEDIUM
- **Problem:** Uninstall deletes `opencode_connector_settings` option and `opencode_connector_avail_{go,zen}` transients, but not the `ai_client_<VERSION>_<md5>_models` transients/options created by `AbstractOpenAiCompatibleModelMetadataDirectory` (and explicitly busted in `Settings::clearModelCaches`). Leaves orphaned rows after uninstall.
- **Evidence:** `uninstall.php:12-14` vs `Settings.php:112` key `ai_client_` + `md5`.
- **Impact:** Orphaned transients remain in `wp_options` (autoload `no`, timeout row as well) until expiry/GC.
- **Recommendation:** Enumerate both provider directory classes and delete `delete_transient('ai_client_'.AiClient::VERSION.'_'.md5(...).'_models')` plus `delete_site_transient` fallback, or at minimum wildcard delete `DELETE FROM wp_options WHERE option_name LIKE '_transient_ai_client_%_models%'` (with `prepare` + `LIKE` escaping). Keep `WP_UNINSTALL_PLUGIN` guard.
- **Confidence:** High

#### F-UN-02 — Single-site only (no `delete_site_option`/`is_multisite` handling)
- **File:Line:** `uninstall.php:12`
- **Category:** Edge Case
- **Severity:** LOW
- **Problem:** Uses `delete_option` only. For network-activated uninstall, `delete_site_option` may be needed if option was network-stored (plugin uses plain `get_option`, so likely per-site, but uninstall docs recommend handling both).
- **Evidence:** `:12`.
- **Impact:** On multisite with network activation, per-site `uninstall.php` is invoked per site by WP core (since WP 5.1), so `delete_option` suffices. Not a bug but worth documenting.
- **Recommendation:** Add comment clarifying per-site invocation; optionally guard with `if (is_multisite()) delete_site_option(...)`.
- **Confidence:** Medium

*No syntax error: `php -l` passes. `WP_UNINSTALL_PLUGIN` guard correct.*

---

### 3.13 Cross-file / Systemic Findings

#### F-SYS-01 — Availability probe and metadata directory both build `Request` without `getRequestOptions`
- **File:Line:** `Availability:72-86`, `AbstractOpenCodeModelMetadataDirectory:68-71`, `AbstractOpenCodeTextGenerationModel:50-53`
- **Category:** Inconsistency
- **Severity:** INFO
- **Problem:** Only the model layer forwards `getRequestOptions()` (timeout, etc.). Availability and metadata requests use bare `Request` without options, so any custom transport options (e.g. timeout override via SDK) won't apply to probes/listing.
- **Evidence:** Cited lines.
- **Impact:** Minor — defaults are usually fine.
- **Recommendation:** Align all `createRequest` implementations to forward options where `WithHttpTransporterTrait` exposes them.
- **Confidence:** Medium

#### F-SYS-02 — `MINUTE_IN_SECONDS` constant without include guard (relies on WP load order)
- **File:Line:** `Availability:105` (`5 * MINUTE_IN_SECONDS`)
- **Category:** Correctness (load order)
- **Severity:** INFO
- **Problem:** `MINUTE_IN_SECONDS` is defined by `wp-includes/default-constants.php` (loaded before `init`). Availability check runs after `init`, so defined. In unit tests with Brain Monkey and minimal `ABSPATH` stub, constant is mocked via Monkey. No bug.
- **Evidence:** `:105`.
- **Impact:** None.
- **Recommendation:** No change; optionally `defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60` for CLI resilience.
- **Confidence:** High

---

## 4. Per-File Verdicts (Explicit)

| File | Verdict |
|------|---------|
| `duoport-connect-for-opencode.php` | Issues found — F-DC-01 (LOW), F-DC-02 (MEDIUM), F-DC-03 (INFO), F-DC-04 (LOW), F-DC-05 (DEAD CODE/INFO). Core logic correct, defensive best elsewhere not applied uniformly. |
| `src/autoload.php` | **No functional correctness issues found after reviewing all 31 lines.** Two INFO/LOW hardening notes (F-AL-01, F-AL-02) but no bug. PSR-4 mapping, guards, `strict_types` all correct. |
| `src/Availability/OpenCodeProviderAvailability.php` | Issues found — F-AV-01 (MEDIUM), F-AV-02 (MEDIUM), F-AV-03 (INFO), F-AV-05 (LOW). F-AV-04 investigated and confirmed correct. |
| `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php` | Issues found — F-MD-01 (HIGH), F-MD-02 (MEDIUM), F-MD-03 (LOW), F-MD-04 (INFO), F-MD-05 (OPTIMIZATION). |
| `src/Metadata/ModelAllowlist.php` | **No correctness issues found after reviewing all 125 lines.** F-ALLOW-01/02 are INFO observations; strict `in_array`, fallback, and display logic correct. |
| `src/Metadata/OpenCodeGoModelMetadataDirectory.php` | **No issues found after reviewing all 49 lines.** Correct delegation; duplication is intentional (F-DIR-01). |
| `src/Metadata/OpenCodeZenModelMetadataDirectory.php` | **No issues found after reviewing all 49 lines.** Same verdict as Go directory. |
| `src/Models/AbstractOpenCodeTextGenerationModel.php` | **No correctness bug after reviewing all 76 lines.** F-MO-01 (LOW) and F-MO-02 (INFO) are observations; `strict_types`, wrapping, and SDK override are correct. |
| `src/Models/OpenCodeGoTextGenerationModel.php` | **No issues found after reviewing all 38 lines.** Single binding correct. |
| `src/Models/OpenCodeZenTextGenerationModel.php` | **No issues found after reviewing all 38 lines.** Single binding correct. |
| `src/Providers/AbstractOpenCodeProvider.php` | Issues found — F-PR-01 (MEDIUM), F-PR-02 (LOW). |
| `src/Providers/OpenCodeGoProvider.php` | **No issues found after reviewing all 80 lines.** Correct constants and URL. |
| `src/Providers/OpenCodeZenProvider.php` | **No issues found after reviewing all 80 lines.** Correct constants and URL. |
| `src/Settings/Settings.php` | Issues found — F-ST-01 (MEDIUM), F-ST-02 (MEDIUM), F-ST-03 (INFO), F-ST-04 (LOW), F-ST-05 (LOW). |
| `uninstall.php` | Issues found — F-UN-01 (MEDIUM), F-UN-02 (LOW). |

---

## 5. Summary Counts

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 1 (F-MD-01) |
| MEDIUM | 8 (F-DC-02, F-AV-01, F-AV-02, F-MD-02, F-PR-01, F-ST-01, F-ST-02, F-UN-01) |
| LOW | 7 (F-DC-01, F-DC-04, F-AL-01, F-MD-03, F-MO-01, F-PR-02, F-ST-04/05, F-UN-02) |
| INFO | 7 |
| OPTIMIZATION | 1 (F-MD-05, plus F-ST-03) |
| DUPLICATE | 1 (F-DIR-01 systemic) |
| DEAD CODE | 1 (F-DC-05) |

**Top priority fix:** `AbstractOpenCodeModelMetadataDirectory.php:84` empty-array handling (HIGH) — change to `!is_array` guard so `{"data":[]}` returns `[]`.

**Next:** Settings render try/catch (MEDIUM), cache-key coupling (MEDIUM), uninstall cache cleanup (MEDIUM), availability catalog validation + probe decoupling (MEDIUM), provider metadata version fragility (MEDIUM).

---

## 6. Methodology Notes

- Every production file read in full via `read` tool; syntax validated via `php -l` for bootstrap, availability, metadata, settings.
- Tests not executed here but skimmed to understand SDK contract expectations: `wp_connectors_init` override (pristine restore), slim bust hooks (no `connectors_ai_*` option access), and absence of `Approvals_Store` usage all correspond to current implementation and were used to inform audit hypotheses.
- No AI Client vendor source present in `vendor/` (only WPCS/PHPCS), so SDK behavior inferred from interface names, traits, DTOs, and call sites; flagged where inference limits confidence.

---

*End of report — PHP Correctness Audit — no production code modified.*
