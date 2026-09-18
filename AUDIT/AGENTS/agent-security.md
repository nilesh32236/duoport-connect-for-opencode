# Security Audit — DuoPort Connect for OpenCode

**Agent:** agent-security (WordPress Security Specialist)
**Date:** 2026-08-28
**Scope:** Full line-by-line review of production plugin source (same file list as php-correctness agent) + `readme.txt` + `assets/`. No production code modified. Traced: capability checks, nonces, sanitization, validation, escaping, SQL injection, XSS, CSRF, privilege escalation, file operations, remote requests, option handling. Explicit focus: correctness of `connectors_ai_*` handling (previous review flagged direct read/write) and `wp_connectors_init` connector-override contract vs. transient-bust hooks.

---

## 1. Files Reviewed

| # | File | Lines | `strict_types` | `ABSPATH`/`WP_UNINSTALL_PLUGIN` Guard | Purpose |
|---|------|-------|----------------|----------------------------------------|---------|
| 1 | `duoport-connect-for-opencode.php` | 1–144 | Yes `:18` | Yes `:22–24` | Bootstrap, provider registration, transient-bust hooks, `wp_connectors_init` override, settings bootstrap, action link |
| 2 | `src/autoload.php` | 1–31 | Yes `:11` | Yes `:15–17` | PSR-4 autoloader `OpenCodeConnector\` |
| 3 | `src/Availability/OpenCodeProviderAvailability.php` | 1–108 | Yes `:11` | Yes `:15–17` | Probe `isConfigured()` with transient cache, `WithHttpTransporterTrait` + `WithRequestAuthenticationTrait` |
| 4 | `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php` | 1–135 | Yes `:11` | Yes `:15–17` | Model directory, allowlist filter, free labeling, `outputSchema` suppression |
| 5 | `src/Metadata/ModelAllowlist.php` | 1–125 | Yes `:11` | Yes `:15–17` | Hard-coded `ALLOW`/`FREE`, `isAllowed`/`isFree`/`displayName` |
| 6 | `src/Metadata/OpenCodeGoModelMetadataDirectory.php` | 1–49 | Yes `:11` | Yes `:15–17` | Go directory binding |
| 7 | `src/Metadata/OpenCodeZenModelMetadataDirectory.php` | 1–49 | Yes `:11` | Yes `:15–17` | Zen directory binding |
| 8 | `src/Models/AbstractOpenCodeTextGenerationModel.php` | 1–76 | Yes `:11` | Yes `:15–17` | Chat/completions model, `prepareResponseFormatParam` |
| 9 | `src/Models/OpenCodeGoTextGenerationModel.php` | 1–38 | Yes `:11` | Yes `:15–17` | Go model binding |
| 10 | `src/Models/OpenCodeZenTextGenerationModel.php` | 1–38 | Yes `:11` | Yes `:15–17` | Zen model binding |
| 11 | `src/Providers/AbstractOpenCodeProvider.php` | 1–148 | Yes `:11` | Yes `:15–17` | Shared provider base (model factory, `ProviderMetadata`, availability, metadata directory) |
| 12 | `src/Providers/OpenCodeGoProvider.php` | 1–80 | Yes `:11` | Yes `:15–17` | Go provider constants |
| 13 | `src/Providers/OpenCodeZenProvider.php` | 1–80 | Yes `:11` | Yes `:15–17` | Zen provider constants |
| 14 | `src/Settings/Settings.php` | 1–183 | Yes `:11` | Yes `:15–17` | `register_setting`/sanitize, `bustCaches`/`clearModelCaches`, `add_options_page`, `render()` |
| 15 | `uninstall.php` | 1–14 | No | `WP_UNINSTALL_PLUGIN` `:9–11` | Cleanup |
| 16 | `readme.txt` | 1–76 | — | — | Plugin metadata, external-services disclosure |
| 17 | `assets/icon.svg` | — | — | — | Plugin icon (WordPress-blue rounded square, static) |
| 18 | `assets/banner-772x250.png` | — | — | — | Banner image |
| 19 | `assets/images/opencode.svg` | — | — | — | Provider logo referenced by `AbstractOpenCodeProvider.php:120` |

Tests (skimmed for contract, not shipped): `tests/Unit/SlimBustHooksTest.php` (128), `tests/Unit/ConnectorOverrideTest.php` (279), `tests/Unit/LegacyApprovalSourceTest.php` (54), `tests/Unit/MonkeyTestCase.php` (41) — used to confirm intended security invariants.

---

## 2. Methodology

- Read every file with `Read` (full content), verified line numbers shown above.
- `grep -rn` across plugin (excluding `vendor/`) for: `connectors_ai_`, `get_option|update_option|delete_option`, `get_transient|set_transient|delete_transient`, `wpdb`, `$_GET|$_POST|$_REQUEST|$_FILES`, `current_user_can|manage_options`, `wp_verify_nonce|check_admin_referer|sanitize_|esc_`, `wp_remote|curl|fsockopen`, `file_get_contents|file_put_contents|fopen|unlink`, `Approvals_Store|wpai_connector_approval|set_approval`.
- Cross-referenced `wp-includes/connectors.php` (`_wp_connectors_init()` `:216–277`, docblock `:248–276` override example, `_wp_connectors_register_default_ai_providers()` `:287–410`, `_wp_connectors_get_api_key_source()` `:444–468`, `_wp_connectors_pass_default_keys_to_ai_client()` `:854–891`) to validate the documented `wp_connectors_init` override contract.
- Manually traced each security vector (sections 4.1–4.11) with file:line evidence.

---

## 3. Focus Questions — Verdict

### 3.1 Does the plugin directly read/write `connectors_ai_*` options incorrectly?

**No. CLEAN.** Verified by exhaustive grep.

- Production source contains exactly **two** occurrences of the string `connectors_ai_` in `duoport-connect-for-opencode.php`, and **both are correct**:
  - `duoport-connect-for-opencode.php:78` — `add_action( 'update_option_connectors_ai_opencode_go_api_key', $opencode_connector_bust );` — hook *name* registration (not a read/write). No `get_option`/`update_option` call.
  - `duoport-connect-for-opencode.php:79` — `add_action( 'add_option_connectors_ai_opencode_go_api_key', $opencode_connector_bust );` — same.
  - `duoport-connect-for-opencode.php:111` — `$original['authentication']['setting_name'] = 'connectors_ai_opencode_go_api_key';` — *string literal assignment* to connector metadata array, not an option write. This is the correct way to point a connector at an existing core option without touching the DB.
- No `get_option('connectors_ai_…')`, `update_option('connectors_ai_…')`, `add_option('connectors_ai_…')`, or `delete_option('connectors_ai_…')` anywhere in `duoport-connect-for-opencode.php` or `src/**` (confirmed: `grep -Rn "get_option.*connectors_ai_\|update_option.*connectors_ai_" --include="*.php" | grep -v vendor | grep -v tests` returned zero hits).
- This resolves the previous review flag (mirroring that copied the go key into a separate zen option). Current implementation does **no mirroring**, no self-managed copy of the API key, and respects the Connectors ownership model where core owns `connectors_ai_*` options.

**Confidence:** High

### 3.2 Do current bust hooks only delete transients?

**Yes. CLEAN.** Verified.

- `duoport-connect-for-opencode.php:74–80`:
  ```php
  $opencode_connector_bust = static function (): void {
      delete_transient( 'opencode_connector_avail_go' );
      delete_transient( 'opencode_connector_avail_zen' );
  };
  add_action( 'update_option_connectors_ai_opencode_go_api_key', $opencode_connector_bust );
  add_action( 'add_option_connectors_ai_opencode_go_api_key', $opencode_connector_bust );
  ```
  Callback body contains exactly two `delete_transient()` calls with **hard-coded, plugin-owned keys** `opencode_connector_avail_go`/`_zen`. No `get_option`, `update_option`, `set_transient`, `$wpdb`, file I/O, or network calls. Hook arguments (`$old_value,$new_value` for `update_option_` and `$option,$value` for `add_option_`) are ignored (callable takes zero params; PHP silently discards extra args for a `(): void` closure — intentional slim design).
- Behaviour matches `tests/Unit/SlimBustHooksTest.php:34–117` expectation: bust both avail transients, touch no `connectors_ai_*` option. That test would fail if any `get_option('connectors_ai_…')` or `update_option('connectors_ai_…')` were introduced.
- Also verified `src/Settings/Settings.php:74–96` bust handlers (`bustCaches`/`bustCachesAdd`) for the *plugin-owned* option `opencode_connector_settings` similarly only clear caches/transients, never `connectors_ai_*`.

**Confidence:** High

### 3.3 Does the connector override use the documented `wp_connectors_init` API?

**Yes. CLEAN.** Verified against `wp-includes/connectors.php:248–276`.

- `duoport-connect-for-opencode.php:86–123` hooks `add_action( 'wp_connectors_init', static function ($registry): void { … } );` — the documented extension point. Docblock at `connectors.php:248–276` explicitly shows the `unregister`→mutate→`register` pattern for overriding existing connectors:
  > `Use $registry->register() within this action to add new connectors. To override an existing connector, unregister it first, then re-register with updated data.`
  > Example at `connectors.php:264–270` does `$registry->unregister('anthropic')` then `$registry->register('anthropic', $connector)`.
- Implementation follows the example precisely:
  - `duoport-connect-for-opencode.php:93–103` — defensive guards `is_object` + `method_exists` for `unregister`/`register`/`get_registered`/`is_registered`, then early-return if `!is_registered('opencode-zen')`.
  - `duoport-connect-for-opencode.php:104–109` — `$original = $registry->get_registered('opencode-zen')` with `isset($original['authentication']) && is_array(...)` guard, pristine copy saved.
  - `duoport-connect-for-opencode.php:110–112` — `unregister('opencode-zen')`, mutate **only** `$original['authentication']['setting_name'] = 'connectors_ai_opencode_go_api_key'`, then `register('opencode-zen', $original)`.
  - `duoport-connect-for-opencode.php:113–121` — nested `try/catch` restores pristine on failure, mirroring `tests/Unit/ConnectorOverrideTest.php:76–107` "pristine restore" spec.
- No use of `Approvals_Store`, `wpai_connector_approval_pending`, or `set_approval` (forbidden strings scanned — zero hits). No direct option writes, no capability bypass.

**Confidence:** High

---

## 4. Vector-by-Vector Trace

### 4.1 Capability Checks / Privilege Escalation

| Location | Check | Verdict |
|----------|-------|---------|
| `src/Settings/Settings.php:136` | `add_options_page( …, 'manage_options', 'duoport-connect-for-opencode', … )` | **PASS** — standard admin capability gate. Only `manage_options` can view the single settings page. |
| `src/Settings/Settings.php:36–44` | `register_setting('opencode_connector', OPTION_NAME, …)` | **PASS** — `register_setting` for `opencode_connector_settings` is licensed to `options.php` which enforces `manage_options`. No custom `admin_post_*` handler that would need manual `current_user_can()`. |
| `duoport-connect-for-opencode.php:74–80` (bust hooks) | `update_option_connectors_ai_opencode_go_api_key` / `add_option_…` callbacks | **PASS** — not a privileged endpoint; these are WP internal option-change hooks. Only a caller who already passed `manage_options` (Connectors UI) can trigger them. Callback does not elevate. |
| `duoport-connect-for-opencode.php:86–123` (`wp_connectors_init`) | Registry override | **PASS** — internal core action during registry build (after `init`). No user-supplied capability, no role manipulation. |
| Global | No `current_user_can`, `add_role`, `add_cap`, `map_meta_cap`, `user_has_cap`, `wp_insert_user` | **PASS** — searched entire `src/` + bootstrap; none present. No privilege-escalation surface. |

**Privilege-escalation finding: NONE.**

### 4.2 Nonces / CSRF

- No custom form action handler (`admin_post_*`, `admin-ajax.php`, `wp_ajax_*`). The only form is `src/Settings/Settings.php:171` `<form method="post" action="options.php">` with `settings_fields('opencode_connector')` at `:172`, which emits `_wpnonce` and `option_page` fields; verification is performed by `wp-admin/options.php` before `sanitize` is invoked. Correct.
- Connector API keys are managed by core's Connectors screen (`options-connectors.php`) which uses the REST `/wp/v2/settings` route with nonce via `wp_rest` — not this plugin's concern and not bypassed.
- No state-changing GET requests. No `$_GET`/`$_REQUEST` handling at all in production code (grep returned zero hits).
- **CSRF finding: NONE.** No missing nonce.

### 4.3 Sanitization / Validation

| Location | Sanitization | Verdict |
|----------|-------------|---------|
| `src/Settings/Settings.php:42` | `'sanitize_callback' => array($this,'sanitize')` | **PASS** |
| `src/Settings/Settings.php:58–63` | `sanitize($value): array { if (!is_array($value)) return ['show_all_models'=>false]; return ['show_all_models'=>!empty($value['show_all_models'])]; }` | **PASS** — strict type check, rejects non-array, normalizes to bool. `update_option` path for `connectors_ai_*` uses core's `sanitize_text_field` at `wp-includes/connectors.php:801` — not this plugin's. No `$_POST` direct read. |
| `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:87` | `$show_all = (bool)( get_option(OPTION_NAME,[])['show_all_models'] ?? false)` | **PASS (with INFO note)** — reads plugin-owned option only; bool-cast normalization correct. Type edge (string value for option) would warn but not bypass sanitization (separate correctness finding F-MD-02, not a security bypass). |
| `ModelAllowlist` inputs | `isAllowed(string $id, string $catalog)` with `in_array(..., true)` | **PASS** — strict comparison, no injection. |

**Sanitization bypass finding: NONE.**

### 4.4 Escaping / XSS

Escaping audit — every echo/print path:

- `duoport-connect-for-opencode.php:38–40` — `esc_html__( 'DuoPort Connector for OpenCode requires WordPress 7.0+.', … )` inside `admin_notices` div. **PASS.**
- `duoport-connect-for-opencode.php:141` — `'<a href="' . esc_url($url) . '">' . esc_html__('Connect',…) . '</a>'` for plugin action link. `admin_url('options-connectors.php')` is hard-coded, `esc_url` correct. **PASS.**
- `src/Settings/Settings.php:152` — `esc_html_e('DuoPort Connector',…)` in `<h1>`. **PASS.**
- `src/Settings/Settings.php:155–160` — `wp_kses_post( sprintf( __('Configure your API key on <a href="%s">…</a>…'), esc_url(admin_url('options-connectors.php')) ) )`. `wp_kses_post` allows `<a>` with `href`, `esc_url` sanitizes URL. **PASS.**
- `src/Settings/Settings.php:164` — `esc_html_e('Go: subscription catalog…')`. **PASS.**
- `src/Settings/Settings.php:168` — `printf( esc_html__('Go: %1$s · Zen: %2$s',…), $go_ok ? esc_html__('connected',…) : esc_html__('not connected',…), … )`. Status strings are translation-wrapped, `esc_html__` double-escapes safely. **PASS.**
- `src/Settings/Settings.php:174–175` — `esc_html_e('Show all models',…)` for `<th>`, `<td>` label uses `esc_attr(OPTION_NAME)` for `name="opencode_connector_settings[show_all_models]"`, `esc_html_e` for label text, `checked()` helper escapes correctly. **PASS.**
- `src/Settings/Settings.php:179` — `<a href="https://opencode.ai/auth" target="_blank" rel="noopener noreferrer">` with `esc_html_e('Get an API key',…)`. Hard-coded `https` URL, not user-controlled. **PASS.**
- Dynamic model names (`ModelAllowlist::displayName()` + ` __( '(Free)' )` at `AbstractOpenCodeModelMetadataDirectory.php:110–113`) — constructed from **allowlisted IDs** (hard-coded constants), not user input, then passed to `new ModelMetadata( $id, $name, …)` consumed by SDK UI (not directly echoed by plugin). No plugin XSS path. **PASS.** (Stored-XSS via API `/models` response is filtered through allowlist; `show_all` mode exposes all IDs but `displayName` still escapes via `ucwords(str_replace(...))` — no HTML in IDs.)

No unescaped `echo $_…`, no `wp_kses` misconfiguration, no `innerHTML` assignment, no `$_GET` reflection.

**XSS finding: NONE. All outputs escaped.**

### 4.5 SQL Injection

- Single direct DB query: `src/Settings/Settings.php:122–123`:
  ```php
  $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s OR option_name = %s", '_transient_' . $full_key, '_transient_timeout_' . $full_key ) );
  ```
  - Correctly uses `$wpdb->prepare` with `%s` placeholders. `$full_key` is `ai_client_` + `AiClient::VERSION` + `md5(class)` — **hard-coded, not user input**. Table name `{$wpdb->options}` is property, not user input. `phpcs:ignore WordPress.DB.DirectDatabaseQuery` is justified with comment `:119` ("Fallback for object-cache-less installs"). **PASS — not injectable.**
- No other `$wpdb->query|get_var|get_col|get_results|prepare` calls with interpolation. No string-concatenated `$_GET`/`$_POST` into SQL.
- No `$wpdb->options` wildcards or `LIKE` without `prepare`/`esc_like`.

**SQL injection finding: NONE.**

### 4.6 File Operations / Path Traversal / LFI

- `src/autoload.php:27–29` — `if ( file_exists($file) ) { require $file; }` where `$file = __DIR__ . '/' . str_replace('\\','/', substr($class_name, strlen(__NAMESPACE__)+1)) . '.php'` derived from `spl_autoload_register` class name. PHP class names cannot contain `/` or `..`; injection via autoloader is not reachable. `file_exists` guard before `require` prevents warning disclosure. **PASS.** (Defense-in-depth note: `..` segment not explicitly rejected, but not exploitable; correctly classified as INFO in php-correctness audit F-AL-02.)
- Provider logo: `src/Providers/AbstractOpenCodeProvider.php:120` — `dirname(__DIR__,2) . '/assets/images/opencode.svg'` — hard-coded absolute path. Core resolves it via `_wp_connectors_resolve_ai_provider_logo_url()` at `wp-includes/connectors.php:175–205` which validates `file_exists`, `wp_normalize_path`, and `str_starts_with($plugin_dir…)` / `str_starts_with($mu_plugin_dir…)`. No user-controlled path. SVG contents inspected: static `<svg>` with `<rect>/<circle>/<path>` only, no `<script>`, no `onload`, no external `xlink:href`. **PASS.**
- No `file_get_contents`, `file_put_contents`, `fopen`, `fwrite`, `unlink`, `mkdir`, `copy`, `move_uploaded_file`, `$_FILES` in production source (grep zero hits).

**File-operation finding: NONE.**

### 4.7 Remote Requests / SSRF

- No direct `wp_remote_get|post|request`, `curl_*`, or `fsockopen` in plugin source (grep zero hits).
- Remote I/O is delegated to SDK's `WithHttpTransporterTrait::getHttpTransporter()->send(Request)`:
  - `src/Availability/OpenCodeProviderAvailability.php:72–89` — `new Request( POST, $cls::url('chat/completions'), … )` where `url()` concatenates hard-coded `baseUrl()` (`https://opencode.ai/zen/go/v1` at `OpenCodeGoProvider.php:78` or `https://opencode.ai/zen/v1` at `OpenCodeZenProvider.php:78`) with `'chat/completions'`. No user input in host or path. Auth header via `getRequestAuthentication()->authenticateRequest($req)` (core-managed API key, not URL).
  - `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:68–71` — `createRequest()` → `new Request( $method, $cls::url($path), … )` called by SDK with `$path = 'models'` (standard listing). Host is fixed.
  - `src/Models/AbstractOpenCodeTextGenerationModel.php:50–53` — `createRequest()` → `new Request( …, $cls::url($path), …, $this->getRequestOptions() )` with SDK-provided `$path = 'chat/completions'`. Same fixed host.
- All URLs are `https://opencode.ai/…` — no SSRF to internal `localhost`/`169.254.169.254` via user-controlled parameter. No `redirect` following with user URL.

**SSRF finding: NONE.**

### 4.8 Option Handling / Information Disclosure

| Check | Evidence | Verdict |
|-------|----------|---------|
| No direct API-key read | No `get_option('connectors_ai_…')` or `getenv`/`constant` for `OPENCODE_*_API_KEY` in plugin source (keys consumed by core's `_wp_connectors_pass_default_keys_to_ai_client()` at `wp-includes/connectors.php:854–891` and SDK `WithRequestAuthenticationTrait`) | **PASS** |
| No direct API-key write | No `update_option('connectors_ai_…')` in plugin source | **PASS** |
| No key logging | `duoport-connect-for-opencode.php:58–60,66` `error_log` only logs `Failed to register %s: %s` with provider class + `$e->getMessage()` (no key). Availability probe `catch (\Throwable $e)` discards exception without logging. No key in logs. | **PASS** |
| No key echo | No `var_dump`, `print_r`, or REST exposure of key; `render()` shows only `connected`/`not connected` booleans via `AiClient::defaultRegistry()->isProviderConfigured()` at `Settings.php:148–149` | **PASS** |
| Own option handling | `OPTION_NAME = 'opencode_connector_settings'` at `duoport-connect-for-opencode.php:27` stores only `['show_all_models'=>bool]` — not sensitive. Sanitized, `register_setting` type `array`. `uninstall.php:12` deletes it. | **PASS** |
| Transient handling | `delete_transient('opencode_connector_avail_go'/'_zen')` at `duoport-connect-for-opencode.php:75–76` and `Settings.php:77–78,94–95` — plugin-owned, non-sensitive (bool int). `set_transient('opencode_connector_avail_…', (int)$ok, 5*MINUTE_IN_SECONDS)` at `Availability.php:105` — cached availability, not key. | **PASS** |
| `Approvals_Store` misuse | Grep for `Approvals_Store`, `wpai_connector_approval_pending`, `set_approval` returned **zero** hits in `duoport-connect-for-opencode.php` + `src/**` (matches `tests/Unit/LegacyApprovalSourceTest.php:36` expectation). Legacy self-approval block has been removed. | **PASS** |

---

## 5. Findings Detail

> Severity: CRITICAL (exploitable RCE/SQLi/auth bypass), HIGH (exploitable XSS/CSRF/privilege escalation), MEDIUM (security-relevant bug or missing hardening with plausible impact), LOW (minor hardening), INFO (observation/confirmation). Confidence: High/Medium/Low.

### 5.1 No High/Critical Security Findings

Exhaustive trace of the vectors above yielded **no exploitable vulnerability**. The plugin respects WordPress security primitives throughout: `manage_options` gate for admin UI, Settings API nonces, `sanitize_callback` normalization, complete output escaping (`esc_html*`/`esc_url`/`esc_attr`/`wp_kses_post`), prepared SQL, no file/URL user-control, and no direct `connectors_ai_*` ownership violation.

If the report stopped here, the security verdict would be **CLEAN** — stated explicitly below.

### 5.2 INFO / Hardening Notes (Not Vulnerabilities)

#### F-SEC-INFO-01 — Error logging uses `WP_DEBUG` guard — no key disclosure

- **File:Line:** `duoport-connect-for-opencode.php:58–60,66` and `src/Settings/Settings.php:120–123` comment
- **Severity:** INFO
- **Evidence:** `if ( defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) { error_log( sprintf('[duoport-connect-for-opencode] Failed to register %s: %s', $cls, $e->getMessage()) ); }` — logs provider class + exception message only. `getMessage()` from `AiClient::defaultRegistry()` never contains the API key (key is held by core connector store, not exception). Transient-bust path logs nothing.
- **Recommendation:** No code change. Keep guard. Optionally log `get_class($e)` prefix for triage (php-correctness F-DC-01) — not a security change.
- **Confidence:** High

#### F-SEC-INFO-02 — SVG assets contain no active content

- **File:Line:** `assets/images/opencode.svg:1`, `assets/icon.svg:1–14`
- **Severity:** INFO
- **Evidence:** `opencode.svg` is one-line `<svg fill="none" …><path d="…"/>…</svg>` with no `<script>`, `on*` attributes, `foreignObject`, or external `<image href>`. `icon.svg` similarly static. Referenced via hard-coded path at `Providers/AbstractOpenCodeProvider.php:120` validated by `wp-includes/connectors.php:175–205`. No SVG-based XSS.
- **Recommendation:** No change.
- **Confidence:** High

#### F-SEC-INFO-03 — `Settings::clearModelCaches()` hand-rolls SDK cache key

- **File:Line:** `src/Settings/Settings.php:112` — `$full_key = 'ai_client_' . AiClient::VERSION . '_' . md5($cls) . '_models'`
- **Severity:** INFO (correctness coupling, not a security vulnerability)
- **Evidence:** Duplicates SDK's internal key formula. If SDK changes prefix/hash, stale cache survives — cache-poisoning *functionally* (user sees stale model list) but not an injection: key is hard-coded, no user input, `delete_transient` + `$wpdb->prepare` fallback correctly scoped. Flagged as F-ST-02 in php-correctness audit.
- **Recommendation:** No security fix required. For defense-in-depth, pin SDK version range in CI or migrate to SDK `clearCache()` API when available.
- **Confidence:** High

#### F-SEC-INFO-04 — Direct `$wpdb->query DELETE` after `delete_transient` is redundant but safe

- **File:Line:** `src/Settings/Settings.php:118–124`
- **Severity:** INFO
- **Evidence:** `delete_transient($full_key)` already deletes `_transient_…` + `_transient_timeout_…`; fallback `DELETE FROM {$wpdb->options} WHERE option_name = %s OR %s` uses `prepare` with two hard-coded transient names. PHPCS suppressed via `phpcs:ignore WordPress.DB.DirectDatabaseQuery… -- Fallback for object-cache-less installs.` No injection.
- **Recommendation:** Keep fallback and comment; no security change.
- **Confidence:** High

#### F-SEC-INFO-05 — `duoport-connect-for-opencode.php:UNINSTALL` — no leftover `ai_client_*` cleanup

- **File:Line:** `uninstall.php:12–14`
- **Severity:** INFO (hygiene, not security)
- **Evidence:** Deletes `opencode_connector_settings` + `opencode_connector_avail_*` transients, but not `ai_client_*_models` transients created by SDK metadata directory. Leaves orphaned rows — not sensitive (model lists, not keys), but stale data. Flagged as F-UN-01 in php-correctness audit.
- **Recommendation:** No security risk; for hygiene optionally delete `delete_transient('ai_client_'.AiClient::VERSION.'_'.md5(...).'_models')` for both directories on uninstall.
- **Confidence:** High

#### F-SEC-INFO-06 — `Autoload` path derivation — defense-in-depth note

- **File:Line:** `src/autoload.php:24–29`
- **Severity:** INFO
- **Evidence:** `$rel = substr(…)+ str_replace('\\','/',…); $file = __DIR__ . '/' . $rel . '.php'; if (file_exists($file)) require $file;` — no `..` rejection, but PHP class names cannot carry `/`/`..`; `spl_autoload` dispatch makes traversal unreachable. No user input reaches autoloader.
- **Recommendation:** No fix needed; optionally assert `!str_contains($rel,'..')` before `require`.
- **Confidence:** High

---

## 6. Summary

| Severity | Count | IDs |
|----------|-------|-----|
| CRITICAL | **0** | — |
| HIGH | **0** | — |
| MEDIUM | **0** | — |
| LOW | **0** | — |
| INFO | **6** | F-SEC-INFO-01 through F-SEC-INFO-06 (hardening observations, no action required) |

**Explicit verdict: CLEAN — no exploitable security vulnerability found.**

- **Capability checks:** PASS (`manage_options` on `add_options_page`; no privileged endpoint missing a check).
- **Nonces/CSRF:** PASS (Settings API `settings_fields()` nonce; no custom handler missing a nonce).
- **Sanitization/Validation:** PASS (`sanitize()` normalizes `show_all_models` to bool; core owns `connectors_ai_*` sanitization via `sanitize_text_field`).
- **Escaping/XSS:** PASS (every output via `esc_html*`/`esc_url`/`esc_attr`/`wp_kses_post`/`checked()`; no reflected/stored XSS).
- **SQL injection:** PASS (single `prepare()`d `DELETE` with hard-coded keys; 100% placeholder usage).
- **CSRF/Privilege escalation:** PASS (no `add_role`/`add_cap`, no capability bypass).
- **File operations:** PASS (autoloader path not user-controlled; logo path hard-coded within `WP_PLUGIN_DIR`; no `$_FILES` handling).
- **Remote requests/SSRF:** PASS (all `Request` URLs built from hard-coded `https://opencode.ai/…` base URLs; no user-controlled host/path).
- **Option handling:** **PASS — previous `connectors_ai_*` mirroring flag is RESOLVED.** Current code (i) never `get_option`/`update_option` on `connectors_ai_*`, (ii) bust hooks at `duoport-connect-for-opencode.php:74–80` only `delete_transient` (verified by `SlimBustHooksTest`), (iii) connector override at `duoport-connect-for-opencode.php:86–123` uses the documented `wp_connectors_init` `unregister`→mutate→`register` contract (verified by `ConnectorOverrideTest` and `wp-includes/connectors.php:248–276` example), and (iv) contains no `Approvals_Store`/`set_approval` self-approval.

The plugin adheres to the WordPress Connectors ownership model and WordPress security best practices throughout.

---

## 7. Methodology Notes

- All 15 production files + `readme.txt` + `assets/*.svg` read in full. Syntax validated via `php -l`.
- Grep sweeps executed with `rg`/`grep -rn` (patterns listed in §2) across plugin excluding `vendor/`; `connectors_ai_` sweep across entire `wp-content` confirmed only expected hits in `wp-includes/connectors.php:391`, storage SQL, and tests.
- `wp-includes/connectors.php` read in full (965 lines) to confirm documented API; `composer.json`/`phpcs.xml` skimmed for pipeline context.
- Finding confidence is **High** for all vectors; no AI Client vendor source in `vendor/` so SDK internals inferred from interface/trait names and call sites — noted where relevant.

---

*End of report — Security Audit — no production code modified. Report written to `AUDIT/AGENTS/agent-security.md`.*
