# OpenCode Model Radar

## Purpose

The Model Radar is the credential-free fast-follow loop for OpenCode Go and Zen. It compares public `/models` evidence with the reviewed `ModelRegistry`, reports coverage and verification work, and aggregates meaningful changes into at most one GitHub issue.

It does **not** promote a model. A candidate remains default-deny until its catalog, endpoint, request, response, capability, and WordPress AI Client contracts are verified.

## Baseline snapshot

Captured: `2026-09-25T06:20:27+00:00`

| Catalog | Discovered | Supported | Free supported | Unsupported | Verification required | Free candidates |
|---|---:|---:|---:|---:|---:|---:|
| Go | 42 | 17 | 0 | 0 | 25 | 1 |
| Zen | 80 | 14 | 5 | 3 | 66 | 10 |

The machine-readable snapshot is [model-radar-snapshot.json](<model-radar-snapshot.json>). It was generated from the public sources below and the radar implementation merged in PR #93; post-merge runtime and browser checks passed. The public sources are:

- <https://opencode.ai/zen/go/v1/models>
- <https://opencode.ai/zen/v1/models>

Endpoint-family evidence comes from the published OpenCode tables (<https://opencode.ai/docs/zen/>, <https://opencode.ai/docs/go/>), never from `/models` membership alone: the live catalog carries no endpoint or pricing field, and OpenCode documents Responses, Messages, and chat families side by side. The Zen MiniMax records were previously reported as unsupported/needs-adapter; OpenCode documents all three on the Zen chat/completions endpoint, so they are now reviewed records. The docs also expose Responses and Messages families; those remain future transport work, not automatic chat fallbacks.

An allowlisted model whose family is not in the current published tables stays routable but is reported as `verification-required` with no verification date, so the weekly lane re-checks it instead of inheriting someone else's review. Re-check a pending record by sending one `chat/completions` request with that model and moving it into `ModelRegistry::ENDPOINT_FAMILIES` with the date.

## Free-model fast lane

A public `free` field is treated as explicit evidence. IDs with a free-looking name are reported separately as `unverified_name` candidates. Neither form changes `ModelRegistry` or makes a model routable. The `(Free)` label in the model picker is the reviewed counterpart: it is only applied to IDs confirmed in OpenCode's published Zen pricing table, with the source and access date recorded on `ModelAllowlist::FREE`.

Current observations include `space-bunny-free` in Go and Zen plus Zen candidates such as `jev-1.13-free`, `mimo-v2.6-flash-free`, and the Muse Spark contributor-free IDs. The report is a queue for verification, not a promise of free pricing or availability. Free status can change at any time.

## Transition semantics

The radar distinguishes:

- new, retired, endpoint-changed, capability-changed, free-changed, and metadata-changed observations;
- API unreachable versus malformed/duplicate/invalid evidence;
- reviewed support versus allowlisted records whose family still needs re-verification;
- explicit free evidence versus unverified free-name candidates.

Malformed evidence fails closed. An unreachable catalog is skipped rather than interpreted as mass retirement. A short API outage therefore does not create a false catalog update.

## Fast-follow measurements

Measurement starts with this implementation. The report keeps these fields nullable until observed:

```text
model_detected_at
verification_started_at
verification_completed_at
implementation_started_at
merged_at
released_at
detection_to_verification
verification_to_merge
merge_to_release
total_detection_to_release
```

No historical timings are fabricated. The next verified model implementation records real timestamps and calculates the intervals.

## Automation and issue safety

`check-catalog-drift.php --json` emits the machine-readable report; `--markdown` emits an issue-safe report. `catalog-watch.yml` runs both modes and:

- creates or comments on one `model-radar` issue;
- labels free-model detections explicitly;
- defers issue creation if another campaign issue is already open;
- never writes model IDs or capability state into the registry automatically;
- never reads WordPress connector credential values.

A model is promoted only through a separate reviewed change with focused tests, CI, AI review, exact-head merge, real WordPress verification, and Playwright verification.

## Recheck cadence

The scheduled catalog watcher is the detection lane. A meaningful change should be triaged into the existing campaign issue or one new aggregate issue, not one issue per model. The current WordPress.org listing and competitor conversion audit is a separate future candidate (`GROWTH-001`); Responses and Messages are separate transport candidates (`TRANSPORT-001` and `TRANSPORT-002`).
