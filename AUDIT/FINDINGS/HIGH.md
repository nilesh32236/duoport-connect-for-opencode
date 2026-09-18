# HIGH — 1 finding
## H-01 Empty /models throws instead of returning []
- **File:** src/Metadata/AbstractOpenCodeModelMetadataDirectory.php:84-86
- **Evidence:** `if (!isset($data['data']) || !$data['data']) throw ResponseException::fromMissingData(...)` — empty array triggers exception, should return [] for "no models" case.
- **Impact:** User-visible error on empty catalog, not graceful.
- **Fix:** Return [] when data missing/empty per SDK contract.
