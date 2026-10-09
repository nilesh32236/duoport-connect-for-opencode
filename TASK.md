# DuoPort Connector — Plugin Auditor Remediation

Source: `duoport-connector-for-opencode-0.1.8-plugin-auditor-report.pdf` (transcribed findings;
the PDF itself is not present on this filesystem — verified with `find / -name '*-plugin-auditor-report.pdf'`).

Baseline: repo `a57c0e5`, plugin version 0.1.8, License GPL-2.0-or-later (header), readme Stable tag 0.1.8,
`Tested up to: 7.1.2`.

Rule: verify every finding against current source before fixing. Do not assume the report's line
numbers match. Do not reintroduce a defect that is already fixed. Do not silence a scanner by
removing functionality.

| ID | Finding | Severity | Area | Status |
| --- | --- | --- | --- | --- |
| A1 | No `register_activation_hook()` detected despite add_option/update_option/$wpdb->query | Medium | Standards | PENDING VERIFY |
| A2 | No `LICENSE`/`LICENSE.txt` in plugin root | Medium | Standards | **CONFIRMED** — `ls LICENSE*` empty; header says GPL-2.0-or-later |
| A3 | Missing Plugin URI / Author URI | Low | Standards | **CONFIRMED** — header has neither |
| A4 | `Tested up to` 7.1.2 while 7.1.3 current at scan | Low | Standards | PENDING VERIFY (must test, not bump blindly) |
| A5 | `.gitattributes` in production ZIP | Low | Standards | **CONFIRMED** — `.distignore` does not list it |
| A6 | DB query in loop — `src/Settings/Settings.php:199` transient deletion | High | Performance | PENDING VERIFY |
| A7 | Accessible link text — `src/Settings/Settings.php:331` empty link text | Medium | Accessibility | PENDING VERIFY (likely FP: excerpt shows a translated `Get an API key` label) |

Statuses: PENDING VERIFY → CONFIRMED / FALSE POSITIVE / ALREADY FIXED → FIXED → VERIFIED.

## Verification round 2 — DuoPort A1, A4, A6, A7 (2026-10-09), verified directly

| ID | Verdict | Evidence |
| --- | --- | --- |
| A1 | **FALSE POSITIVE** | No `register_activation_hook` anywhere — but the plugin creates NO custom tables (no `dbDelta`, no `CREATE TABLE`), so there is no one-time setup for an activation hook to do. The flagged `add_option` is `add_action( 'add_option_' . OPTION_NAME, ... )` at Settings.php:49 — an ACTION registration, not the `add_option()` function. The flagged `$wpdb->query` (Settings.php:199/:202) runs inside a cache-bust callback fired by an option change, never on every request. Nothing to move. |
| A4 | **CONFIRMED — fixed** | Current stable WP is 7.1.3 (authoritative `api.wordpress.org/core/version-check/1.7/`). No WP-version CI matrix exists (only a PHP `testVersion 8.2-` PHPCompatibilityWP run), so the evidence is empirical: the live site runs WP **7.1.3** with DuoPort active, and a load probe (`include_once` the plugin + `do_action('plugins_loaded')` under WP 7.1.3) reports "plugin included OK / errors during load: none". `Tested up to` bumped 7.1.2 → 7.1.3. `Tested up to` is a compatibility field, not a version, so AGENTS.md rule 3's version-sync list is unaffected (Stable tag stays 0.1.9). |
| A6 | **FALSE POSITIVE** | The loop iterates a hardcoded 2-element array (`OpenCodeGoModelMetadataDirectory`, `OpenCodeZenModelMetadataDirectory`) — a constant, not a record set — so query count scales with 2 and is independent of the number of sites or keys. The `$wpdb->query` calls are an explicit **fallback for object-cache-less installs**, taken only AFTER the WP transient APIs (`delete_transient` / `delete_site_transient`) and the object-cache `delete()`. On multisite it targets the correct tables: `$wpdb->options` for the current site plus `$wpdb->sitemeta` gated by `is_multisite()`. Credential-blind, per the AGENTS.md hard rule. |
| A7 | **FALSE POSITIVE** | Settings.php:334 — the anchor's text node is `esc_html_e( 'Get an API key', 'duoport-connect-for-opcode' )`, a translated non-empty label. The scanner matched the bare constant `Catalog::AUTH_URL` at Catalog.php:103 and treated the constant's own line as an "empty link", never rendering the page. Exactly as the brief suspected. |

## FINAL TALLY — DuoPort (7 findings)
CONFIRMED AND FIXED (4): A2 (no LICENSE), A3 (no Plugin/Author URI), A4 (Tested up to 7.1.3, evidence-based),
A5 (.gitattributes in the ZIP). FALSE POSITIVE (3): A1, A6, A7.
