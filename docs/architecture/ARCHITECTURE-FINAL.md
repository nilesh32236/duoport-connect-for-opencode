# Architecture final re-audit

**Snapshot:** 2026-09-25  
**Merged baseline:** `97296b20a0d76c98e661691956ebf0805f8cf191` (`origin/main` after MODEL-001 and GROWTH-001)
**Runtime:** WordPress 7.1.2, PHP 8.3.33, WordPress AI Client 1.3.1

## Decision

The plugin remains a small WordPress provider adapter. WordPress owns connector credentials; the plugin owns only provider registration, curated model metadata, capability boundaries, transport helpers, and the plugin settings presentation. No runtime diagnostic reads or writes `connectors_ai_*` values.

## OPS-001 result

PR #47 merged deterministically at the exact reviewed head. The dependency manifest now records:

- OpenCode AI Reviewer v1.22.0, peeled commit `103082c963f64cb2cf979ae14b729ec41d40866e`, publication date, and release URL.
- OpenCode CLI v1.18.31 with separate Linux x64 and arm64 SHA-256 values bound to the installer.
- PAT-backed updater events, fixed repository concurrency, same-repository campaign-PR guarding, current-branch PR recovery, and lease-safe update pushes.
- Active repository ruleset 23935160 requiring exact-head PHP 8.2/8.3 checks and ruleset 23935219 requiring a pull request.

The updater never auto-merges. It opens or refreshes one traceable dependency PR and the active rulesets are the merge gate.

## Post-merge runtime evidence

The merged plugin was synchronized to the live WordPress site and PHP-linted. WordPress deactivate/reactivate completed successfully at version 0.1.5. The AI Client registry reported both `OpenCodeGoProvider` and `OpenCodeZenProvider` registered. Model, metadata, availability, and HTTP helper classes loaded. The settings page rendered without warning capture or secret markers. No runtime errors were emitted during registry inspection.

Local verification passed:

- WPCS
- PHPUnit: 148 tests, 694 assertions
- PHPCompatibilityWP for PHP 8.2+
- Reviewer dependency verifier
- YAML and shell syntax checks
- Release ZIP build and executable release contract
- Exact post-merge WordPress runtime and authenticated Playwright browser checks

## OPS-002 result

PR #49 merged deterministically at the exact reviewed head. The workflow guard now paginates all open PRs, trusts only the exact same-repository automation branch, fails closed on Git/GitHub/schema inspection errors, and has executable identity, recovery, and lease-race fixtures. The updater records exact active rulesets and uses PAT-backed events.

## OPS-003 result

PR #52 merged deterministically at the exact reviewed head. The shared validator now requires complete reviewer manifest provenance, exact release/CLI/ruleset/check values, and distinct architecture hashes. The branch inspector and offline verifier fail closed on omitted, invalid, equal, or mismatched integrity data, with behavioral fixtures covering each case.

## PF-009 result

PR #54 merged deterministically at the exact reviewed head. The plugin now has a small credential-blind compatibility seam for WordPress, AI Client registry shape, and provider registration. It does not read connector options and keeps Settings presentation-only.

## PF-001 result

PR #56 merged deterministically at the exact reviewed head. The curated registry now records endpoint family, capabilities, free status, verification status, and review date per catalog, and metadata filtering uses those records while unknown models and capabilities remain default-deny.

## PF-002 correction

PR #60 merged deterministically at the exact reviewed head. The first routing pass is narrowed to the only complete transport contract: chat/completions. Unresolved metadata now fails before Request construction, and responses/messages/provider-specific families are explicitly denied until their payload, parser, and authentication adapters are verified.

## PF-003 implementation

PR #66 merged the detailed-result correction. The backend now retains safe state/flags through transient caching, separates current usability from the legacy boolean projection, and routes tool preparation through the canonical registry so unsupported Zen routes cannot advertise or prepare tools.

## PF-004 correction

PR #80 merged the final causal CI fixture, direct malformed-input regressions, and source/dependency audit corrections. The shipped drift script fails closed when malformed evidence is the only drift signal, and the inventory records actual method boundaries and runtime dependencies.

