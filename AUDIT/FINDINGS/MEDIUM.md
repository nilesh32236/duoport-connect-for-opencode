# MEDIUM — 12 findings
1. Availability thundering herd `Availability.php:64,105` — no lock/jitter
2. Billed chat/completions probe `Availability.php:72-86`
3. Blocking double probe in Settings render `Settings.php:148-149`
4. Hand-rolled ai_client_* key `Settings.php:112`
5. Settings bootstrap no Throwable `duoport-connect-for-opencode.php:126`
6. Uninstall orphans ai_client_* `uninstall.php:12`
7. Single-site uninstall only (no site_option/site_transient) `uninstall.php:12`
8. Tested up to 7.0 stale `readme.txt:5` vs 7.1 core
9. Domain Path /languages missing `duoport-connect-for-opencode.php:12`
10. Dead VERSION constant `duoport-connect-for-opencode.php:26`
11. Banner+icon dead in ZIP `assets/banner-772x250.png, icon.svg`
12. Probe model hard-coded coupling `Availability.php:71`
