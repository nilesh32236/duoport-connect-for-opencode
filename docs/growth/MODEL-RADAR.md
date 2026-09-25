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

The current Zen MiniMax records are intentionally reported as unsupported/needs-adapter: official documentation identifies a chat endpoint, while the reviewed adapter has not yet completed the full contract verification. The current docs also expose Responses and Messages families; those remain future transport work, not automatic chat fallbacks.

## Free-model fast lane

A public `free` field is treated as explicit evidence. IDs with a free-looking name are reported separately as `unverified_name` candidates. Neither form changes `ModelRegistry` or makes a model routable.

Current observations include `space-bunny-free` in Go and Zen plus Zen candidates such as `jev-1.13-free`, `mimo-v2.6-flash-free`, and the Muse Spark contributor-free IDs. The report is a queue for verification, not a promise of free pricing or availability. Free status can change at any time.

## Transition semantics

The radar distinguishes:

- new, retired, endpoint-changed, capability-changed, free-changed, and metadata-changed observations;
- API unreachable versus malformed/duplicate/invalid evidence;
- reviewed support versus registry candidates needing an adapter;
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
