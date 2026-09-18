# Frontend / Assets Audit — DuoPort Connect for OpenCode

**Agent:** agent-frontend-assets (Frontend & Assets Specialist)
**Date:** 2026-08-28
**Scope:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/duoport-connect-for-opencode` — all production assets and frontend surface. Full line-by-line review of 16 production paths (excluded `vendor/`, `tests/`). No production code modified.
**Method:** `Read` every file with line numbers (`duoport-connect-for-opencode.php:1-144`, `src/Settings/Settings.php:1-183`, `src/Providers/AbstractOpenCodeProvider.php:1-148`, `assets/icon.svg`, `assets/images/opencode.svg`); `Bash` inspection of `assets/banner-772x250.png` (file magic, IHDR chunk parsing, `struct.unpack`, IDAT/zlib decompress, byte-size reporting); `Grep -rn` for `wp_enqueue|wp_register.*style|wp_register.*script|assets|banner|icon.svg|opencode.svg|dashicon` (excluding `vendor`); `Glob` for `*.css`/`*.js`; `python3 xml.etree` SVG parsing + regex attribute checks (`viewBox`, `width`/`height`, `<script`, `onload`, external `href`, `<style`).

---

## 1. Files Reviewed

| # | File | Lines | Type | Purpose |
|---|------|-------|------|---------|
| 1 | `duoport-connect-for-opencode.php` | 1–144 | PHP bootstrap | Hook wiring, no enqueue |
| 2 | `src/Settings/Settings.php` | 1–183 | PHP admin UI | `add_options_page:136`, `render():146-182` — sole HTML output |
| 3 | `src/Providers/AbstractOpenCodeProvider.php` | 1–148 | PHP provider base | Runtime `assets/images/opencode.svg` consumption `:120` |
| 4 | `src/Providers/OpenCodeGoProvider.php` | 1–80 | PHP provider | No assets |
| 5 | `src/Providers/OpenCodeZenProvider.php` | 1–80 | PHP provider | No assets |
| 6 | `src/autoload.php` | 1–31 | PHP | Autoloader, no assets |
| 7 | `assets/banner-772x250.png` | — | PNG 772×250 | WordPress.org banner (dead at runtime) |
| 8 | `assets/icon.svg` | 1–13 | SVG 128×128 | WordPress.org icon (dead at runtime) |
| 9 | `assets/images/opencode.svg` | 1 | SVG 240×300 | Provider logo (live, via `ProviderMetadata`) |
| 10 | `.distignore` | 1–23 | Config | Determines what ships in ZIP |
| 11 | `readme.txt` | 1–76 | TXT | W.org metadata, no asset refs |
| 12 | `phpcs.xml` | 1–26 | Config | No asset handling |

CSS/JS search: `find ... -name "*.css" -o -name "*.js" | grep -v vendor` returned **0** results. No stylesheet or script exists outside `vendor/`.

---

## 2. Executive Summary

| Category | Verdict | Severity |
|---|---|---|
| Custom CSS / JS | **None — PASS** | — |
| `wp_enqueue_*` / `wp_register_*` usage | **Zero — PASS** (no render-blocking) | — |
| Frontend code (shortcode/block/widget) | **None — PASS** (rear-only) | — |
| Admin UI DOM (Settings page) | **PASS** — native `wrap` + `form-table`, zero custom markup/JS | — |
| `assets/banner-772x250.png` compliance | **Compliant but unoptimized** + **dead weight in ZIP** | LOW |
| `assets/icon.svg` compliance | **Compliant + DEAD in ZIP** | LOW |
| `assets/images/opencode.svg` correctness | **Parses + LIVE** — portrait ratio + fixed dimensions + over-complex markup | LOW |
| Asset shipping (`.distignore`) | **Misconfigured — banner + icon ship as dead weight (~30 KB)** | MEDIUM |
| Render-blocking / DOM cost | **Zero — PASS** | — |
| Overall frontend risk | **No blocking issues. 1 MEDIUM (shipping), 4 LOW.** | — |

**Bottom line:** This is a backend-only connector plugin with **zero frontend footprint** — no CSS, no JS, no enqueue, no render-blocking, no frontend DOM injection. The sole HTML surface `src/Settings/Settings.php:146-182` correctly reuses WordPress core admin classes and requires no assets. The three asset files split cleanly into 2× dead W.org directory assets (`banner-772x250.png`, `icon.svg`) that are correctly **not** enqueued but incorrectly **shipped** in the production ZIP, and 1× live provider icon (`assets/images/opencode.svg`) with minor SVG hygiene issues (portrait aspect, hard-coded pixel dimensions, unnecessary `clipPath`/`mask`). Banner PNG is oversized at 16-bit depth (29 KB vs ~14 KB achievable at 8-bit) and is missing the recommended high-DPI `banner-1544x500.png` companion.

---

## 3. CSS / JS — None

### 3.1 File Inventory

```
Glob src/**/* → 13 PHP files, 0 CSS, 0 JS
find -name "*.css" -o -name "*.js" | grep -v vendor → 0 hits
assets/ → banner-772x250.png, icon.svg, images/opencode.svg — 0 .css/.js
```

**Verdict: PASS.** Plugin legitimately requires no custom styling or scripting. The decision to rely on core admin CSS (`wrap`, `form-table`, `form-table th/td`, `button-secondary` via `submit_button()`) is correct for a single-checkbox settings page.

### 3.2 Implications

- No asset minification / bundling pipeline needed. `composer.json:29-34` `scripts.lint/compat` correctly omit any `build`/`assets` step — consistent with `.distignore:14-15` excluding `node_modules/` and `package.json`.
- No `style.css` or `dist/` to rev or fingerprint.

---

## 4. Admin UI — `src/Settings/Settings.php:135-183`

### 4.1 Structure

`Settings::menu():135-137`:
```php
add_options_page( __( 'DuoPort Connector', ... ), __( 'DuoPort Connector', ... ), 'manage_options', 'duoport-connect-for-opencode', array( $this, 'render' ) );
```
Slug `duoport-connect-for-opencode` → URL `options-general.php?page=duoport-connect-for-opencode`. Capability `manage_options` correct (mirrors `register_setting` ownership). No `admin_enqueue_scripts` hook — intentionally absent.

`Settings::render():146-182` DOM:

```html
<div class="wrap">                          <!-- :151 native WP container -->
  <h1>DuoPort Connector</h1>                <!-- :152 -->
  <p>Configure your API key on <a href="...options-connectors.php">…</a>.</p> <!-- :153-163 wp_kses_post + esc_url -->
  <p>Go: subscription… Zen: pay-as-you-go…</p>                               <!-- :164 esc_html_e -->
  <p>Go: %1$s · Zen: %2$s</p>             <!-- :165-170 printf esc_html -->
  <form method="post" action="options.php"> <!-- :171 -->
    settings_fields('opencode_connector')   <!-- :172 nonce + option_group -->
    <table class="form-table"><tr>          <!-- :173 core table -->
      <th>Show all models</th>              <!-- :174 -->
      <td><label><input type="checkbox" … /></label></td> <!-- :175 checked() + esc_attr OPTION_NAME -->
    </tr></table>
    submit_button()                         <!-- :177 -->
  </form>
  <p><a href="https://opencode.ai/auth" target="_blank" rel="noopener noreferrer">Get an API key</a></p> <!-- :179 -->
