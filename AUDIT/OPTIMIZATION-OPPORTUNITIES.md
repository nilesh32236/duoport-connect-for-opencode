# Optimization Opportunities (Prioritized)

1. **MEDIUM — Availability thundering herd** `src/Availability/OpenCodeProviderAvailability.php:64,105` — add `wp_cache_add` lock + jitter ±60s, or `set_transient` with `wp_rand` expiry.
2. **MEDIUM — Billed chat/completions probe** `:72-86` — prefer GET `models` lightweight ping or `OPTIONS`, keep status-code logic.
3. **MEDIUM — Blocking double probe in render** `src/Settings/Settings.php:148-149` — `isProviderConfigured` fires two HTTP calls synchronously on admin page; cache result or make async.
4. **MEDIUM — Hand-rolled ai_client key** `src/Settings/Settings.php:112` — use trait's `getBaseCacheKey()` via reflection or SDK helper, not manual `md5`.
5. **LOW — Synchronized expiry** — add jitter to 5min and 86400s to avoid mass expiry.
6. **LOW — get_option per parse** `src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:87` — inject show_all flag via constructor, not per-row get_option.
7. **LOW — init:20 on every request** `duoport-connect-for-opencode.php:126` + `Settings.php:35` — guard `is_admin()` before registering settings.
8. **LOW — Triple-delete** `src/Settings/Settings.php:110-125` — `has()+delete+transient+SQL` redundant; rely on object cache+transient is enough; cache `AiClient::getCache()`.
9. **LOW — Autoloader stat** `src/autoload.php:24-29` — `file_exists` per class; use composer's classmap in production.
10. **LOW — Asset bloat** `assets/banner-772x250.png` 16-bit 28KB → 8-bit ~14KB; `assets/images/opencode.svg` remove clipPath/mask, add <title>.
