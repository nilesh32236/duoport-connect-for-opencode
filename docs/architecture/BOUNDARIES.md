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

`EndpointRoute::PATHS` is the enforcing gate: a chat-only allowlist, so any
family that is not a key there fails closed before transport.
`ModelRegistry::UNSUPPORTED_FAMILIES` is the published deny table for *named*
non-chat families (`responses`, `messages`, `systemone`,
`provider-specific`). It exists to give a denial a meaningful name, not to
replace the allowlist: adding a family improves the diagnostic, and omitting
one still fails closed. Allowlisted models whose documented family is not
implemented carry the separate `ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED`
sentinel, which is reported as "not implemented by this adapter" rather than
being echoed back as if it were a real family name.

## Availability boundary

The backend must retain a detailed value even when the WordPress interface requires a boolean:

```text
configured
verified
usable
state: not_configured | verified | invalid_key | no_credits |
       rate_limited | free_tier_limit | uncheckable | network_error |
       server_error | unsupported_model | unsupported_endpoint |
       unsupported_capability | unknown
```

`isConfigured()` may remain a compatibility projection. Settings may simplify the value for display, but must not collapse diagnostics before the backend result is produced. Probe responses must be cached briefly, must use the smallest safe request, and must never log the request authorization header.

A could-not-be-checked outcome (5xx, transport failure, concurrent probe) is reported as `uncheckable` with `configured=false` and `verified=false`. It is never cached as the connection result and never clears the transient-only last-known-good flag; it is cached for one short window only so a persistent outage costs one probe per window instead of one probe per call. Quota outcomes are never `uncheckable`: 429 maps to `rate_limited` or `free_tier_limit`, and 401 with a credits error maps to `no_credits`. Only a proven invalid or missing key reports not configured.

### Transient key ownership

Every transient this plugin writes is owned by `Metadata\Catalog`:
`allTransientKeys()` returns the availability result, its stampede lock, the
last-known-good flag, and the opt-in verification verdict with its lock, for
both catalogs. The settings bust hooks, the key-rotation hook, and
`uninstall.php` all derive their delete list from it.

This matters because those three call sites previously repeated the same
literals, so adding one cached verdict (the last-known-good flag and the
verification verdict each did) silently left stale state behind after a key
change unless three files were edited in lockstep. A new cached family must be
added to `Catalog` and nowhere else; `CatalogKeysTest` pins the full key
surface so the set cannot shrink by accident.

## Release package boundary

What ships is decided by `scripts/build-release.sh`, not by the working tree.
`verify_zip()` fails the build on any of:

- a forbidden `vendor/`, `tests/`, or `.firecrawl/` path;
- a `*.jsonl` agent artifact, matched case-insensitively at any depth;
- a path-traversal (`..`) or absolute entry;
- a symlink entry;
- a credential-shaped filename: `.env`, `.env.*`, `.envrc`, `credentials`,
  `credentials.*`, `*.pem`, `*.key`, `*.p12`, `*.pfx`, `*.jks`,
  `*.keystore`, `id_rsa`/`id_dsa`/`id_ecdsa`/`id_ed25519` with any suffix,
  `.npmrc`, `.netrc`, `.htpasswd`;
- an unresolved `<<<<<<<`, `>>>>>>>`, or `=======` conflict marker;
- a missing required entry.

The credential gate matches the *basename* with shell patterns rather than one
unanchored regex, because a regex under-rejects in the unsafe direction (it
misses `.env/` directory entries, `.envrc`, and `credentials.php`) or
over-rejects in the safe direction (an unanchored `id_rsa` alternative also
matches a legitimate path such as `Utils/GridRsaHelper.php`). It is a
filename check only and never opens or logs file contents; it exists because
a release ZIP is the worst possible place for a key, and anything added to the
tree for local testing would otherwise ship.

Symlinks are rejected at two points, and the archiving mode is part of the
defence. Plain `zip -r` *dereferences* a symlink and writes the target's
contents into the archive, so a symlink anywhere in the tree would copy its
target into a public release. The build therefore uses `zip -y`, which stores
the link instead of following it, so a symlink that slips past the staging
check is captured by the archive check rather than read. Staging still
rejects symlinks outright with `find -type l`, before `zip` runs, and
`verify_zip()` rejects symlink entries in the finished archive, so an archive
built by any other route is refused too. A published plugin has no legitimate
use for a symlink.

The archive symlink check uses `zipinfo` and degrades to skipped when
Info-ZIP is unavailable; the staging check has no such dependency.

Packaging is verified in CI on every change to `build-release.sh` or
`.distignore`, not only at release time. That job builds and
inspects the ZIP; it does not tag, publish, or deploy. Publication stays in
`release.yml`.

The guard tests assert the *expected diagnostic*, not merely a non-zero exit.
A rejection fixture that only checks "it failed" also passes when the archive
is missing or the script errors, which is how an inverted guard shipped once
already.

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