## PF-006 implementation

PR #82 merged the payload-safe bounded fallback. It selects before tool shaping, requires matching model identity, catalog, implemented endpoint family, capability, and verification records, rewrites the request model when selected, and fails closed when no candidate exists.

## PF-007 evidence audit

PR #84 completed the image evidence boundary audit. The image allowlist remains empty/default-deny, and no provider response/media-safety evidence justified exposing an image capability. Issue #83 is closed.

## Stopping point

No improvement item remains justified for implementation. PF-005 stays deferred because it overlaps WordPress AI Client selection, and PF-008 remains rejected. GROWTH-002 title/tag experiments and TRANSPORT-001/002 remain evidence-backed future candidates, not active work. RELEASE-003 is the next deliberate packaging step to publish the already-verified screenshots; the queue has no active item, issue, or PR after GROWTH-001 closeout.

## RELEASE-002 result

The fresh audit found internally consistent 0.1.5 metadata and green automated/ZIP gates. Exact commit `176aa80f1243de2f855bcd6f9ef7e016350c2788` passed 138 PHPUnit tests/601 assertions, WPCS, PHPCompatibilityWP, reviewer/workflow contracts, release ZIP and unzip checks, and post-merge WordPress runtime checks. The subsequent documentation-only closeout main passed 138 tests/605 assertions. Authenticated Playwright reached Plugins, activation/deactivation, settings persistence, Connectors, and the public site with no secret-shaped input values; three console/network 404s were isolated to unrelated `ai-provider-for-*` WordPress REST routes and are not DuoPort requests. Annotated `v0.1.5` points to the exact release commit. GitHub release asset `duoport-connect-for-opencode-0.1.5.zip` SHA-256 is `42f7b5b82009b23a71fb23e9db1abf2058d792027fe0b79582d8037650ac4eff`; WordPress.org’s repackaged ZIP has identical extracted contents. Issue #87 is complete and the queue has no active item.

## MODEL-001 result

PR #93 merged at exact reviewed head `6089eadb4837296f3c48729dd605fa7d7e61699e`, producing post-merge main `4987a078cd4a37a1d1a46719ec89f66426cfc05f`. The Model Radar now emits credential-free JSON/Markdown coverage, separates reviewed free models from unverified free-name candidates, records nullable fast-follow metrics, and updates or creates at most one aggregated `model-radar` issue. The live catalog baseline is Go 42 discovered/17 supported/25 verification-required/1 free candidate and Zen 80/14/66/10, with 3 Zen records unsupported pending adapter verification. Post-merge WordPress and Playwright checks pass; no model, capability, transport, or credential boundary was widened.

## GROWTH-001 result

PR #96 merged at exact reviewed head `54ae799b05195bd4cf0bad4f07f40e9d188cd944`, producing main `97296b20a0d76c98e661691956ebf0805f8cf191`. Two real 1440 × 868 WordPress admin screenshots (Connectors and Settings) were captured with Playwright after credential-value checks, cropped below the admin bar, and recorded with hashes in `docs/growth/WORDPRESS-ORG.md`. The readme now explains Go + Zen, free-model boundaries, verified support, and native WordPress AI Client integration without changing title, tags, banner, icon, runtime, registry, transport, or credentials. Post-merge 148 tests/694 assertions, WPCS, PHPCompatibilityWP, release ZIP/unzip, live WordPress, and Playwright checks pass. WordPress.org publication awaits the deliberate `RELEASE-003` packaging step.

## Final boundaries

- Never inspect, mirror, alias, update, or log connector option values.
- Do not advertise unverified tools, images, embeddings, or unknown endpoint families.
- Keep `/models` discovery separate from credential/credit/capability proof.
- Keep transport-family routing explicit; unknown models must not silently fall back to chat completions.
- Keep availability diagnostics bounded and credential-blind.
- Keep the plugin ZIP free of vendor, tests, and local research artifacts.