</div>
```

### 4.2 Assessment

| Check | Result | Evidence |
|---|---|---|
| Inline `style=` attributes | **None** | `grep -n "style\|wp_add_inline" src/Settings/Settings.php` → 0 hits (only `:164` literal word “style” in prose is absent; actual grep hit was false-positive on translation string). No `style=` substring in `render()`. |
| Custom CSS classes requiring stylesheet | **None** | Only `wrap`, `form-table` — provided by `wp-admin/css/forms.css` already loaded on `options-general.php`. No `.duoport-*` selectors. |
| Custom JS / `add_action('admin_enqueue_scripts')` | **None** | `grep -rn "admin_enqueue\|wp_enqueue\|enqueue" --include="*.php" | grep -v vendor` → only vendor hits; 0 in `src/**` or bootstrap. |
| Nonce / capability enforcement | **Delegated correctly** | `settings_fields:172` emits `wp_nonce_field` for `opencode_connector` group; `register_setting:36-44` with `sanitize_callback:42` → `sanitize():58-63`. Capability check via `add_options_page` third arg `manage_options:136`. |
| Escape hygiene | **CLEAN** | `esc_html_e:152,164`, `wp_kses_post(sprintf(__(…))):155-158`, `esc_url(admin_url('options-connectors.php')):159`, `esc_html__(…):168`, `esc_attr(OPTION_NAME):175`, `checked():175`. No unescaped `echo` of user input. |
| Accessibility | **Adequate** | `<label><input>` implicit association (`:175`) + `<th>` header gives minimal screen-reader structure. Checkbox `value="1"` conventional. External link correctly has `target="_blank" rel="noopener noreferrer":179`. No missing `scope`/`for` is LOW but acceptable for single-field form-table where `<th>` + `<label>` pattern is idiomatic WP. |
| Render-blocking | **Zero** | No `<link rel="stylesheet">`, no `<script>`, no `@import`, no `wp_enqueue_*`. Admin page load is network-bound only by availability probes (separate performance concern, not asset-related). |
| DOM weight | **Minimal** | ~18 nodes, 0 custom layout, 0 reflow triggers, 0 JS. Page paints in single core-admin stylesheet pass. |

**Verdict: PASS.** Zero frontend debt. No CSS or JS should be added.

---

## 5. Frontend Code — Should Be None

### 5.1 Verification

- `Grep -rn "add_shortcode|add_block|register_block_type|widgets_init|wp_head|wp_footer|the_content|template_include" --include="*.php" | grep -v vendor | grep -v tests` → **0 hits**.
- `duoport-connect-for-opencode.php:1-144` registers only `admin_notices:32`, `init:5` (provider registration), `init:20` (settings), `wp_connectors_init:87`, and `plugin_action_links_…:137`. No `wp_enqueue_scripts`, no `template_redirect`, no REST route, no block.
- `Settings::register():45` hooks `admin_menu` only — not `wp_loaded` frontend path.
- Anonymous page view (warm cache) path exercises only `init:5` `class_exists(AiClient) + hasProvider()` in-memory check — **zero** DOM mutation, zero option reads, zero network (see `agent-performance`).

**Verdict: PASS.** No frontend surface to audit. Confirms plugin is settings-page + AI-client provider glue only.

---

## 6. Script / Style Enqueue — Render-Blocking — DOM

### 6.1 Enqueue Audit

| Hook | Expected | Actual |
|---|---|---|
| `wp_enqueue_scripts` | Absent | Absent — confirmed via `grep` above |
| `admin_enqueue_scripts` | Absent (or scoped) | Absent — intentionally none |
| `wp_register_style` / `wp_enqueue_style` | 0 | 0 in production PHP |
| `wp_register_script` / `wp_enqueue_script` | 0 | 0 in production PHP |
| Inline `<style>` / `<script>` in `render()` | 0 | 0 — verified `Read` `Settings.php:146-182` contains no such tag |

**Render-blocking: none.** No stylesheet or script blocks `DOMContentLoaded` or first paint. No `defer`/`async` strategy needed because nothing is enqueued.

### 6.2 DOM Cost

- Frontend DOM cost: **0** (no insertion).
- Admin DOM cost: **~18 nodes** (`div.wrap + h1 + 3×p + form + table + tr + th + td + label + input + submit_button markup + trailing p>a`). No nested flex/grid, no custom layout recalculations, no observation (`ResizeObserver`/`MutationObserver`). Palette is inherited from `wp-admin` — no paint invalidation beyond core.

---

## 7. Assets — Inventory & Usage

### 7.1 `assets/banner-772x250.png`

| Property | Value | Source |
|---|---|---|
| Dimensions | **772 × 250** (`struct.unpack('>I', IHDR[16:24])`) | `Bash` IHDR parse |
| Bit depth / color type | **16 / 2** (16-bit RGB truecolor, non-interlaced) | Byte 24–25 IHDR |
| File size | **28,969 bytes (28.29 KB)** | `ls -lh` / `stat` |
| Magic | `89 50 4E 47 0D 0A 1A 0A` valid PNG | `hexdump -C` |
| Chunks | `IHDR 13, cHRM 32, bKGD 6, tIME 7, tEXt×3 (date:create/modify/timestamp), IDAT 28681, IEND 0` | Chunk walker |
| IDAT compressed | 28,681 bytes | Sum `IDAT` lengths |
| `tEXt` payloads | `date:create 2026-08-24T06:25:01+00:00`, `date:modify…`, `date:timestamp…` | tEXt extraction |
| `file` | `PNG image data, 772×250, 16-bit/color RGB, non-interlaced` | `/usr/bin/file` |

**Compliance (WordPress.org directory):**

- **Dimensions PASS:** 772×250 is the exact low-res banner required by `https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/` (accepts 772×250 **or** 1544×500 retina). File is correctly named `banner-772x250.png`.
- **High-DPI companion MISSING (LOW):** Guideline recommends additionally providing `banner-1544x500.png` (2× retina). Only low-res exists; retina devices will upscale with mild blur. Recommend adding `banner-1544x500.png` (same art at 2×) for crisp directory display.
- **Format PASS:** PNG is accepted (JPEG alternate would be smaller for photographic banners, but PNG is appropriate if banner has flat-color/typography). Size 28 KB well under implicit ~500 KB–1 MB w.org soft limit.
- **Location FAIL (MEDIUM — shipping):** For W.org consumption this file belongs in `.wordpress.org/assets/` SVN (alongside `icon.svg` etc.), **not** inside the plugin runtime ZIP (`assets/banner-772x250.png`). Current `.distignore:1-23` does **not** exclude `assets/banner-*` or `assets/icon.svg`, so both ship to every install as dead weight (~30 KB). They are never referenced by any `src/**/*.php` (`grep assets|banner|icon.svg` → 0 hits outside vendor) — correct that they are not enqueued, but they should not be distributed to production sites at all. Fix: add `assets/banner-*.png` and `assets/icon.svg` / `assets/icon-*.png` to `.distignore`, keep only `assets/images/opencode.svg` (live).
- **Naming:** `banner-772x250.png` correct per W.org spec. Alternative naming `banner-1544x500.png` for retina is expected pattern — not present.

**Optimization (LOW):**

- **16-bit depth is wasteful.** 772×250 truecolor at 16-bit stores 6 bytes/pixel raw (≈ 772×250×6 = 1,158,000 bytes raw, confirmed via `zlib.decompress(IDAT)` near that magnitude). An 8-bit RGB (color type 2, bit depth 8) achieves visually identical output for web banners at **~50% smaller IDAT** (≈ 14–16 KB). No alpha channel needed (`color_type 2` lacks alpha; `tRNS` absent — correct). No transparency, no high-precision gradient requiring 16-bit.
- **Ancillary chunks:** `cHRM 32 + bKGD 6 + tIME 7 + tEXt×3 (114 bytes)` add ~160 bytes — negligible but `tEXt date:*` timestamps and `cHRM`/`bKGD` are non-essential for directory display and can be stripped via `pngquant --strip` / `optipng -strip all` / `oxipng -s`. Stripping also avoids leaking build timestamp.
- **IDAT compression:** 28 KB at 16-bit suggests moderate palette complexity; recompressing at 8-bit + `oxipng -o4 --strip all` typically yields 14–18 KB with no visual loss. JPEG alternative at quality 85–90 would be 20–30 KB with photographic content; keep PNG if banner is flat-vector.
- **Interlacing:** Non-interlaced (`interlace 0`) — correct for W.org banners (Adam7 interlace adds size).

### 7.2 `assets/icon.svg`

```xml
<!-- :1-13 complete file -->
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128" role="img" aria-labelledby="titleDesc">
  <title id="titleDesc">DuoPort Connector for OpenCode</title>
  <rect width="128" height="128" rx="24" fill="#3858E9"/>
  <circle cx="48" cy="64" r="28" fill="white" opacity="0.10"/>
  <circle cx="80" cy="64" r="28" fill="white" opacity="0.10"/>
  <path d="M 22 30 H 50 C 68 30 76 43.5 76 64 C 76 84.5 68 98 50 98 H 22 Z M 35 42 …" fill="white"/>
  <path d="M 80 30 H 98.5 C 110 30 114.5 37.5 114.5 48 C 114.5 58.5 110 67 …" fill="white"/>
