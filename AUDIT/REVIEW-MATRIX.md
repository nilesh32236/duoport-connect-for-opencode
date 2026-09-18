# Review Matrix

| File | Agents | Lines | Status | Findings |
|------|--------|-------|--------|----------|
| duoport-connect-for-opencode.php | php-correctness, security, performance, architecture, duplicate, cache, compat | 144 | REVIEWED | MEDIUM no Throwable on init:20, LOW admin notice imprecision, LOW error_log without class |
| src/autoload.php | php-correctness, performance, architecture | 31 | REVIEWED | No issues (stat cost noted LOW) |
| src/Availability/OpenCodeProviderAvailability.php | php-correctness, security, performance, cache | 108 | REVIEWED | HIGH none, MEDIUM probe coupling, MEDIUM thundering herd, MEDIUM billed chat/completions |
| src/Metadata/AbstractOpenCodeModelMetadataDirectory.php | php-correctness, performance, cache | 135 | REVIEWED | HIGH empty-array throws (should return []), LOW get_option per parse |
| src/Metadata/ModelAllowlist.php | php-correctness, duplicate | 125 | REVIEWED | No issues (allowlist alive) |
| src/Metadata/OpenCodeGoModelMetadataDirectory.php | php-correctness, duplicate | 49 | REVIEWED | No issues (97.8% dup intentional) |
| src/Metadata/OpenCodeZenModelMetadataDirectory.php | php-correctness, duplicate | 49 | REVIEWED | No issues |
| src/Models/AbstractOpenCodeTextGenerationModel.php | php-correctness, performance | 76 | REVIEWED | DUPLICATE createRequest delta |
| src/Models/OpenCodeGoTextGenerationModel.php | php-correctness, duplicate | 38 | REVIEWED | No issues |
| src/Models/OpenCodeZenTextGenerationModel.php | php-correctness, duplicate | 38 | REVIEWED | No issues |
| src/Providers/AbstractOpenCodeProvider.php | php-correctness, architecture | 148 | REVIEWED | MEDIUM variadic ProviderMetadata fragility |
| src/Providers/OpenCodeGoProvider.php | php-correctness, duplicate | 80 | REVIEWED | No issues |
| src/Providers/OpenCodeZenProvider.php | php-correctness, duplicate | 80 | REVIEWED | No issues |
| src/Settings/Settings.php | php-correctness, security, performance, cache, compat | 183 | REVIEWED | MEDIUM hand-rolled ai_client key, MEDIUM blocking double probe in render, LOW triple-delete |
| uninstall.php | php-correctness, cache, compat | 14 | REVIEWED | MEDIUM orphan ai_client_* keys, MEDIUM single-site only |
| readme.txt | security, compat, frontend | 76 | REVIEWED | MEDIUM Tested up to 7.0 stale |
| assets/* | frontend, compat | 3 files | REVIEWED | MEDIUM banner+icon dead in ZIP, LOW portrait SVG, LOW 16-bit PNG |
| phpcs.xml / composer.json / etc | compat | — | REVIEWED | 2 error_log warnings, phpcs exclusions documented |

**Coverage:** 15/15 production PHP + 3 assets + 4 configs = 100% assigned. No file skipped.
