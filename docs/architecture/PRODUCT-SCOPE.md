# Product scope

## In scope

- Register OpenCode Go and Zen with the WordPress AI Client / Connectors system.
- Discover and safely filter the OpenCode model catalogs.
- Resolve model metadata and capabilities from verified evidence.
- Select a verified endpoint family and transport for each supported model.
- Build authenticated requests through WordPress AI Client interfaces.
- Cache and classify lightweight availability diagnostics.
- Show a deliberately small settings page with connection/catalog status, model information, show-all behavior, and safe diagnostics.
- Save already-generated image bytes to the Media Library when image support has been explicitly verified.
- Maintain focused tests, WPCS compliance, release metadata, and safe GitHub automation.

## Out of scope by default

- Generic chat UI or conversation history.
- Prompt library, RAG, vector database, or embeddings UI.
- AI agents, local model runtime, provider routing dashboard, or generic AI platform.
- Arbitrary model auto-promotion from `/models`.
- Unsupported endpoint families or capabilities.
- A second credential/settings system competing with WordPress Connectors.
- Unverified image, vision, web-search, or tool support.
- Analytics, telemetry, or paid-request monitoring beyond minimal provider diagnostics.

## Release boundary

A release is a packaging and evidence milestone, not a feature expansion. The plugin may publish only after the exact commit passes source, CI, security, live WordPress, Playwright, ZIP, and artifact gates; WordPress.org deployment and the installed runtime must be verified afterward. The release must not widen the provider, capability, transport, or credential boundaries described here.


A proposed feature must answer all five questions in the campaign brief:

1. Is it specifically useful because the provider is OpenCode?
2. Does WordPress AI Client not already provide it?
3. Does it reduce user friction?
4. Does it improve reliability or correctness?
5. Does it keep the plugin small and maintainable?

A “no” rejects the feature. A feature also needs primary evidence, a bounded owner, a fail-open path, and focused tests.

## Candidate disposition

| Candidate | Initial disposition | Reason |
|---|---|---|
| PF-001 Capability-aware model registry | queued after workflow repair | High correctness value; requires canonical evidence model |
| PF-002 Endpoint-family routing | queued after registry | Official docs show chat/responses/messages/provider paths; high risk |
| PF-003 Detailed connection diagnostics | queued | Useful friction reduction; must preserve credential blindness |
| PF-004 Catalog freshness/verification status | queued | Reduces stale allowlist risk; watcher must be default-deny |
| PF-005 Smart default model selection | deferred | Useful but overlaps caller/WordPress model selection |
| PF-006 Capability-aware fallback | completed | Bounded selector and payload-safe routing are implemented behind the verified registry, endpoint, and capability contracts |
| PF-007 Verified image-generation contract | completed | Evidence audit kept image capability default-deny; no provider response/media-safety evidence justified exposure |
| PF-008 WordPress request-log integration | rejected for now | Core AI Client owns request logging; avoid duplicate data |
| PF-009 Compatibility diagnostics | queued | Small, provider-specific operational value |

The authoritative state, dependencies, and next action live in `refactor-queue.yaml`.
