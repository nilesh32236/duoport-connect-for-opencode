# Audit: Security & WordPress Hardening

You are auditing the DuoPort Connector for OpenCode (WordPress 7.0+ AI provider plugin, PHP 8.2+, `src/` PSR-4, no runtime dependencies) for security vulnerabilities. This plugin handles third-party API keys — credential handling is the top priority.

## What to Check

### Credential Handling (HIGHEST PRIORITY)
- Never read or write any `connectors_ai_*` option value (`get_option`/`update_option` on credential options is forbidden — cache-bust hooks must be transient-deletes only)
- No API keys in `error_log()`, exceptions, REST responses, admin HTML, or transients
- Keys only ever flow through core's Connectors settings + AI Client `ApiKeyRequestAuthentication`
- No hardcoded keys, test keys, or example keys anywhere in shipped code

### Nonce & Capability Verification
- Settings page (`Settings::render`, `Settings::register`) requires `manage_options`; `register_setting` uses a sanitize callback
- Any state-changing action verifies capability; no unauthenticated writes

### Sanitization & Escaping
- Every output escaped (`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`) — never raw echo
- `sanitize_file_name()` on any filename touching uploads; extension forced from the MIME allowlist, never from user input
- `$wpdb->prepare()` for ALL dynamic queries (uninstall + transient cleanup) with `phpcs:ignore` justification comments present

### Media Upload Attack Surface (`src/Media/`)
- MIME allowlist enforced AND sniffed content must match the claim (`detect_mime`)
- Byte cap enforced before upload; `upload_files` capability checked first
- No SVG or executable types; attachment metadata generated via core functions

### External Requests
- All OpenCode calls go through the SDK transporter (auth, timeouts, error mapping); no raw `wp_remote_*`/curl with hand-rolled auth
- Availability probe is cheap (`max_tokens: 1`), transient-cached with stampede lock, and never leaks the key

## Output Format

Write findings to the output file in JSON Lines format:

```jsonl
{"type":"summary","text":"Audited {target_dir}. Found X issues."}
{"type":"issue","severity":"critical|important|minor","file":"relative/path","line":42,"message":"What the issue is","suggestion":"How to fix it","inline":false}
```

## Severity Guide

- **critical**: Credential reads/writes, unescaped output, capability bypass, MIME spoof acceptance, SQL injection
- **important**: Missing sanitization, overly broad permissions, API key in log, oversized upload cap
- **minor**: Non-blocking config issues, missing return types on security functions
