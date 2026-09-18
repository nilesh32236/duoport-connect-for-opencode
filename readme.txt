=== DuoPort Connector for OpenCode ===
Contributors: nilesh912
Tags: ai, opencode, connector, zen, go
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect OpenCode Go and Zen catalogs (including free models) to WordPress 7.0 AI.

== Description ==

Registers two AI providers — **OpenCode Go** (subscription catalog) and **OpenCode Zen** (pay-as-you-go catalog including free models) — with the WordPress 7.0 AI Client. Enter your API key in both the Go and Zen fields on Settings → Connectors; the same opencode.ai key works for both catalogs.

This plugin is not affiliated with or endorsed by OpenCode (Anomaly Innovations, Inc.).

= Features =

* Two providers (`opencode-go` / `opencode-zen`) auto-discovered by the Connectors screen.
* Allowlisted chat/completions models per catalog — verified against the OpenCode `/models` API.
* Free models labeled `(Free)` in the model picker (Zen catalog).
* Availability probe with transient caching — validates key without calling `/models`.
* **Show all models** toggle on Settings → DuoPort Connector (off by default).

== Installation ==

1. Upload to `/wp-content/plugins/duoport-connect-for-opencode`
2. Activate through the Plugins menu
3. Go to Settings → Connectors and enter your opencode.ai key in both the Go and Zen fields (the same key works for both catalogs)
4. (Optional) Go to Settings → DuoPort Connector to enable **Show all models** or check connection status

== Frequently Asked Questions ==

= Where do I get an API key? =

Visit https://opencode.ai/auth and sign up for Go or Zen. The same key works for both catalogs.

= Do Go and Zen use the same API key? =

Yes. OpenCode uses a unified auth domain, so paste the same opencode.ai key into both the Go and Zen fields on Settings → Connectors. Each catalog keeps its own key field so WordPress can validate them independently. You can also set `OPENCODE_GO_API_KEY` / `OPENCODE_ZEN_API_KEY` constants or env vars.

= Which models are available? =

By default only allowlisted chat/completions models are shown (Go: 16, Zen: 19 including free models). Enable **Show all models** on Settings → DuoPort Connector to expose every model from the API (including non-chat models that may fail).

= Does it work without WordPress 7.0? =

No. Requires WordPress 7.0+ and PHP 8.2+. On older installs an admin notice is shown and registration is skipped.

== External Services ==

This plugin connects to the OpenCode API (https://opencode.ai) to list models, check availability, and generate text.

* Your API key is sent with every request.
* Prompts and messages you submit for generation are sent to OpenCode's servers.
* Model list is fetched from `https://opencode.ai/zen/go/v1/models` and `https://opencode.ai/zen/v1/models`.
* Availability is validated by a `chat/completions` probe (`max_tokens: 1`).
* See https://opencode.ai/legal/terms-of-service for terms and https://opencode.ai/legal/privacy-policy for privacy policy.

== Changelog ==

= 0.1.2 =
* Fixed: Go and Zen now use separate API key fields on the Connectors screen. The previous shared-key setup made WordPress reject valid keys on save ("It was not possible to connect to the provider using this key"). If you connected 0.1.1, please re-enter your key in both fields after updating.
= 0.1.1 =
* Renamed plugin to DuoPort Connector for OpenCode; Go and Zen now share a single API key field on the Connectors screen; connecting the providers now requires a one-click approval consent in the Connectors screen.

= 0.1.0 =
* Initial release: Go and Zen providers, allowlisted models with free labels, probe availability, shared-key sync, and Show all models toggle.

== Upgrade Notice ==

= 0.1.2 =
Go and Zen now use separate API key fields. After updating, re-enter your opencode.ai key in both fields on Settings → Connectors.

= 0.1.1 =
Renamed to DuoPort Connector for OpenCode. Go and Zen now share one API key field; connecting the providers now requires a one-click approval consent in the Connectors screen.

= 0.1.0 =
Initial release.