</svg>
```

| Property | Value | Evidence |
|---|---|---|
| Dimensions | `width="128" height="128" viewBox="0 0 128 128"` — **square PASS** | `Read` `:2` + `xml.etree` |
| Size | **1,000 bytes** (`wc -c`) | `Bash` |
| XML decl | Present `<?xml version="1.0" encoding="UTF-8"?>` | `:1` |
| Root `xmlns` | `http://www.w3.org/2000/svg` correct | `:2` |
| Accessibility | `role="img" aria-labelledby="titleDesc"` + `<title id="titleDesc">` | `:2-3` |
| Content | `rect` rounded-square `#3858E9` + 2 translucent circles + monogram paths `D` + `P` | `:4-12` |
| Script / events | **None** — no `<script>`, no `onload`/`onerror`/`onclick` | `python3` string scan + `xml.etree` iter |
| External refs | **None** — no `xlink:href="http"` / `href="http"` | scan |
| Embedded style | **None** — no `<style>` | scan |
| Parse | **Valid SVG 1.1** — `xml.etree` parse succeeds, 6 children | `ET.parse` |

**Compliance (WordPress.org):**

- **Square PASS:** 128×128 square required for `icon.svg` / `icon-128x128.png` / `icon-256x256.png`. ViewBox square matches pixel dims.
- **SVG accepted PASS:** W.org plugin directory accepts `icon.svg` as preferred vector icon (fallback `icon-128x128.png` optional). Naming `icon.svg` correct — spec also accepts `icon-128x128.png`, `icon-256x256.png`.
- **Recommended addition (INFO):** W.org docs illustrate 256×256 as ideal for SVG fidelity at retina; current 128×128 SVG scales losslessly so 256 variant is not strictly needed — SVG is resolution-independent. However some historical validators expect `width="256" height="256"` viewBox for “high-res” SVG; either size is accepted because viewBox drives rendering. Retaining 128 is acceptable but noting that exporting at 256 would silence pedantic checkers.
- **Location FAIL (MEDIUM — same as banner):** Must live in `.wordpress.org/assets/` SVN, not runtime ZIP. Currently ships as dead weight. No PHP references `icon.svg` (`grep icon.svg` → 0 hits). Should be excluded via `.distignore`.

