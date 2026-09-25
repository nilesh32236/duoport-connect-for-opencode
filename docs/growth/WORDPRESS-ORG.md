# WordPress.org Growth Baseline

## Current listing evidence

Captured during the 2026-09-25 fresh audit:

- Listing: [DuoPort Connector for OpenCode](https://wordpress.org/plugins/duoport-connect-for-opencode/)
- Version: 0.1.5; tested through WordPress 7.1.2; requires PHP 8.2
- Active installs: fewer than 10; ratings/reviews: 0; visible support threads: 0
- Current tags: AI, artificial intelligence, connector, opencode, zen
- Banner and icon exist; no screenshots are present in the listing
- Current listing description is accurate but does not visually demonstrate Go + Zen setup, free-model value, or the WordPress AI Client flow

## Factual competitor comparison

| Listing | Version | Active installs | Screenshots | Observable positioning |
|---|---:|---:|---|---|
| DuoPort Connector for OpenCode | 0.1.5 | <10 | No | Go + Zen, verified/default-deny models, free-model awareness |
| OpenCode AI Provider | 1.0.0 | 0 | Yes | Zen integration, dynamic models, tools/web search claims |
| AI Provider for OpenCode Zen | 1.6.0 | 30 | No in API metadata | Broad Zen model count and one-key setup |
| Nominal AI Provider for OpenCode | 1.0.0 | 30 | Yes | Simple OpenCode bridge and model discovery |

These are public listing facts, not claims that competitors are correct or more capable. DuoPort’s differentiator is verified, conservative coverage rather than raw count.

## Conversion funnel gap

A visitor can understand DuoPort’s purpose from the readme, but cannot see the product before installing. The next improvement adds real screenshots and useful listing sections; it does not change the title, tags, banner, icon, model registry, or runtime behavior in the same experiment.

## Screenshots captured

1. `screenshot-1.png` — the real WordPress Connectors screen showing the OpenCode Go and Zen provider integration.
2. `screenshot-2.png` — the real DuoPort Settings page showing connection state, the optional model-view toggle, and the small settings surface.

The images were captured with Playwright from the live WordPress environment after checking that no input held a secret-shaped value. The temporary audit account was deleted after capture. The screenshots are cropped below the WordPress admin bar, contain no credentials or authorization headers, and are not fabricated mockups.

- `screenshot-1.png`: 1440 × 868 PNG, SHA-256 `89859473776b4cc5f735676fc663f7743a31524fc131d1e16553aadc635a5727`
- `screenshot-2.png`: 1440 × 868 PNG, SHA-256 `d737a505fce7cf3f7db5616e0f4946d1928ff4dba9a38ae5168e7df94f73987c`

## Experiment discipline

`GROWTH-001` records the before-state above and changes screenshots/description structure only. A later `GROWTH-002` may evaluate title, short description, or tags after this baseline is deployed and measured. No fake reviews, installs, support activity, keyword stuffing, or misleading model claims are permitted.
