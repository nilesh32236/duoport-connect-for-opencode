# DuoPort Connector for OpenCode

Connect [OpenCode](https://opencode.ai) Go and Zen catalogs — including reviewed free-tier models, some of which require the OpenCode app — to the WordPress 7.0+ AI Client.

> This plugin is not affiliated with or endorsed by OpenCode (Anomaly Innovations, Inc.).
>
> Also available on [WordPress.org](https://wordpress.org/plugins/duoport-connect-for-opencode).

Once connected, any plugin that uses the WordPress AI Client (for example the official AI plugin's featured-image, alt-text, title and excerpt generation) can use OpenCode models.

## Features

- **Two providers** — OpenCode Go (subscription catalog) and OpenCode Zen (pay-as-you-go catalog including reviewed free-tier models), auto-discovered on Settings → Connectors.
- **Curated model list** — only verified chat/completions models per catalog; reviewed free-tier models that work from WordPress are labeled `(Free)`, while free-tier IDs that require the OpenCode app are hidden by default and labeled `(Requires OpenCode app)` under **Show all models**.
- **Show all models toggle** — optional full catalog exposure on Settings → DuoPort Connector.
- **Key validation with caching** — connection status is probed and cached, and shown on the settings page.

## Requirements

- WordPress 7.0+
- PHP 8.2+

## Installation

1. Install from WordPress.org, or download the ZIP from the
   [Releases](../../releases) page and upload it via Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to **Settings → Connectors** and enter your opencode.ai key in **both**
   the Go and the Zen fields — in most cases, the same key works for both catalogs.
4. Optional: visit **Settings → DuoPort Connector** to enable **Show all
   models** or check connection status.

## FAQ

**Where do I get an API key?**
Visit https://opencode.ai/auth and sign up for Go or Zen. In most cases, the same key works for both catalogs.

**Do Go and Zen use the same API key?**
In most cases, yes. OpenCode uses a unified auth domain — paste the same key into both fields. Each catalog keeps its own field so WordPress can validate them independently.

**Which models are available?**
By default only reviewed chat/completions-routable models are shown (Go: 11, Zen: 15 routable; Zen: 12 offered by default). The other three Zen IDs are free-tier models that OpenCode now serves only through its own client, so they are hidden by default and appear only under **Show all models** labeled `(Requires OpenCode app)`. Allowlisted models whose endpoint family is not yet verified stay hidden until a chat/completions probe passes. Enable **Show all models** to expose every other model from the API, including non-chat models that may fail.

**Does it work without WordPress 7.0?**
No. It requires WordPress 7.0+ and PHP 8.2+. On older installs an admin notice is shown and registration is skipped.

## Privacy

This plugin connects to the OpenCode API (https://opencode.ai) to list models, check availability, and generate text. Your API key is sent with every request, and prompts you submit are sent to OpenCode's servers. See the [terms of service](https://opencode.ai/legal/terms-of-service) and [privacy policy](https://opencode.ai/legal/privacy-policy).

## Support & contributions

- Found a bug or want a feature? [Open an issue](../../issues).
- Developers: see [CONTRIBUTING.md](CONTRIBUTING.md) and [AGENTS.md](AGENTS.md).

## License

GPL-2.0-or-later.