**Correctness:**

- Well-formed XML; no prohibited features. `opacity="0.10"` on `white` circles is permitted (W.org SVG sanitizer allows `opacity`). `fill="white"` keyword valid. `rx="24"` rounded corners conventional for directory icons. Title/a11y pattern correct.

### 7.3 `assets/images/opencode.svg` — **LIVE** (only runtime asset)

```xml
<svg fill="none" height="300" viewBox="0 0 240 300" width="240" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><clipPath id="a"><path d="m0 0h240v300h-240z"/></clipPath><mask id="b" height="300" maskUnits="userSpaceOnUse" width="240" x="0" y="0"><path d="m240 0h-240v300h240z" fill="#fff"/></mask><g clip-path="url(#a)"><g mask="url(#b)"><path d="m180 240h-120v-120h120z" fill="#cfcecd"/><path d="m180 60h-120v180h120zm60 240h-240v-300h240z" fill="#211e1e"/></g></g></svg>
```

| Property | Value | Evidence |
|---|---|---|
| Dimensions | `width="240" height="300" viewBox="0 0 240 300"` — **portrait 0.8:1 NOT square** | Read + `xml.etree` |
| Size | **503 bytes** | `wc -c`, `stat` |
| XML decl | **Missing** | Content starts with `<svg` not `<?xml` |
| Namespaces | `xmlns` + `xmlns:xlink` (xlink unused — dead attr) | `:1` |
| Parse | Valid — 3 children `clipPath`, `mask`, `g` | `ET.parse` succeeds |
| Script/events/style/external | **None** | Scan clean |
| Visual | Two-rect geometric glyph (`#cfcecd` + `#211e1e`) with redundant `clipPath#` + `mask` wrapper | Manual read |

