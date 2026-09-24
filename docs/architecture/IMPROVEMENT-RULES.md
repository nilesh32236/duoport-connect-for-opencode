# Improvement rules

These rules turn the campaign brief into repeatable gates. They apply to every queue item, not only refactors.

## Queue and GitHub discipline

- Keep exactly one item `in_progress` in `refactor-queue.yaml`.
- Keep exactly one active campaign issue and one campaign PR. Close or explicitly defer stale monitor issues/PRs.
- Do not open speculative issues for every candidate feature. Preserve rejected/deferred candidates in the queue with evidence and a reason.
- A PR contains one responsibility or one small architectural migration. Do not combine transport, catalog, settings redesign, and release work.
- Record the issue, PR, head SHA, review state, CI state, and post-merge verification in the queue.

## SOLID, pragmatically

- **SRP:** extract a class only when it has an independent reason to change. Do not create a “service” merely to reduce a line count.
- **OCP:** use a registry/strategy only where Go/Zen or multiple verified endpoint families are real variations. Keep maps deterministic and validated.
- **ISP:** prefer small contracts with multiple implementations or substantial test value. No giant provider interface.
- **DIP:** inject HTTP transport, catalog retrieval, clock, and availability classification when testing or future families justify it. No DI container.
- **LSP:** subclasses must honor the parent contract; Go/Zen model subclasses differ only by provider identity/base URL and capability behavior.

## DRY classification

Every duplicated cluster must be classified before consolidation:

- `intentional`: protocol or catalog-specific behavior;
- `shared responsibility`: one semantic rule currently copied;
- `wrong duplication`: accidental drift;
- `legacy compatibility`: retained for a measured compatibility window;
- `dead code`: remove with tests/source evidence.

Known hotspots are Go/Zen header logic, request construction, catalog/allowlist literals, cache-key construction, capability checks, and settings copy. Consolidate only semantically identical logic.

## Transport and capability rules

1. Never infer an endpoint family from a model name substring or silently default an unknown family to chat.
2. Store endpoint family beside the curated model metadata when evidence supports it.
3. Reject unsupported families/capabilities before any paid request.
4. Advertise a capability only when model, catalog, endpoint, and verification agree.
5. Keep image support opt-in and require allowlist + verified capability + supported transport + safe response handling.
6. Keep web search, embeddings, vision, and other capabilities default-deny until an end-to-end probe exists.

## Security and credentials

- Never share/alias Go and Zen `setting_name`.
- Never read or write `connectors_ai_*` option values.
- Never log API keys, authorization headers, or secret-shaped exception messages.
- Never render secrets in admin HTML, JSON diagnostics, artifacts, or test fixtures.
- Cache-bust hooks are credential-blind and may only delete plugin-owned transients.
- New settings are plugin-owned, additive, sanitized, and default-safe.

## WordPress compatibility

- Target WordPress 7.0+ and PHP 8.2+.
- Prefer newest supported WordPress AI Client API, then a guarded fallback when a safe path exists.
- Guard new WP functions/classes/constants with `function_exists`, `class_exists`, `defined`, `version_compare`, or `has_filter` as appropriate.
- Use real WordPress runtime verification after every merge when the environment is available.
- Do not invent compatibility branches or require a bundled SDK when the core client is present.

## Versioning and releases

Keep synchronized:

```text
main plugin Version header
VERSION constant
readme.txt Stable tag
Changelog
Upgrade Notice
@since tags
reviewer/CLI dependency records
```

Do not add a future `@since` version unless release preparation is underway. A release requires tests, WPCS, runtime checks, ZIP build, changelog, stable tag, and upgrade notice. Tiny refactors do not require a release.

## Verification order

Every PHP change runs:

```text
php -l
vendor/bin/phpcs
vendor/bin/phpunit
vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.2- --ignore=vendor/*,.github/* .
```

CI must be green on the exact PR head. A review label alone never authorizes merge.

## Deterministic merge gate

Before merge, independently verify:

```text
latest PR head observed
expected head SHA captured
CI green on that SHA
review loop idle
no unresolved blocking findings
no newer push
branch mergeable
```

Then merge using the exact expected head SHA. Never merge a stale or recreated commit.

## Architecture ratchet

For each refactor record before/after:

```yaml
lines:
methods:
responsibilities:
dependencies:
fan_in:
fan_out:
static_state:
duplicated_logic:
```

A facade may temporarily increase methods or fan-in, but the queue must contain a caller-migration follow-up. Success means a meaningful, measured improvement in responsibility clarity, correctness, duplication, or dependency direction—not a lower number at any cost.
