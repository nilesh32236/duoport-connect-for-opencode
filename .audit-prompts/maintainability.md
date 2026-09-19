# Audit: Maintainability & Modularity

You are auditing the DuoPort Connector for OpenCode (small PHP plugin: entry file + `src/` PSR-4 across Providers, Models, Metadata, Availability, Settings, Media, Http) for long-term maintainability. Flag code that works today but will rot.

## What to Check

### Hard Rules (must never regress — flag any violation as critical)
- Each catalog keeps its own connector setting (`connectors_ai_opencode_{go,zen}_api_key`); no aliasing/sharing via `wp_connectors_init`
- Bust hooks stay credential-blind (transient deletes only)
- Version header/const/readme stay in sync; OpenCode non-affiliation disclaimer stays in `readme.txt`

### Duplicated Logic (DRY)
- Same logic in 2+ places that must change together (provider ID/catalog-key/display-name mappings, URL building, transient key patterns, error messages)
- Copy-pasted blocks across `src/` that should be a shared helper or base-class method
- Duplicated SDK stubs across `tests/Unit/*Test.php` that belong in `tests/Unit/Fixtures/SdkStubs.php`
- When flagging, name ALL locations involved so the fix covers them in one change

### Oversized Units (Split Candidates)
- PHP methods longer than ~60 lines or classes longer than ~400 lines — propose a split point, not just "too long"
- Functions with 5+ parameters — propose a parameter object or split
- Deeply nested conditionals (3+ levels) — propose guard clauses or extraction

### Missing Seams & Coupling
- New `new Class` collaborators where constructor injection would allow testing
- Static state without reset (causes order-dependent test pollution)
- Test classes that bypass `MonkeyTestCase` setup/teardown

## Output Format

Write findings to the output file in JSON Lines format:

```jsonl
{"type":"summary","text":"Audited {target_dir}. Found X issues."}
{"type":"issue","severity":"critical|important|minor","file":"relative/path","line":42,"message":"What the issue is","suggestion":"How to fix it","inline":false}
```

## Severity Guide

- **critical**: Hard-rule regression (shared settings, credential I/O, version drift, disclaimer removal)
- **important**: Duplication forcing multi-spot edits, oversized units, test pollution risks
- **minor**: Small extractions, naming cleanups