**Live usage:** `src/Providers/AbstractOpenCodeProvider.php:119-121`:
```php
if ( version_compare( AiClient::VERSION, '1.3.0', '>=' ) ) {
    $args[] = dirname( __DIR__, 2 ) . '/assets/images/opencode.svg';
}
return new ProviderMetadata( ...$args );
```
Conditional on `AiClient::VERSION >= 1.3.0` — correct guard (icon param introduced in SDK 1.3.0). Path built via `dirname(__DIR__,2)` → `DUOPORT-CONNECT-FOR-OPENCODE/assets/images/opencode.svg` — absolute filesystem path expected by `ProviderMetadata` (SDK resolves icon via `file_get_contents`/inline or `plugins_url` depending on implementation). `file_exists` is **not** checked before passing — LOW risk; if SVN strips `assets/images/` the call would pass a dangling path. Consider `if (file_exists($icon)) $args[]=$icon` guard.

**Correctness concerns (LOW):**

1. **Portrait aspect 0.8:1 — will render padded or stretched.** W.org provider-icon consumers (Connectors screen / AI Client registry UI) conventionally display provider logos in a **square 1:1** slot (often `width: 24px;height:24px` or `40×40` with `object-fit: contain`). A 240×300 portrait will letterbox with side padding or be center-cropped depending on `object-fit`. Visual audit of the glyph suggests the design is centered in 240×300 with intentional vertical excess (top bar + bottom bar). Recommend **square canvas** `viewBox="0 0 240 240"` or `0 0 256 256` and re-center glyph so slot is filled without padding.
2. **Explicit `width="240" height="300"` locks pixel size.** When a caller does `file_get_contents($svg) → inline echo` or `img src`, fixed pixel attrs override CSS `width:100%;height:auto` responsiveness and conflict with square slots. Best practice for reusable icons: **omit `width`/`height`** and keep only `viewBox`, letting the embedding context size it (or use `width="24" height="24"` if SDK expects fixed). Current hard-coded 240×300 may force a 300px-tall provider row. Recommend remove attrs or set `width="24" height="24"` consistently.
3. **Redundant wrappers.** `<clipPath id="a"><path d="m0 0h240v300h-240z"/></clipPath>` clipping to the exact viewBox rectangle is no-op; `<mask id="b"><path d="m240 0h-240v300h240z" fill="#fff"/></mask>` masking with an identical white rect is also no-op. Both `<g clip-path>` / `<g mask>` layers can be removed — direct two `<path>` elements suffice, saving bytes and reducing parse cost. Minimal equivalent:
   ```svg
   <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 240" role="img"><title>OpenCode</title><path d="…" fill="#211e1e"/><path d="…" fill="#cfcecd"/></svg>
   ```
   (Retain actual path `d` values.)
