# Code Inventory — DuoPort Connector for OpenCode (0.1.1)

**Date:** 2026-08-28
**Root:** `duoport-connect-for-opencode/`
**Total files (ex-vendor):** 33
**Production PHP (src + root):** 15 files, 1374 lines

## 1. Source Files
| File | Lines | Purpose | Hooks? | DB? | Remote? |
|------|-------|---------|--------|-----|---------|
| duoport-connect-for-opencode.php | 144 | Bootstrap, provider registry, connector override, settings bootstrap, action link | admin_notices, init:5, init:20, wp_connectors_init, plugin_action_links | transients only | no |
| src/autoload.php | 31 | PSR-4 spl_autoload_register for OpenCodeConnector\* | — | — | — |
| src/Availability/OpenCodeProviderAvailability.php | 108 | isConfigured() probe + transient cache, WithHttpTransporter/WithRequestAuthentication | — | get_transient/set_transient | POST chat/completions |
| src/Metadata/AbstractOpenCodeModelMetadataDirectory.php | 135 | Filter API /models via ModelAllowlist, sorting, option-dependent | — | get_option(opencode_connector_settings) | inherited GET models |
| src/Metadata/ModelAllowlist.php | 125 | Hard-coded allowlists Go(16)/Zen(19), FREE set, displayName | — | — | — |
| src/Metadata/OpenCodeGoModelMetadataDirectory.php | 49 | Concrete Go directory (providerClass, catalogKey) | — | — | — |
| src/Metadata/OpenCodeZenModelMetadataDirectory.php | 49 | Concrete Zen directory | — | — | — |
| src/Models/AbstractOpenCodeTextGenerationModel.php | 76 | createRequest + prepareResponseFormatParam (json_schema wrapper) | — | — | POST chat/completions (via parent) |
| src/Models/OpenCodeGoTextGenerationModel.php | 38 | Go model leaf | — | — | — |
| src/Models/OpenCodeZenTextGenerationModel.php | 38 | Zen model leaf | — | — | — |
| src/Providers/AbstractOpenCodeProvider.php | 148 | providerId/catalogKey/displayName/description abstract, createModel, createProviderMetadata (version-gated), createProviderAvailability, createModelMetadataDirectory | — | — | — |
| src/Providers/OpenCodeGoProvider.php | 80 | Go provider constants, baseUrl https://opencode.ai/zen/go/v1 | — | — | — |
| src/Providers/OpenCodeZenProvider.php | 80 | Zen provider constants, baseUrl https://opencode.ai/zen/v1 | — | — | — |
| src/Settings/Settings.php | 183 | register_setting, sanitize, bustCaches, clearModelCaches, menu, render | admin_menu, update_option_*, add_option_* | register_setting, get_option, $wpdb DELETE, transients, AiClient::getCache() | no |
| uninstall.php | 14 | delete_option + delete_transient on uninstall | — | delete_option/transient | — |

## 2. Configuration / Docs
| File | Purpose |
|------|---------|
| readme.txt (76) | Directory listing: Contributors nilesh912, Tags ai/opencode/connector/zen/go, Requires 7.0, Tested 7.0, PHP 8.2, License GPL-2.0, External services disclosure |
| composer.json | require-dev: wpcs, phpcompatibility, phpunit, brain/monkey |
| composer.lock | locked deps |
| phpcs.xml | WordPress standard, excludes src/* file-name + function-name, tests/* |
| phpunit.xml.dist | Unit suite |
| .distignore | excludes .git/.github/agents, docs, node_modules, vendor, build, scripts, etc |
| .gitignore | vendor? |

## 3. Assets
| File | Size | Usage |
|------|------|-------|
| assets/banner-772x250.png | 28KB 16-bit RGB PNG 772×250 | WordPress.org directory banner (not runtime) — **dead in ZIP, should be .wordpress.org/** |
| assets/icon.svg | 1KB SVG 128×128 | Org icon — dead in ZIP |
| assets/images/opencode.svg | 503b SVG 240×300 portrait | **LIVE** runtime icon via AbstractOpenCodeProvider:120 when AiClient>=1.3.0 |

## 4. Tests (not shipped)
| File | Lines | Purpose |
|------|-------|---------|
| tests/bootstrap.php | 16 | Brain Monkey bootstrap |
| tests/Unit/MonkeyTestCase.php | 41 | Base |
| tests/Unit/SmokeTest.php | 46 | Harness boots |
| tests/Unit/ConnectorOverrideTest.php | 279 | wp_connectors_init override spec |
| tests/Unit/SlimBustHooksTest.php | 128 | Bust hooks spec (no connectors_ai_* touch) |
| tests/Unit/LegacyApprovalSourceTest.php | 54 | No Approvals_Store usage |

## 5. Vendor (4740 files total, excluded from audit scope)
antecedent/patchwork, brain/monkey, hamcrest, mockery, wpcs, phpcompatibility, phpunit, etc — `vendor/` excluded via .distignore, not for directory.

## 6. Generated / Bundled / Unused
- No JS/CSS build, no minified files, no node_modules, no shortcodes/blocks/REST/Cron/WP-CLI/CPT.
- `.phpunit.result.cache` — generated, gitignored but present — should be ignored.

