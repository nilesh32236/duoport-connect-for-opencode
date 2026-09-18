# Duplicate & Dead-Code Audit — DuoPort Connector for OpenCode

**Scope:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/duoport-connect-for-opencode` — all production files (`duoport-connect-for-opencode.php`, `src/**`, `uninstall.php`, `assets/**`, `composer.json`, `readme.txt`). Vendor/ excluded. **Date:** 2026-08-28.
**Method:** Full `Read` of 13 prod PHP files + `Grep` for duplicate patterns, `python3 -c difflib.SequenceMatcher` for Go vs Zen pairs, model/metadata directory comparisons, `ModelAllowlist` usage graph, `VERSION`/`OPTION_NAME` reference counts, hook/ transient/ option inventories, commented/debug/dead-branch scans, asset reference checks, uninstall storage reconciliation.

---

## 1. Executive Summary

| Category | Severity | Count |
|---|---|---|
| Near-duplicate provider/model/metadata pairs (SDK-required) | **LOW / INFO** | 3 pairs, 93–98% identical |
| True duplicated method: `createRequest` across two abstractions | **MEDIUM** | 1 |
| Repeated cache-bust `delete_transient` trio | **LOW** | 3 sites, 6 calls |
| Repeated `version_compare(AiClient::VERSION …)` + catalog ternary | **LOW** | 2 each |
| Unused constant `VERSION` | **MEDIUM** | 1 |
| Uninstall incomplete (leaks `ai_client_*_models` cache) | **MEDIUM** | 1 |
| Unused asset `assets/icon.svg` | **LOW** | 1 |
| Dead formal params `bustCachesAdd($option,$value)` | **INFO** | 1 |
| SDK-contract methods flagged as “unused” by naive static analysis | **INFO / FP** | 7 |
| Obsolete / commented / debug / unreachable | **NONE** | 0 |

**Bottom line:** No high-severity dead code or copy-paste logic bug. The 3 Go/Zen class pairs are SDK-mandated duplication (each provider must be a distinct FQCN for `AiClient::defaultRegistry()->registerProvider()`). The only actionable duplication is `createRequest` differing by one argument and the repeated transient-bust trio. The only true dead code is `const VERSION` and the orphan SVG.

---

## 2. Duplicates — Exact & Near

### 2.1 Copy-Pasted Class Pairs: Go vs Zen (SDK-required, INFO)

> **Verdict:** Near-duplicates are **required** by the WordPress AI SDK. Each concrete class exists so `hasProvider(FQCN)` / `registerProvider(FQCN)` identity differs. Bases already factor the shared behavior. Collapsing them would break registration or force a reflective factory that fights the SDK. Report as INFO, do not refactor without SDK change.

| Pair | Files | Diff | Similarity |
|---|---|---|---|
| **Providers** | `src/Providers/OpenCodeGoProvider.php:25` (`final class OpenCodeGoProvider`) vs `src/Providers/OpenCodeZenProvider.php:25` (`final class OpenCodeZenProvider`) | Only literals differ: `providerId()` `opencode-go`↔`opencode-zen:33–34`, `catalogKey()` `go`↔`zen:44`, `displayName()` `OpenCode Go`↔`OpenCode Zen:55`, `description()` Go vs Zen+free:66, `baseUrl()` `https://opencode.ai/zen/go/v1`↔`https://opencode.ai/zen/v1:78` | **93.7%** (`difflib.SequenceMatcher`) |
| **Models** | `src/Models/OpenCodeGoTextGenerationModel.php:27` vs `src/Models/OpenCodeZenTextGenerationModel.php:27` | Import + `providerClass()` return FQCN only: `OpenCodeGoProvider::class`↔`OpenCodeZenProvider::class:35` | **97.6%** |
| **Metadata directories** | `src/Metadata/OpenCodeGoModelMetadataDirectory.php:27` vs `src/Metadata/OpenCodeZenModelMetadataDirectory.php:27` | Same as models: `providerClass()` + `catalogKey()` :35,46 | **97.8%** |

**Single-responsibility check:** Bases hold all non-trivial logic:
- `src/Providers/AbstractOpenCodeProvider.php:87–99` `createModel`, `:108–123` `createProviderMetadata`, `:132–134` `createProviderAvailability`, `:143–147` `createModelMetadataDirectory`
- `src/Models/AbstractOpenCodeTextGenerationModel.php:50–53` `createRequest`, `:63–75` `prepareResponseFormatParam`
- `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:68–71` `createRequest`, `:82–134` `parseResponseToModelMetadataList`

**Recommendation:** Keep. Add inline comment in each concrete class: `// Distinct FQCN required by AiClient::registerProvider — see AbstractOpenCodeProvider`.

### 2.2 Exact Duplicate Method: `createRequest` Across Two Abstractions — MEDIUM

The two abstractions each define an identical `createRequest` differing **only** by one trailing argument:

- `src/Models/AbstractOpenCodeTextGenerationModel.php:50–53`:
  ```php
  protected function createRequest(HttpMethodEnum $method, string $path, array $headers = array(), $data = null): Request {
      $cls = $this->providerClass();
      return new Request($method, $cls::url($path), $headers, $data, $this->getRequestOptions());
  }
  ```
- `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:68–71`:
  ```php
  protected function createRequest(HttpMethodEnum $method, string $path, array $headers = array(), $data = null): Request {
      $cls = $this->providerClass();
      return new Request($method, $cls::url($path), $headers, $data);
  }
  ```

 delta = `, $this->getRequestOptions()` (available only on `AbstractOpenAiCompatibleTextGenerationModel`, not on `AbstractOpenAiCompatibleModelMetadataDirectory`). This is textbook “near-exact duplicate” — same `providerClass()`/`::url()` dispatch, same signature, 75% token overlap.

**Recommendation (low-risk):** Extract to a trait `HasOpenCodeRequest` or a small helper `OpenCodeRequestFactory::forProvider(string $providerFqcn, string $path, …, ?array $options)` and have both call it. Alternatively keep as-is and annotate why they diverge — the divergence is a genuine SDK asymmetry, but duplication still triggers linters/DryRunners.

### 2.3 Repeated Boilerplate Guard — INFO (Ignore)

`if ( ! defined('ABSPATH') ) { exit; }` appears in **all 12** `src/**` files + `duoport-connect-for-opencode.php:22–24` and `autoload.php:15–17`: `src/Providers/{AbstractOpenCodeProvider,OpenCodeGoProvider,OpenCodeZenProvider}:15–17`, `src/Models/*:15–17`, `src/Metadata/*:15–17`, `src/Availability/OpenCodeProviderAvailability.php:15–17`, `src/Settings/Settings.php:15–17`. This is mandated WP plugin boilerplate, not dead code — exclude from DRY tooling.

---

## 3. Repeated Conditions / Queries / Validation / Cache Logic / Hook Registrations

### 3.1 Transient-Bust Trio Duplicated 3× — LOW

Identical pair repeated verbatim:

- `duoport-connect-for-opencode.php:74–77` — `$opencode_connector_bust = static function(): void { delete_transient('opencode_connector_avail_go'); delete_transient('opencode_connector_avail_zen'); };` + `add_action('update_option_connectors_ai_opencode_go_api_key', …):78` / `add_option_…:79`
- `src/Settings/Settings.php:76–78` — inside `bustCaches()` after `if (($old['show_all']??false)!==($new['show_all']??false))`
- `src/Settings/Settings.php:93–95` — inside `bustCachesAdd()` unconditionally (plus `clearModelCaches()`)

Separately, `src/Settings/Settings.php:105–126` `clearModelCaches()` loops two directory classes, calls `AiClient::getCache()->delete($full_key)`, then `delete_transient($full_key)`, then `$wpdb->query(DELETE … _transient_*)` — cache-key construction `$full_key='ai_client_'.AiClient::VERSION.'_'.md5($cls).'_models':112` repeated for two classes by loop (good), but conceptually duplicates the “delete this transient/option pair” pattern above.

**Impact:** 6 direct `delete_transient('opencode_connector_avail_*')` calls that must stay in sync. A typo in one key string silently diverges.

**Recommendation:** Centralize:
```php
// src/Support/Transients.php or Availability class
public const AVAIL_GO  = 'opencode_connector_avail_go';
public const AVAIL_ZEN = 'opencode_connector_avail_zen';
public static function bustAvailability(): void {
    delete_transient(self::AVAIL_GO); delete_transient(self::AVAIL_ZEN);
}
```
Replace 3 call-sites. Already partially done via `$opencode_connector_bust` closure — extract to named function for testability.

### 3.2 Catalog-Gated Ternary Duplicated — LOW

```php
'go' === static::catalogKey() ? A : B  // src/Providers/AbstractOpenCodeProvider.php:91 (createModel), :144 (createModelMetadataDirectory)
'go' === $this->catalog ? A : B       // src/Availability/OpenCodeProviderAvailability.php:70 (provider class), :71 (probe model)
```

Two pairs. The branch strings are different each time (model class vs directory class; provider class vs probe model `deepseek-v4-flash`↔`deepseek-v4-flash-free`), so abstraction would add indirection. Flagged as near-duplicate condition; keeping is fine but a `match($catalog)` helper or `catalog->providerClass()` mapping would read cleaner.

### 3.3 `version_compare(AiClient::VERSION …)` Pattern — LOW

- `src/Providers/AbstractOpenCodeProvider.php:116` `if (version_compare(AiClient::VERSION,'1.2.0','>=')) $args[]=description();`
- `src/Providers/AbstractOpenCodeProvider.php:119` `if (version_compare(AiClient::VERSION,'1.3.0','>=')) $args[]=svg_path;`

Not dead — guards SDK backward-compat for optional `ProviderMetadata` args. The repetition is intentional sequential gating. Could collapse to a version-map, but current form is clearest.

### 3.4 Repeated `get_option(OPTION_NAME)` / `OPTION_NAME` Hook Wiring — LOW

- `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:87` `$show_all=(bool)(get_option(\OpenCodeConnector\OPTION_NAME,[] )['show_all_models']??false)`
- `src/Settings/Settings.php:147` `get_option(\OpenCodeConnector\OPTION_NAME, ['show_all_models'=>false])`
- `src/Settings/Settings.php:38–47` `register_setting('opencode_connector', OPTION_NAME, …)` + `add_action('update_option_'.OPTION_NAME, [$this,'bustCaches'])` / `add_option_… bustCachesAdd`
- `duoport-connect-for-opencode.php:27` `const OPTION_NAME='opencode_connector_settings'`

The two `get_option` read sites intentionally share the same option — not duplicated logic, but a single option read with consistent default. The hook-name concatenation `update_option_ . OPTION_NAME` is correct WP pattern; flagged only for completeness.

### 3.5 Hook Registration Inventory (no duplicates)

Total **7** top-level `add_action`/`add_filter` in `duoport-connect-for-opencode.php:32,44,78,79,86,126,137` + **3** inside `Settings::register():45–47` (`admin_menu`, `update_option_{OPTION_NAME}`, `add_option_{OPTION_NAME}`). No duplicate hook *names* with conflicting callbacks. Two `init` hooks are intentional with priorities `5` (provider registration) and `20` (settings bootstrap). Each hooked closure is referenced exactly once via `add_action` return and WordPress `$wp_filter` — no dead hook.

---

## 4. Copy-Pasted Classes — Consolidated

See §2.1. Additional note: `AbstractOpenCodeTextGenerationModel` and `AbstractOpenCodeModelMetadataDirectory` both override `providerClass(): string` + `catalogKey(): string` as abstract, forcing each concrete child to re-declare a one-liner return. This is not copy-paste but SDK template-method enforcement — the concrete `providerClass()`/`catalogKey()` pairs are correlated (Go provider ↔ go key, Zen ↔ zen key) and validated by the four `difflib` slices above. No third catalog exists, so generics would be over-engineering.

---

## 5. Dead / Unused Code

### 5.1 [MEDIUM] Unused Constant `VERSION` — `duoport-connect-for-opencode.php:26`

```php
const VERSION     = '0.1.1';          // duoport-connect-for-opencode.php:26
const OPTION_NAME = 'opencode_connector_settings'; // :27
```

- `VERSION` has **zero** references outside its definition. Verified via `Grep VERSION` across `duoport-connect-for-opencode.php` + `src/**`: only the definition itself + 3 hits for `AiClient::VERSION` (unrelated SDK constant) at `src/Providers/AbstractOpenCodeProvider.php:116,119` and `src/Settings/Settings.php:112`. Plugin header at `duoport-connect-for-opencode.php:7` `Version: 0.1.1` is the canonical source; `VERSION` is stale duplication.
- `OPTION_NAME` **is** used (7 refs: `duoport-connect-for-opencode.php:27` def + `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:87`, `src/Settings/Settings.php:38,46,47,147,175`) — alive.

**Evidence:** `rg -n "VERSION" src/ duoport-connect-for-opencode.php` returns only `const VERSION` and `AiClient::VERSION`. No `OpenCodeConnector\VERSION` read.

**Fix:** Delete `const VERSION` or, if retained for back-compat, add `// Used by external callers: OpenCodeConnector\VERSION` and reference it (e.g., in `createProviderMetadata` cache key). Historically plugins expose `VERSION` for enqueuing — but this plugin enqueues nothing.

### 5.2 [MEDIUM] Incomplete Uninstall — `uninstall.php:12–14` Leaks `ai_client_*_models` Cache

`uninstall.php` deletes:
```php
delete_option('opencode_connector_settings');        // :12
delete_transient('opencode_connector_avail_go');    // :13
delete_transient('opencode_connector_avail_zen');   // :14
```

But `src/Settings/Settings.php:105–124` `clearModelCaches()` persists per-SDK-version caches for both catalogs:
```php
$full_key='ai_client_'.AiClient::VERSION.'_'.md5($cls).'_models'; // :112
$cache->delete($full_key);           // :115  (object cache)
delete_transient($full_key);         // :118  (transient)
$wpdb->query(DELETE FROM {$wpdb->options} WHERE option_name IN ('_transient_%','_transient_timeout_%')); // :123
```

On uninstall, those `ai_client_*_models` rows remain in `wp_options` (and possibly in external object cache). For sites that tested multiple `AiClient::VERSION` values, multiple stale keys remain. The `availability` transients *are* cleaned — only the model metadata cache is missed.

**Fix:** Mirror `clearModelCaches()` in `uninstall.php` (without `AiClient` dependency — enumerate known version patterns or brute-delete `WHERE option_name LIKE '\_transient\_ai\_client\_%\_models%'`). Minimum:
```php
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ai_client_%_models' OR option_name LIKE '_transient_timeout_ai_client_%_models'");
if (function_exists('wp_cache_flush')) { /* optional */ }
```

### 5.3 [LOW] Unused Asset `assets/icon.svg` — Orphan

- `assets/images/opencode.svg` **is** referenced: `src/Providers/AbstractOpenCodeProvider.php:120` `dirname(__DIR__,2).'/assets/images/opencode.svg'`
- `assets/icon.svg` (1000-byte file, separate from the 503-byte `images/opencode.svg`) has **zero** references in `duoport-connect-for-opencode.php`, `src/**`, `readme.txt`, or `composer.json`. `assets/banner-772x250.png` is likewise unreferenced in code (expected — WP.org directory asset), but `icon.svg` vs `images/opencode.svg` duplication is confusing: one is bare `icon.svg` at `assets/`, the other is `assets/images/opencode.svg`.

**Evidence:** `rg -n "icon\.svg|banner" src/ duoport-connect-for-opencode.php` → no hit for `icon.svg` or `banner`.

**Fix:** Delete `assets/icon.svg` if deprecated, or point provider at it (`:120` currently points to `images/opencode.svg`). If both are intentional (icon for plugin directory, image for runtime), rename to make intent explicit (`assets/wporg-icon.svg` vs `assets/images/provider-logo.svg`).

### 5.4 [INFO] Dead Formal Params `bustCachesAdd(string $option, $value)` — `src/Settings/Settings.php:91`

```php
public function bustCachesAdd(string $option, $value): void { // :91
    unset($option, $value);                                   // :92
    $this->clearModelCaches();                                // :93
```
Params are intentionally unused — WP fires `add_option_{$option}` with `($option,$value)` but this handler busts caches unconditionally (no filter on `$option` value because option is already `OPTION_NAME`). The `unset()` silences “unused parameter” inspection. WP hook *requires* acceptance of 2 args (`add_action(...,10,2):47`), so params are live for dispatch even if body ignores them.

**Verdict:** Not dead — intentional. Annotate `// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- hook signature` to remove heuristic noise.

### 5.5 [INFO] Methods Flagged “Unused” by Naive Static Analysis — False Positives via SDK Reflection

A file-scoped `grep -c function` counts definitions but not SDK-invoked templates. All of the following are **alive** via `WordPress\AiClient` abstract contracts dispatched by the SDK (registry, directory, model factories). They are *expected* to show “1 ref = definition only” when grepping only plugin code:

| Method | Location | SDK Caller | Evidence |
|---|---|---|---|
| `__construct(string $catalog)` | `src/Availability/OpenCodeProviderAvailability.php:46` | `AbstractOpenCodeProvider::createProviderAvailability():133` `new OpenCodeProviderAvailability(static::catalogKey())` | Alive — instantiated by provider base |
| `isConfigured(): bool` | `:57` | SDK `ProviderAvailabilityInterface` polled by `AiClient::defaultRegistry()->isProviderConfigured()` (see `src/Settings/Settings.php:148–149` render-time) | Alive — trait `WithRequestAuthenticationTrait` |
| `createModel` | `src/Providers/AbstractOpenCodeProvider.php:87` | `AbstractApiProvider` factory via `AiClient::generateText()` | Alive via SDK dispatch |
| `createProviderMetadata` | `:108` | `AbstractApiProvider` registry path | Alive |
| `createProviderAvailability` | `:132` | Provider bootstrap | Alive |
| `createModelMetadataDirectory` | `:143` | Provider bootstrap | Alive |
| `parseResponseToModelMetadataList` | `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:82` | `AbstractOpenAiCompatibleModelMetadataDirectory` fetch path | Alive |
| `prepareResponseFormatParam` | `src/Models/AbstractOpenCodeTextGenerationModel.php:63` | `AbstractOpenAiCompatibleTextGenerationModel` JSON path | Alive |
| `createRequest` (x2) | see §2.2 | SDK HTTP trait | Alive |
| `baseUrl()` | `src/Providers/OpenCodeGoProvider.php:77` / `Zen:77` | `AbstractApiProvider::url($path)` static dispatcher | Alive — URL builder |

No true unused method. A repository-level `rg` that includes `vendor/WordPress/ai-client` would resolve them; since SDK is not vendored (WordPress core), treat as known false-positive.

### 5.6 No Dead Variables / Assignments

Scanned `\$\w+\s*=` assignments across `src/**` — every assigned `$var` is subsequently read. Notably `$show_all`, `$common_opts`, `$list`, `$full_key`, `$cache`, `$classes`, `$probe_model`, `$req`, etc., all have ≥2 uses. No orphaned assignment.

### 5.7 Hook / Filter Subscriptions: All Referenced

Every `add_action`/`add_filter` target is either a closure invoked by WordPress core or a method referenced by handle array (`[$this,'bustCaches']`, `[$this,'menu']`). Each has exactly one registration and zero orphan. Confirmed no `remove_action`/`remove_filter` that would deaden a prior registration.

### 5.8 Import Usage Check — All Imports Referenced

- `src/Availability/OpenCodeProviderAvailability.php:19–27` — all 7 `use` imports referenced (`OpenCodeGoProvider`, `OpenCodeZenProvider`, `ProviderAvailabilityInterface`, `WithHttpTransporterInterface`, `WithRequestAuthenticationInterface`, `Request`, `HttpMethodEnum`, plus two traits).
- `src/Providers/AbstractOpenCodeProvider.php:19–32` — all 9 imports hit (availability, directories, models, `AiClient`, `AbstractApiProvider`, DTOs, enums).
- `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:19–30` — all imports hit. No dead `use`.

### 5.9 Dependencies: No Unused Production Dependency

`composer.json:16–23` lists only `require-dev` (`wpcs`, `phpcodesniffer-installer`, `php-compatibility`, `phpunit`, `brain/monkey`). `require` is empty — correct for a runtime that depends on WordPress core + AI SDK. No prod dependency to prune. `psalm.xml` referenced in `.distignore:17` but absent (ghost exclude) — harmless.

---

## 6. Obsolete / Debug / Commented / Unreachable

### 6.1 Debug Code — None (PASS)

- No `var_dump`, `print_r`, `console.log`, `dd(`, `dump(` in `src/**` or `duoport-connect-for-opencode.php`.
- `error_log` appears only **guarded**: `duoport-connect-for-opencode.php:58–60` and `:65–67` inside `if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) { error_log(...)}` — correct transient-failure observability, not stray debug.
- No `WP_DEBUG` leakage into production front-end output beyond `error_log`.

### 6.2 Commented-Out Code — None (PASS)

`Grep "//\s*(if|for|while|function|\$|return|class)"` across `src/**` returned zero. The only `//` lines are:
- `src/Settings/Settings.php:119` `// Fallback: direct option delete for object-cache-less installs.` (explanatory, precedes `$wpdb->query`)
- `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:114` `// DeepSeek models return malformed JSON …` (explanatory)
- `src/Availability/OpenCodeProviderAvailability.php:47` `// Catalog is go or zen.` (param doc)
- `src/Metadata/ModelAllowlist.php:122` `// e.g. "Deepseek V4 …"` (doc)
- Plus `// phpcs:ignore …` directives (`:97`, `Settings:122–123`, `Models:63`) — tooling, not dead code.

No block-comment `/* … */` payload is commented code.

### 6.3 Dead Branches / Unreachable Code — None

- Closure at `duoport-connect-for-opencode.php:88–123` (`wp_connectors_init`) has layered guards: 4 `method_exists` early-return `:94–99`, `is_registered` guard `:101`, null/auth array guard `:104–106`, `try{/catch restore}`:110–121 — all reachable depending on registry shape. No branch is vacuously false because registry shape is environment-driven.
- `src/Availability/OpenCodeProviderAvailability.php:57–107` `isConfigured()` — `try getRequestAuthentication()` may throw (transient miss/bogus key): `:58–62` catches `Throwable` → `return false`. Transient cache hit at `:64–68` returns early. HTTP probe at `:88–104` maps `2xx→true, 429→true, 401+C реда…` etc. No unreachable segment; the `catch(Throwable $e)` at `:102` captures transport/Auth failures correctly.
- No `return`/`throw` followed by dead statement — `AbstractOpenCodeProvider.php:98` throw is terminal, no statement after.

### 6.4 Obsolete Compatibility Shims — None (Version Gates Are Active)

`version_compare(AiClient::VERSION,'1.2.0')` and `'1.3.0'` at `AbstractOpenCodeProvider.php:116,119` are live shims for optional `ProviderMetadata` ctor args (description, icon). They are not obsolete while supporting `AiClient <1.3.0`. If `Requires at least: 7.0` ever implies `AiClient >=1.3.0`, they become dead — but that implication is not asserted.

---

## 7. ModelAllowlist — Usage & Residual Risk

`src/Metadata/ModelAllowlist.php:25` (`final class ModelAllowlist`) provides `ALLOW:31` (go 16, zen 19), `FREE:77` (7 ids), and `isAllowed:96`, `isFree:108`, `displayName:120`.

- **All 3 public methods are used:** `isAllowed` at `AbstractOpenCodeModelMetadataDirectory.php:107`, `isFree` at `:111,125–126`, `displayName` at `:110` — no dead method.
- **Allowlist coverage:** `go ∩ zen = 9` (`glm-5{,.1,.2}, kimi-k{3,2.5,2.6,2.7-code}, deepseek-v4{ -pro, -flash}`) — intentional overlap; remaining go-only vs zen-only are catalog-specific.
- **Free invariant:** `FREE ⊆ zen ALLOW` and `FREE ∩ go ALLOW = ∅` — correct: free models exist only on Zen pay-as-you-go catalog; Go is subscription-only.
- **Probe models are allowlisted:** `deepseek-v4-flash ∈ go ALLOW` and `deepseek-v4-flash-free ∈ zen ALLOW` — `OpenCodeProviderAvailability.php:71` probe will pass `isAllowed` gating.
- **No unused allowlist entry detected by static grep** — but runtime API may return a superset; `show_all_models` toggle in `Settings.php:62` / `AbstractOpenCodeModelMetadataDirectory.php:87` bypasses allowlist, so stale entries degrade gracefully (labeled via `displayName` ucwords fallback).

---

## 8. Hook / Transient / Option Storage Cross-Check — No Dead Stores

| Store | Key | Writer | Reader | Cleaner | Dead? |
|---|---|---|---|---|---|
| `option` | `opencode_connector_settings` (`OPTION_NAME:27`) | `register_setting:36` via `Settings`, `sanitize:58` | `AbstractOpenCodeModelMetadataDirectory:87`, `Settings::render:147` | `uninstall.php:12` | Alive |
| `transient` | `opencode_connector_avail_go/zen` | `Availability::isConfigured:105 set_transient 5m` | `:65 get_transient` | `duoport:75–76`, `Settings:77–78,94–95`, `uninstall:13–14` | Alive; constant-key smell noted in §3.1 |
| `transient+option` | `ai_client_{VERSION}_{md5}_models` + `_transient_timeout_*` | SDK `AiClient::getCache()` (in-memory); fallback via `delete_transient` + `$wpdb->query DELETE` in `clearModelCaches:112–123` | SDK internals | `clearModelCaches` only, **not** `uninstall.php` | **Leak — §5.2** |
| `option` | `connectors_ai_opencode_go_api_key` (core-owned) | Core `wp-includes/connectors.php` | `Availability` via `WithRequestAuthenticationTrait` (core credential) | Core | Plugin only `delete_transient` bust on `:78–79` — no read/write, PASS |

No orphaned transient or option.

---

## 9. Recommendations (Priority Order)

1. **[P2] Delete or use `const VERSION`** — `duoport-connect-for-opencode.php:26` dead. Either `delete const VERSION` (prefer — header is canonical) or wire it into `createProviderMetadata` / enqueued script version.
2. **[P2] Fix uninstall leak** — mirror `Settings::clearModelCaches` in `uninstall.php:12` (see patch §5.2). Low volume but leaves stale rows for support debugging.
3. **[P3] Remove or redirect `assets/icon.svg`** — `assets/icon.svg` orphan vs `assets/images/opencode.svg` live at `AbstractOpenCodeProvider.php:120`. Deduplicate.
4. **[P3] Centralize availability transient keys** — extract `Transients::AVAIL_GO/ZEN` + `bustAvailability()` to collapse §3.1 trio; add test that asserts key constants are used everywhere.
5. **[P3] Extract `createRequest` helper** — trait/factory to collapse §2.2. Add coverage for metadata vs model request-option delta.
6. **[P4] Annotate `bustCachesAdd` params** — `src/Settings/Settings.php:91` add `phpcs:ignore UnusedFunctionParameter` to clarify hook-signature intent.
7. **[P4] Add comment for SDK-required duplication** — annotate Go/Zen concrete classes that duplication is intentional per SDK FQCN identity.

No action required for `ABSPATH` guards, version gates, probe `429`/`CreditsError` branches, `WP_DEBUG` error logging, or commented explanations.

---

## 10. Tooling Notes & Repro

```bash
# Duplicate similarity (production code only)
python3 -c "import difflib,pathlib; r=pathlib.Path('src');
for a,b in [('Providers/OpenCodeGoProvider.php','Providers/OpenCodeZenProvider.php'),
             ('Models/OpenCodeGoTextGenerationModel.php','Models/OpenCodeZenTextGenerationModel.php'),
             ('Metadata/OpenCodeGoModelMetadataDirectory.php','Metadata/OpenCodeZenModelMetadataDirectory.php')]:
  print(a,b,difflib.SequenceMatcher(None,(r/a).read_text(),(r/b).read_text()).ratio())"

# Dead VERSION
rg -n "\bVERSION\b" duoport-connect-for-opencode.php src/

# Repeated cache-bust
rg -n "delete_transient.*opencode_connector_avail" duoport-connect-for-opencode.php src/

# Unused asset
rg -n "icon\.svg|opencode\.svg" duoport-connect-for-opencode.php src/ && ls -l assets/icon.svg assets/images/opencode.svg

# Commented-code scan
rg -n "^\s*//\s*(if|for|while|function|\\\$|return|class)" src/
```

---

*Generated by agent-duplicate-dead — evidence lines are file:line as of current checkout. SDK-contract “unused” methods in §5.5 are false positives when SDK source is absent; verify against `wp-includes/ai-client` when available.*
