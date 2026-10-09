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