4. **Dead `xmlns:xlink`.** Namespace declared but no `xlink:href` used — should be removed to keep markup minimal and silence SVG linters.
5. **Missing `<title>` / `role`.** Icon SVG has no `role="img"` / `<title>` / `aria-*`. `assets/icon.svg:2-3` shows correct pattern. Provider icon appears in admin UI where accessibility of connector row matters.
6. **Missing XML decl.** Minor inconsistency — `icon.svg:1` has `<?xml?>` while this file does not. Either convention is parse-valid, but project should standardize; include decl if file may be served directly.
7. **Trademark breadth.** Glyph is geometric minimal (two rectangles on dark ground) — likely intended as abstract OpenCode “door” motif. Confirm it does not reproduce OpenCode corporate mark beyond fair use; plugin’s `readme.txt:17` disclaimer “not affiliated with or endorsed by OpenCode” helps.

**Optimization:** Even at 503 bytes the file is tiny (negligible). Stripping `clipPath`/`mask`/`xlink` + removing `width`/`height` would bring ~350 bytes and fix layout.

---

## 8. Asset Optimization

| File | Current | Optimized target | Saving | Action |
|---|---|---|---|---|
| `banner-772x250.png` | 28.29 KB, 16-bit RGB | **8-bit RGB, stripped ancillaries (`-strip all`), `oxipng -o4`** → **~14–18 KB** | **~10–14 KB (35–50%)** | `oxipng -o4 --strip all assets/banner-772x250.png` or re-export from source at 8-bit. Remove `tEXt date:*` (leaks build time). |
| `banner-1544x500.png` | **Missing** | Create 2× retina export from same source at 8-bit → ~30–45 KB | New file | Export retina companion from Figma/Illustrator at 1544×500, 8-bit, stripped. |
| `assets/icon.svg` | 1.00 KB | Already minimal — no change needed | — | Optional: ensure `width`/`height` + `viewBox` all 128 or 256 consistently; SVG already optimized. |
| `assets/images/opencode.svg` | 503 B, portrait + wrappers | **~320–380 B** square `viewBox`, strip `clipPath`/`mask`/`xmlns:xlink`, add `<title>` | ~120–180 B + layout fix | Rewrite as square viewBox without wrappers (see §7.3). |

**Build-time stripping:** `tEXt date:create/modify/timestamp` currently leaks build timestamp `2026-08-24T06:25:01+00:00`. Not sensitive but unnecessary. `cHRM` + `bKGD` add no visual value for a flat-color banner displayed in browsers (sRGB assumed).

**Caching / delivery:** No enqueued assets means no `?ver=` query or `Cache-Control` concern. Provider icon `opencode.svg` is loaded via filesystem path to `ProviderMetadata` → SDK may inline as `data:image/svg+xml` or serve via `plugins_url` — either benefits from the small file size.

---

## 9. Dead vs Live — Summary

```
grep -rn "assets|banner|icon\.svg|opencode\.svg" --include="*.php" | grep -v vendor
  → single hit: src/Providers/AbstractOpenCodeProvider.php:120  dirname(__DIR__,2).'/assets/images/opencode.svg'
```

