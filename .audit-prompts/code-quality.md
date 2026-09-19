# Audit: Code Quality & Standards

You are auditing the DuoPort Connector for OpenCode (PHP 8.2+, WPCS via `phpcs.xml`, PHPUnit + Brain Monkey in `tests/Unit`) for code quality. Product code must be fully lint-clean; `tests/` is excluded from WP sniffs by project policy.

## What to Check

### Coding Standards
- `vendor/bin/phpcs` clean on `duoport-connect-for-opencode.php`, `src/`, `uninstall.php` (Yoda conditions, snake_case outside `src/` exemptions, escaping, nonce/capability patterns)
- `declare(strict_types=1)` + `ABSPATH` guard in every PHP file
- No `method_exists()` probing on SDK magic methods (`__call`/`__callStatic` on AbstractEnum: enum factories, `is*()` capability checks) — probe backing constants or call inside try/catch instead
- No empty `catch` blocks; no blind `(string)` casts on non-stringable values (use `get_class()`/`get_debug_type()` fallbacks)

### Internationalization
- Every user-facing string uses text domain `duoport-connect-for-opencode` with translators comments on placeholders
- No hardcoded English in admin HTML outside translation functions

### Version Hygiene
- Main-file `Version:` header, `VERSION` const, readme `Stable tag` + Changelog (+ Upgrade Notice when user action is needed) all agree
- New code carries accurate `@since` tags — never invent a version number

### Tests
- New behavior ships with PHPUnit specs; SDK-touching tests use the shared `tests/Unit/Fixtures/SdkStubs.php` (no duplicate weaker stubs — execution order must not matter)
- Static source-scan tests assert structural rules (no shared `setting_name`, no credential option I/O)

## Output Format

Write findings to the output file in JSON Lines format:

```jsonl
{"type":"summary","text":"Audited {target_dir}. Found X issues."}
{"type":"issue","severity":"critical|important|minor","file":"relative/path","line":42,"message":"What the issue is","suggestion":"How to fix it","inline":false}
```

## Severity Guide

- **critical**: A check that would fail CI (`phpcs`, `phpunit`, compat) or break activation
- **important**: Standards violations, untranslated strings, version drift, test-order dependence
- **minor**: Docblock nits, naming inconsistencies, missing `@since`
