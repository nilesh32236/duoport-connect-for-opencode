# Implementation Log
| Finding | Severity | Category | Original file:line | Changed files | What changed | Why | Tests added | Tests executed | Result | Regression risk | Reviewer |
|---------|----------|----------|--------------------|---------------|--------------|-----|-------------|----------------|--------|-----------------|----------|
| H-01 | HIGH | Correctness | AbstractOpenCodeModelMetadataDirectory.php:84 | same:84-89 | Split !isset + empty: missing throw, empty array return [] | Empty catalog should be [] not exception | none | phpunit 7/7 | VERIFIED | Low | php-correctness |
| P-PERF-01 | MEDIUM | Perf | Availability.php:64/105 | same:70-120 | Added lock _lock 10s + jitter +-60s + max 60 | Herd + synchronized expiry | none | phpunit 7/7 | VERIFIED | Low |
| P-PERF-02 | MEDIUM | Perf | Settings.php:112 | same:112-145 | modelCacheKey() central + no has() + site_transient + sitemeta | Coupling + orphan | none | phpcs 0 err, phpunit 7/7 | VERIFIED | Low |
| P-PERF-03 | MEDIUM | Perf/UX | Settings.php:148 | same:148-183 | Wrap isProviderConfigured in try/catch per provider | Blocking double probe hang | none | phpunit 7/7 | VERIFIED | Low |
| C-01 | MEDIUM | Compat | readme.txt:5 | readme.txt:5 | Tested up to 7.0->7.1 | Stale vs 7.1 | none | -- | VERIFIED | None |
| C-02 | MEDIUM | Compat | duoport...php:12 | languages/ + pot | Created languages/ placeholder | Domain Path missing | none | wp load | VERIFIED | None |
| C-03 | MEDIUM | Compat | uninstall.php:12 | uninstall.php:12-26 | site deletes + LIKE ai_client_* + sitemeta + strict_types | Single-site orphan | none | php -l | VERIFIED | Low |
| C-04 | MEDIUM | Correctness | duoport...php:32/126 | duoport...php:32-42,126-134 | Branched admin notice + init:20 Throwable + get_class log | Misleading notice + unguarded bootstrap | none | phpunit 7/7 | VERIFIED | None |
| L-01 | LOW | Packaging | .distignore | .distignore:24-27 | Added AUDIT, cache, banner, icon | Dead assets in ZIP | none | -- | VERIFIED | None |
| L-02 | LOW | Frontend | opencode.svg | opencode.svg | 24x24 + title + no clipPath/mask | Bloat | none | -- | VERIFIED | None |
| L-03 | LOW | Perf | Settings.php:35 | same:35 | is_admin guard for admin_menu | init:20 every request | none | phpunit | VERIFIED | None |
| L-04 | LOW | Style | Settings.php:112/143 | same | phpcbf align + rename class->class_name | Reserved keyword | none | phpcs 0 err | VERIFIED | None |
