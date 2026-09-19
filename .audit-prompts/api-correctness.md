# Audit: API Correctness (OpenCode + WP AI Client)

You are auditing the DuoPort Connector for OpenCode against the live OpenCode API and the WordPress AI Client SDK. Claims must be verified against real behavior, not assumed.

## What to Check

### Allowlist Accuracy
- Every ID in `ModelAllowlist::ALLOW` exists in the live `/models` catalogs (`https://opencode.ai/zen/go/v1/models`, `https://opencode.ai/zen/v1/models`); flag stale IDs (they fail at generation time)
- Every allowlisted ID works via `chat/completions` with a system prompt + content (not just ping) — models served only on `/responses` or `/messages` endpoints must NOT be default-allowlisted
- Free labels (`FREE` const) match actually-free models; counts in `readme.txt`/README match the arrays

### Probe Correctness (`src/Availability/`)
- Probe sends the required `x-opencode-session` header (Go rejects headerless requests with 400 MissingSessionID)
- Probe models discriminate authentication (paid model: valid key → 200/429/CreditsError; bad key → other 401s) — never a model that fails closed on upstream outages
- 429 handling, lock/transient hygiene, and fail-open behavior on exceptions

### SDK Contract Drift
- Every `WordPress\AiClient\...` class/method/constant/enum referenced in `src/` still exists in the bundled SDK surface (factories may be magic — verify, don't assume)
- `ProviderMetadata` constructor arity matches the installed SDK version gating
- Capability/option enums used (`textGeneration`, `chatHistory`, `functionDeclarations`, `imageGeneration`, modalities, MIME types) exist and behave as assumed

### Settings & Lifecycle
- Settings registration, sanitization, capability checks, uninstall cleanup (options + transients + site-transients on multisite)
- Admin copy stays accurate (separate key fields, no shared-key claims)

## Output Format

Write findings to the output file in JSON Lines format:

```jsonl
{"type":"summary","text":"Audited {target_dir}. Found X issues."}
{"type":"issue","severity":"critical|important|minor","file":"relative/path","line":42,"message":"What the issue is","suggestion":"How to fix it","inline":false}
```

## Severity Guide

- **critical**: Allowlisted model that fails generation, probe rejecting valid keys, SDK contract breakage, settings/credential mishandling
- **important**: Stale allowlist entries, inaccurate counts/docs, missing guards on new SDK calls
- **minor**: Copy inaccuracies, unverified-but-harmless claims
