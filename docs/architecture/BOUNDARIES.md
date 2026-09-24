# Architecture boundaries

This document defines dependency direction and ownership for the DuoPort Connector. It is intentionally small: boundaries are enforced by code review, focused tests, and the queue rather than by a framework or container.

## Dependency direction

```text
WordPress bootstrap
        ↓
Provider adapters ───────→ WordPress AI Client contracts
        ↓                         ↓
Model adapters              Model metadata directory
        ↓                         ↓
HTTP/request helpers       Catalog/allowlist
        ↓
Availability / Media / Settings seams
```

Allowed direction is inward toward focused responsibilities. A lower-level deterministic helper must not call a provider factory, read settings, query the registry, or persist credentials. A model adapter may use a catalog/transport decision service, but the metadata directory must not execute generation.

## Responsibility matrix

| Concern | Owner | Allowed collaborators | Forbidden collaborators/operations |
|---|---|---|---|
| Provider identity and registration | Entry point + `AbstractOpenCodeProvider` | WordPress AI Client registry, provider DTOs | `connectors_ai_*` option reads/writes, settings page, catalog HTTP |
| Catalog filtering and labels | `ModelAllowlist`, metadata directory | Immutable registry data, SDK DTOs | Secrets, arbitrary API capability fields, automatic promotion |
| Endpoint selection | Verified transport registry (planned) | Model metadata, transport adapters | Guessing from class name or silently defaulting to chat |
| Request construction | Model/transport adapter classes | Small request/header helper | Settings dashboard, credential persistence, logging |
| Authentication | WordPress AI Client request-authentication trait | SDK-provided request authentication | Reading connector options from plugin code |
| Availability classification | Availability service | HTTP transporter, small cache interface | Rendering admin HTML, model catalog mutation, secret logging |
| Admin status/settings | `Settings` | Plugin-owned option, availability result value object | Registry ownership, transport execution, connector option values |
| Generated media persistence | `ImageAttachmentSaver` | WordPress Media API, MIME/size guards | Model discovery, remote fetch, credential access |
| Cache invalidation | Settings/bootstrap seam | Plugin-owned transient names, catalog registry | Reading old/new connector option values |
| Release/version metadata | Main header, `VERSION`, readme, release checks | One release checklist | Future `@since` tags without release preparation |

## Credential boundary

The following are absolute invariants:

1. Go and Zen never share or alias `setting_name`.
2. Plugin code never calls `get_option`, `update_option`, `add_option`, or `delete_option` with a `connectors_ai_*` name.
3. Cache-bust callbacks accept no secret values and delete only plugin-owned transients.
4. API keys never appear in exceptions, logs, summaries, HTML, fixtures, or generated documentation.
5. A credential-blind diagnostic may report configured/verified/usable state, but it may not inspect the credential value.

Tests that enforce these rules are part of the merge gate, not optional documentation.

## Capability boundary

A capability is admissible only when all of the following agree:

```text
model ID
+ catalog
+ endpoint family
+ verified transport/response shape
+ curated allowlist status
```

Unknown models, unknown catalogs, unknown endpoint families, and unknown capabilities are rejected or omitted. The current image and web-search lists remain empty/default-deny. The legacy `show_all_models` setting may expose unknown text rows for compatibility, but it must not add an unverified tool, web-search, image, or endpoint capability; those rows remain potentially failing until verified.

## Transport boundary

The intended flow is:

```text
Model metadata
      ↓
Endpoint-family value
      ↓
Verified transport adapter
      ↓
Request DTO
```

The current implementation only has a verified chat-completions path. Responses, messages, and provider-specific model paths are not silently treated as chat. Until each family has a tested request/response adapter, it must be rejected with a clear unsupported-endpoint result. The eventual transport contract should be small (for example, a `supports()`/`send()` pair) and should not become a generic HTTP framework.

## Availability boundary

The backend must retain a detailed value even when the WordPress interface requires a boolean:

```text
configured
verified
usable
state: not_configured | verified | invalid_key | no_credits |
       rate_limited | network_error | server_error |
       unsupported_model | unsupported_endpoint | unsupported_capability | unknown
```

`isConfigured()` may remain a compatibility projection. Settings may simplify the value for display, but must not collapse diagnostics before the backend result is produced. Probe responses must be cached briefly, must use the smallest safe request, and must never log the request authorization header.

## Extension-point rules

- Add an interface only when there are multiple implementations, a real test seam, or a verified future transport family.
- Prefer a focused value object/registry over a DI container.
- Keep compatibility adapters at the boundary and remove obsolete branches after a measured release window.
- Do not make `Settings` a facade for the registry, transport, availability, and catalog.
- Do not make metadata classes perform large network requests or credential operations.

## Change checklist

Every queue item must state:

- affected owner and boundary;
- whether it adds a new dependency or only moves one;
- security/credential impact;
- capability and endpoint impact;
- focused tests;
- WPCS and PHPUnit commands;
- deterministic merge SHA and review state.
