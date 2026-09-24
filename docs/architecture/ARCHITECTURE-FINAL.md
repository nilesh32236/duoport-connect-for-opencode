# Architecture final re-audit

**Snapshot:** 2026-09-24  
**Merged baseline:** `c658df720408964d106895b9f14ea068b778207d` (`origin/main`)  
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
- PHPUnit: 92 tests, 339 assertions
- PHPCompatibilityWP for PHP 8.2+
- Reviewer dependency verifier
- YAML and shell syntax checks
- Release ZIP build and executable release contract

## OPS-002 result

PR #49 merged deterministically at the exact reviewed head. The workflow guard now paginates all open PRs, trusts only the exact same-repository automation branch, fails closed on Git/GitHub/schema inspection errors, and has executable identity, recovery, and lease-race fixtures. The updater records exact active rulesets and uses PAT-backed events.

## Remaining bounded work: OPS-003

The post-merge re-audit found two final manifest-integrity gaps:

1. Current-branch inspection must require the complete reviewer manifest schema, including provenance, exact release/CLI/ruleset/check values, and both architecture hashes.
2. The x64 and arm64 hashes must be distinct and individually bound to the installer assignments.

OPS-003 is the only active queue item and issue #51; its PR is pending. No competing issue or PR is open. ARCH-001 is complete; PF-009 remains queued behind this integrity fix.

## Final boundaries

- Never inspect, mirror, alias, update, or log connector option values.
- Do not advertise unverified tools, images, embeddings, or unknown endpoint families.
- Keep `/models` discovery separate from credential/credit/capability proof.
- Keep transport-family routing explicit; unknown models must not silently fall back to chat completions.
- Keep availability diagnostics bounded and credential-blind.
- Keep the plugin ZIP free of vendor, tests, and local research artifacts.
