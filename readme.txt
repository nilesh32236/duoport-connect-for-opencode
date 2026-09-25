=== DuoPort Connector for OpenCode ===
Contributors: nilesh912
Tags: ai, artificial-intelligence, connector, opencode, zen
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.6
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect OpenCode Go and Zen to the WordPress AI Client with a conservative, WordPress-native integration.

== Description ==

DuoPort Connector for OpenCode registers two WordPress AI Client providers:

* **OpenCode Go** for the subscription catalog.
* **OpenCode Zen** for the pay-as-you-go catalog, including reviewed free-model records.

Enter your opencode.ai key in the Go and Zen fields on **Settings → Connectors**. The same key works for both catalogs, while each field remains independently testable. DuoPort keeps credentials in WordPress and keeps model policy in a reviewed registry.

This plugin is not affiliated with or endorsed by OpenCode (Anomaly Innovations, Inc.).

== Why DuoPort? ==

DuoPort gives WordPress teams a small, auditable OpenCode bridge instead of another opaque AI dashboard. It works with the native WordPress AI Client, keeps Go and Zen catalog identities separate, and exposes only model routes whose endpoint and capability contracts have been reviewed. The optional Model Radar tracks public catalog changes and queues new or free-model candidates for verification; it never auto-promotes a model.

== OpenCode Go and Zen ==

= OpenCode Go =

Use the Go subscription catalog for its reviewed text-generation models. Go availability is checked with a bounded probe and transient cache, so the plugin can report configured or unavailable states without repeatedly calling the catalog API.

= OpenCode Zen =

Use the Zen pay-as-you-go catalog for reviewed text-generation models, including records explicitly identified as free. Free availability is supplied by OpenCode and can change; a free-looking model name is only a verification candidate, not a promise of free access.

== Current OpenCode models ==

Model lists change over time. By default, DuoPort presents the reviewed chat/completions records for each catalog and labels Zen free records. The credential-free Model Radar compares current public Go and Zen `/models` evidence with that registry and reports new, retired, unsupported, and verification-required changes in one aggregate issue.

Enable **Show all models** only when you want to inspect the complete live catalog. Unknown models, unknown endpoint families, unsupported capabilities, and unverified free-name candidates remain default-deny; discovering a model is not the same as making it routable.

== Free OpenCode models ==

Zen records with explicit free evidence are marked `(Free)` in the model picker when they are part of the reviewed registry. Free models still require an API key and may have limits, availability changes, or catalog retirement. DuoPort does not promise that every free-looking ID is free or supported.

== WordPress AI Client integration ==

DuoPort uses the native WordPress AI Client provider registry. It does not create a separate site-wide model router, duplicate WordPress credentials, or read connector secrets for diagnostics. Model selection, metadata, and request transport remain inside the plugin's reviewed boundaries.

== Verified model support ==

Only reviewed chat/completions records are advertised by default. The current adapter does not claim Responses, Messages, image, tool, or web-search support unless the corresponding contract is explicitly present in the registry. Unknown or unresolved routes fail clearly instead of silently falling back.

= Features =

* Two providers (`opencode-go` / `opencode-zen`) auto-discovered by the Connectors screen.
* Allowlisted chat/completions models per catalog — verified against the OpenCode `/models` API.
* Explicitly reviewed free models labeled `(Free)` in the model picker (Zen catalog).
* Credential-free Model Radar and catalog watch for fast-follow verification work.
* Availability probe with transient caching — validates key without calling `/models`.
* **Show all models** toggle on Settings → DuoPort Connector (off by default).

== Screenshots ==

1. The WordPress Connectors screen lists the real OpenCode Go and Zen provider setup entries.
2. DuoPort's native Settings page shows connection state, the optional model-view toggle, and the small settings surface.

== Installation ==

1. Upload to `/wp-content/plugins/duoport-connect-for-opencode`
2. Activate through the Plugins menu
3. Go to Settings → Connectors and enter your opencode.ai key in both the Go and Zen fields (the same key works for both catalogs)
4. (Optional) Go to Settings → DuoPort Connector to enable **Show all models** or check connection status
5. Use the WordPress AI Client's normal provider/model selection flow

== Frequently Asked Questions ==