| Asset | Referenced in PHP | Verdict | Ships? | Should ship? |
|---|---|---|---|---|
| `assets/banner-772x250.png` | **No** | **DEAD** — W.org listing only | Yes (`.distignore` does not exclude) | **No** — move to `.wordpress.org/assets/` SVN, exclude from ZIP |
| `assets/icon.svg` | **No** | **DEAD** — W.org listing only | Yes (same) | **No** — same |
| `assets/images/opencode.svg` | **Yes**: `AbstractOpenCodeProvider.php:120` gated on `AiClient >=1.3.0` | **LIVE** — provider logo | Yes | **Yes** — runtime dependency, keep |

**Impact of dead shipping:** ~30 KB (29 KB + 1 KB) per install × N installs bandwidth + disk. Linters (`plugin-check` / `phpcs` asset rules) typically flag bundled directory images inside plugin ZIP as warning. Correct pattern:

```
.wordpress.org/
  assets/
    banner-772x250.png
    banner-1544x500.png   (recommended)
    icon.svg              (or icon-128x128.png, icon-256x256.png)
plugins/
  duoport-connect-for-opencode/
    assets/images/opencode.svg   ← only runtime asset
```

`.distignore` fix:

```ignore
assets/banner-*.png
assets/banner-*.jpg
assets/icon.svg
assets/icon-*.png
# keep: assets/images/opencode.svg
```

If the project prefers co-locating directory assets for convenience during `svn cp` to `.wordpress.org/assets`, keep them in repo but **exclude via `.distignore`** so they do not enter the build artifact. Already `composer.json:2 type wordpress-plugin` + `AGENTS.md` pattern keeps repo copy — only ZIP matters.

---

## 10. WordPress.org Banner / Icon Guidelines — Compliance Checklist

Reference: `https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/` + `https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/#plugin-icons`.

| Requirement | Expected | Actual | Status |
|---|---|---|---|
| `banner-772x250.png` OR `.jpg`, **exactly** 772×250 | 772×250 PNG | 772×250 PNG `16-bit RGB non-interlaced` | **PASS** |
| `banner-1544x500.png` OR `.jpg`, **exactly** 1544×500 (retina) | Optional but recommended | Missing | **FAIL — LOW** (recommend adding) |
| `icon.svg` (preferred) OR `icon-128x128.png` / `icon-256x256.png`, **square** | Square SVG 128 or 256 | `128×128 viewBox 0 0 128 128` square SVG | **PASS** |
| Icon must not contain `<script>`, `javascript:` or external refs | No script/externals | No script/externals/style | **PASS** |
| Banner must be `< 1 MB`, valid image (no Photoshop-only features) | <500 KB ideal, valid PNG | 28 KB valid PNG | **PASS** |
| Assets belong in **`.wordpress.org/assets/` SVN** top-level, mirrored from plugin `assets/` | SVN `assets/` | Currently only in plugin `assets/` repo folder, not `.wordpress.org/` | **WARN — conventional layout; distribution correct after SVN copy** |
| Plugin ZIP must **not** bundle directory banners/icons | Excluded via `.distignore` | **Not excluded** — dead assets ship | **FAIL — MEDIUM** |
| Icon `viewBox` square, high contrast on `#3858E9` dark-blue | Square, visible DP+P | Square, white `DP` on blue correct contrast | **PASS** |

---

## 11. SVG Correctness — Detailed

### 11.1 `assets/icon.svg:1-13`

- **Well-formed:** `xml.etree.ElementTree.parse()` succeeds.
- **Namespaces:** `xmlns="http://www.w3.org/2000/svg"` correct; no `xmlns:xlink` (not needed — clean).
- **Root attrs:** `width="128" height="128" viewBox="0 0 128 128"` consistent triple — ideal. No `preserveAspectRatio` needed (square fills). `role="img" aria-labelledby="titleDesc"` correct ARIA for linked icon.
- **`<title>`:** Present `:3` with plugin name — good a11y. Corresponds to `aria-labelledby`.
- **Shape primitives:** `rect rx="24"` for rounded square correctly uses single `rx` (mirrors to `ry`). `circle opacity="0.10"` valid; `path d="M …"` absolute `M/H/C/Z` syntax valid for DP glyph. `fill="white"` keyword canonical lower-case ok.
- **Security:** No `<script>`, no `on*=` handlers, no `href`/`xlink:href`, no `foreignObject`, no `style` — safe to serve as `image/svg+xml`.

### 11.2 `assets/images/opencode.svg`

