# Functionality Map (Input → Validation → Processing → Hooks → DB/Cache → Output → Cleanup)

## 1. Bootstrap → Provider Registration
`plugins_loaded → init:5` → `class_exists(AiClient)` → `AiClient::defaultRegistry()->hasProvider()->registerProvider(Go/Zen)` → try/catch per provider → error_log on WP_DEBUG. **Input:** none. **Cache:** none. **Output:** two providers registered. **Risk:** init:5 fires before wp_connectors_init, so activation request needs next request (documented).

## 2. Connector Override (Shared Key)
`wp_connectors_init` → is_registered(opencode-zen) → get_registered → unregister → mutate authentication.setting_name to connectors_ai_opencode_go_api_key → register → on throw restore pristine. **Correct via documented connectors.php API.** No option mirroring.

## 3. Availability Probe
`ProviderAvailability::isConfigured()` → getRequestAuthentication() try → get_transient(opencode_connector_avail_go/zen) → if miss: Request POST chat/completions max_tokens=1 via getHttpTransporter→send → status 2xx/429/401+CreditsError → set_transient 5min → bool. **Blocking network, no lock, synchronized expiry.**

## 4. Model Listing
`ModelMetadataDirectory::listModelMetadata()` (cached via WithDataCachingTrait 86400s under ai_client_<VERSION>_<md5(class)>_models) → GET models → parseResponseToModelMetadataList → filter ModelAllowlist::isAllowed unless show_all_models option, label Free, suppress outputSchema for deepseek, sort free-first. **Input:** option opencode_connector_settings[show_all]. **Cache:** transient+object cache+wpdb fallback.

## 5. Text Generation
`AbstractOpenCodeTextGenerationModel::generateTextResult()` (inherited) → prepareGenerateTextParams → createRequest → authenticate → send → parse. prepareResponseFormatParam wraps bare schema into json_schema{n, schema, strict}. **Correct OpenCode quirk.**

## 6. Settings Screen
`Settings::register()` → register_setting(opencode_connector, opencode_connector_settings, sanitize) → admin_menu → add_options_page DuoPort Connector → render() → isProviderConfigured Go/Zen → settings_fields → checkbox show_all_models → bustCaches on update/add via clearModelCaches (delete ai_client_* from object cache+transient+wpdb) + delete avail transients.

## 7. Transient Bust (Shared Key)
`update/add_option_connectors_ai_opencode_go_api_key` → delete_transient avail_go+zen. **No option read/write — compliant.**

## 8. Uninstall
`WP_UNINSTALL_PLUGIN` → delete_option opencode_connector_settings + delete_transient avail_* . **Orphans ai_client_* (miss).**

## 9. Assets & UI
No frontend assets. Admin UI uses core wrap/form-table only. Icon opencode.svg used as provider metadata icon when AiClient>=1.3.0.
