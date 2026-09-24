# Architecture final re-audit

**Snapshot:** 2026-09-24  
**Merged baseline:** `afe9fdd70d0842864308f5defcea3c46caebe06f` (`origin/main`)  
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
- PHPUnit: 88 tests, 312 assertions
- PHPCompatibilityWP for PHP 8.2+
- Reviewer dependency verifier
- YAML and shell syntax checks
- Release ZIP build and executable release contract

## Remaining bounded work: OPS-002

The independent automation review identified a small follow-up set that is intentionally not folded into the already-merged release:

1. CI path filters must include both `.yml` and `.yaml` workflows.
2. PR enumeration must be bounded but complete (or explicitly paginated), with no default-page blind spot.
3. Both existing-branch and absent-branch pushes must use explicit lease expectations, including the empty-ref case.
4. Manifest checksums must bind to the exact architecture assignment in `setup-opencode.sh`; merely finding both hash strings is insufficient.
5. Branch inspection failures must fail closed rather than being treated as a non-current branch.
6. Behavioral fixtures must exercise guard identity, API failure, missing-PR recovery, lease mismatch, YAML coverage, hash mapping drift, and next-release idempotence.

OPS-002 is the only active queue item, issue #48, and PR pending. No competing issue or PR is open. ARCH-001 remains queued behind the transport milestone work.

## Final boundaries

- Never inspect, mirror, alias, update, or log connector option values.
- Do not advertise unverified tools, images, embeddings, or unknown endpoint families.
- Keep `/models` discovery separate from credential/credit/capability proof.
- Keep transport-family routing explicit; unknown models must not silently fall back to chat completions.
- Keep availability diagnostics bounded and credential-blind.
- Keep the plugin ZIP free of vendor, tests, and local research artifacts.