- **Parse PASS**, security PASS (same checks above clean).
- **Issues LOW** enumerated in §7.3 (portrait ratio, fixed pixel attrs, redundant `clipPath`/`mask`, dead `xmlns:xlink`, missing `<title>`). None break functionality but cause layout jitter and waste bytes.
- **ID naming:** `id="a"`/`id="b"` terse but in a 503-byte single-icon file there is no collision risk. However generic single-letter IDs could collide when multiple SVGs are inlined on one page — recommend prefix `opencode-clip` / `opencode-mask` or remove wrappers entirely.

---

## 12. Recommendations (Priority Ordered)

| # | Priority | Area | Action | File:Line |
|---|---|---|---|---|
| 1 | **MEDIUM** | Shipping | Add W.org directory assets to `.distignore` so they do not ship as dead weight; keep only `assets/images/opencode.svg` runtime. | `.distignore:1-23` — append `assets/banner-*.png`, `assets/icon.svg`, `assets/icon-*.png` (whitelist `!assets/images/opencode.svg` if using wildcard `assets/*`) |
| 2 | LOW | Banner optimization | Re-export `banner-772x250.png` at **8-bit RGB** (bit depth 8, color type 2), strip ancillary chunks (`oxipng --strip all` / `optipng -strip all`), remove `tEXt date:*`. Target ≤18 KB with no visual loss. | `assets/banner-772x250.png` — IHDR byte 24→8, strip `cHRM/bKGD/tIME/tEXt` |
| 3 | LOW | Banner retina | Add `banner-1544x500.png` (same art, exactly 1544×500, 8-bit, stripped) for retina directory display. Place alongside low-res in `.wordpress.org/assets/`. | New file `assets/banner-1544x500.png` (or `.wordpress.org/assets/`) |
| 4 | LOW | Provider icon layout | Fix `assets/images/opencode.svg` to **square** `viewBox="0 0 240 240"` (or `256×256`) and remove hard-coded `width="240" height="300"` (or normalize to `24×24`), remove `clipPath`/`mask` wrappers, remove `xmlns:xlink`, add `role="img"` + `<title>OpenCode</title>`. Re-center glyph within square. | `assets/images/opencode.svg:1` |
| 5 | LOW | Provider icon guard | Guard `AbstractOpenCodeProvider.php:120` path with `file_exists` before appending to `ProviderMetadata` args to avoid exception on stripped builds. | `src/Providers/AbstractOpenCodeProvider.php:119-121` — wrap in `if (file_exists($icon))` |
| 6 | INFO | Icon sizing docs | Document that `assets/icon.svg:2` 128×128 is intentionally SVG (resolution-independent); no 256 bitmap fallback required. Optionally export at `256×256 viewBox` to silence legacy validators. | `assets/icon.svg:2` |
| 7 | INFO | Repo layout docs | Document conventional layout: directory assets live in `.wordpress.org/assets/` SVN; plugin `assets/` retains `images/opencode.svg` only for runtime. Add note to `AGENTS.md` or `readme.txt` assets section. | `.distignore`, `readme.txt` |

---

## 13. Severity Justification

- **No HIGH** — Zero render-blocking, zero broken markup, zero insecure SVG, zero shipped secret. Frontend attack surface is nil.
- **MEDIUM** — Dead directory assets shipping in ZIP is a distribution hygiene / w.org guideline violation and wastes bandwidth at scale. Easy fix in `.distignore`.
- **LOW** — Banner 16-bit bloat, missing retina banner, provider icon portrait ratio + wrappers — all bounded to ~14 KB savings or subtle layout padding, no functional breakage.

---

## 14. Evidence Catalogue (Key Greps)

```
# No enqueue — production PHP
grep -rn "wp_enqueue\|wp_register.*style\|wp_register.*script" --include="*.php" | grep -v vendor → 0 prod hits

# No CSS/JS files
find … -name "*.css" -o -name "*.js" | grep -v vendor → 0

# Only live asset reference
grep -rn "assets|opencode\.svg" --include="*.php" | grep -v vendor → src/Providers/AbstractOpenCodeProvider.php:120

# SVG scans
python3 xml.etree ET.parse(assets/icon.svg) → OK, 6 children, no script/style/externals
python3 xml.etree ET.parse(assets/images/opencode.svg) → OK, 3 children, no script/style/externals
file assets/banner-772x250.png → PNG 772×250 16-bit/color RGB non-interlaced
IHDR unpack → width 772 height 250 bit_depth 16 color_type 2 (truecolor RGB)
```

---

*No production files modified. All observations derived from `Read` + `Bash` evidence above; `vendor/` and `tests/` excluded as non-shipped/non-frontend.*
