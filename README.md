# DuoPort Connector for OpenCode

Connect [OpenCode](https://opencode.ai) Go and Zen catalogs — including free models — to the WordPress 7.0+ AI Client.

> This plugin is not affiliated with or endorsed by OpenCode (Anomaly Innovations, Inc.).
>
> Also available on [WordPress.org](https://wordpress.org/plugins/duoport-connect-for-opencode).

Once connected, any plugin that uses the WordPress AI Client (for example the official AI plugin's featured-image, alt-text, title and excerpt generation) can use OpenCode models.

## Features

- **Two providers** — OpenCode Go (subscription catalog) and OpenCode Zen (pay-as-you-go catalog including free models), auto-discovered on Settings → Connectors.
- **Curated model list** — only verified chat/completions models per catalog; free Zen models are labeled `(Free)` in the picker.
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
   the Go and the Zen fields — the same key works for both catalogs.
   (Advanced: `OPENCODE_GO_API_KEY` / `OPENCODE_ZEN_API_KEY` constants or
   environment variables are also supported.)
4. Optional: visit **Settings → DuoPort Connector** to enable **Show all
   models** or check connection status.

## FAQ

**Where do I get an API key?**
Visit https://opencode.ai/auth and sign up for Go or Zen. The same key works for both catalogs.

**Do Go and Zen use the same API key?**
Yes. OpenCode uses a unified auth domain — paste the same key into both fields. Each catalog keeps its own field so WordPress can validate them independently.

**Which models are available?**
By default only allowlisted chat/completions models are shown (Go: 17, Zen: 17 including free models). Enable **Show all models** to expose every model from the API, including non-chat models that may fail.

**Does it work without WordPress 7.0?**
No. It requires WordPress 7.0+ and PHP 8.2+. On older installs an admin notice is shown and registration is skipped.

## Privacy

This plugin connects to the OpenCode API (https://opencode.ai) to list models, check availability, and generate text. Your API key is sent with every request, and prompts you submit are sent to OpenCode's servers. See the [terms of service](https://opencode.ai/legal/terms-of-service) and [privacy policy](https://opencode.ai/legal/privacy-policy).

## Support & contributions

- Found a bug or want a feature? [Open an issue](../../issues).
- Developers: see [CONTRIBUTING.md](CONTRIBUTING.md) and [AGENTS.md](AGENTS.md).

## License

GPL-2.0-or-later.