= Where do I get an API key? =

Visit https://opencode.ai/auth and sign up for Go or Zen. The same key works for both catalogs.

= Do Go and Zen use the same API key? =

Yes. OpenCode uses a unified auth domain, so paste the same opencode.ai key into both the Go and Zen fields on Settings → Connectors. Each catalog keeps its own key field so WordPress can validate them independently. You can also set `OPENCODE_GO_API_KEY` / `OPENCODE_ZEN_API_KEY` constants or env vars.

= Which models are available? =

By default, only reviewed chat/completions models are shown. Zen may include explicitly reviewed free-model records. Enable **Show all models** on Settings → DuoPort Connector to inspect every live API model, including non-chat models that may fail. The toggle is for inspection, not a guarantee of support.

= Does it work without WordPress 7.0? =

No. Requires WordPress 7.0+ and PHP 8.2+. On older installs an admin notice is shown and registration is skipped.

= Is DuoPort affiliated with OpenCode? =

No. DuoPort is an independent WordPress integration and is not endorsed by OpenCode or Anomaly Innovations, Inc.

== External Services ==

This plugin connects to the OpenCode API (https://opencode.ai) to list models, check availability, and generate text.

* Your API key is sent with every request.
* Prompts and messages you submit for generation are sent to OpenCode's servers.
* Model list is fetched from `https://opencode.ai/zen/go/v1/models` and `https://opencode.ai/zen/v1/models`.
* Availability is validated by a `chat/completions` probe (`max_tokens: 1`).
* See https://opencode.ai/legal/terms-of-service for terms and https://opencode.ai/legal/privacy-policy for privacy policy.

== Changelog ==

= 0.1.6 =
* Added two real WordPress.org screenshots for the Connectors and DuoPort Settings screens.
* Expanded the listing with factual Go + Zen, free-model, verified-support, and WordPress AI Client sections.
* No model registry, transport, capability, or credential behavior changed.

= 0.1.5 =
* Centralized Go session and client User-Agent headers across text and image requests.
* Added an authoritative architecture baseline, class inventory, dependency graph, boundaries, scope, improvement rules, and single-item improvement queue.
* Hardened release documentation so post-0.1.4 additions carry accurate @since metadata.

= 0.1.4 =
* Added `deepseek-v4.1-flash` to the Go catalog.
* Fixed: the key validation probe now sends the required `x-opencode-session` header and probes an authentication-discriminating model, so valid Go/Zen keys are accepted instead of rejected.
* Added image-generation model path: image-capable models advertise the `imageGeneration` capability through new Go/Zen image models behind a per-catalog allowlist. Ships with no image IDs allowlisted yet, so the path stays inert and text generation is unaffected. Also added a Media Library saver with MIME, size, and capability guards.
= 0.1.3 =
* Updated Zen model catalog: removed `hy3-free` and `laguna-s-2.1-free`, which OpenCode retired from the API.
= 0.1.2 =
* Fixed: Go and Zen now use separate API key fields on the Connectors screen. The previous shared-key setup made WordPress reject valid keys on save ("It was not possible to connect to the provider using this key"). If you connected 0.1.1, please re-enter your key in both fields after updating.
= 0.1.1 =
* Renamed plugin to DuoPort Connector for OpenCode; Go and Zen now share a single API key field on the Connectors screen; connecting the providers now requires a one-click approval consent in the Connectors screen.

= 0.1.0 =
* Initial release: Go and Zen providers, allowlisted models with free labels, probe availability, shared-key sync, and Show all models toggle.

== Upgrade Notice ==

= 0.1.6 =
No configuration migration is required. Existing Go and Zen connector keys remain separate and unchanged; this release publishes the verified listing screenshots and documentation improvements.

= 0.1.5 =
No configuration migration is required. Existing Go and Zen connector keys remain separate and unchanged.

= 0.1.2 =
Go and Zen now use separate API key fields. After updating, re-enter your opencode.ai key in both fields on Settings → Connectors.

= 0.1.1 =
Renamed to DuoPort Connector for OpenCode. Go and Zen now share one API key field; connecting the providers now requires a one-click approval consent in the Connectors screen.

= 0.1.0 =
Initial release.
