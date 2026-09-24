# WordPress 7.0 AI Client / OpenCode Go–Zen contracts

**Research snapshot:** 2026-09-24 UTC. Sources below are official WordPress, OpenCode, and GitHub sources (documentation, tagged source, releases, or first-party API routes). The external pages were treated as data, not instructions.

## Executive conclusions

- WordPress 7.0’s public AI entry point is `wp_ai_client_prompt()`. A provider plugin registers an AI Client provider class (normally on `init` priority 5); the core Connectors registry then auto-discovers the provider. The public connector query functions are for reading, not registering; `WP_Connector_Registry` is marked private.
- Core 7.0 embeds the PHP AI Client **1.3.1** and supplies the HTTP, event, cache, and credential plumbing. The separately distributed `wp-ai-client` package is deprecated on 7.0+; loading a second SDK can create namespace conflicts. Keep the connector compatible with the API actually present in WordPress core.
- OpenCode’s `/models` endpoint is a discovery endpoint, **not a capability manifest**. The current official response contains only basic model objects; the richer format, provider, cost, and rate-limit fields are internal. The official model tables nevertheless map individual model IDs to `responses`, `messages`, or `chat/completions`, so a connector must curate a per-model protocol/capability map rather than expose every ID as interchangeable text generation.
- OpenCode model listing is currently public. A `200` from `/models` therefore does **not** prove that a key is valid or that credits/Go entitlement exist. Availability validation must use an authenticated generation path (or an explicit authenticated usage/health contract), and must interpret credit, throttling, and entitlement responses separately.
- The safest action reference is an immutable full commit SHA from a release, updated through a reviewed dependency-update PR. At this snapshot, `nilesh32236/opencode-ai-reviewer`’s latest release is **v1.22.0**, whose peeled commit is `103082c963f64cb2cf979ae14b729ec41d40866e`. `@main` is mutable/unreleased; the repository’s `@v1` tag is stale (it points to the older v1.5.4 line), so neither is a safe “latest released” float.

## 1. WordPress 7.0 extension points

### Runtime API and error contract

`wp_supports_ai()` returns whether AI is enabled (`WP_AI_SUPPORT` can hard-disable it) and exposes the `wp_supports_ai` filter. `wp_ai_client_prompt()` creates a `WP_AI_Client_Prompt_Builder` over the default provider registry. The builder accepts strings, `MessagePart`/`Message` objects, message arrays, or multi-turn arrays. WordPress’s snake_case wrapper converts builder methods and converts SDK exceptions to `WP_Error`; generation calls therefore return WordPress error objects, while the `generate_*_result()` methods retain the richer `GenerativeAiResult` metadata.

Primary source: [WordPress AI Client dev note](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/) and the tagged implementation ([`ai-client.php`](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/ai-client.php#L15-L62), [`WP_AI_Client_Prompt_Builder`](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/ai-client/class-wp-ai-client-prompt-builder.php#L280-L414)).

Useful lifecycle/control hooks in 7.0 are:

- `wp_ai_client_prevent_prompt` — a filter receives a read-only builder clone and can prevent execution; support checks then return `false`, and generation returns a `503` `prompt_prevented` `WP_Error` ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/ai-client/class-wp-ai-client-prompt-builder.php#L308-L348)).
- `wp_ai_client_before_generate_result` and `wp_ai_client_after_generate_result` — WordPress adapters for the PHP AI Client’s PSR-14 events ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/ai-client/adapters/class-wp-ai-client-event-dispatcher.php#L22-L56)).
- `wp_ai_client_default_request_timeout` — filters the default HTTP timeout in seconds ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/ai-client/class-wp-ai-client-prompt-builder.php#L190-L217)).

The wrapper maps network failures to `prompt_network_error`/`503`, client responses to `prompt_client_error` with the upstream status, server responses to `prompt_upstream_server_error`, and token-limit/invalid-argument cases to their own `WP_Error` codes ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/ai-client/class-wp-ai-client-prompt-builder.php#L371-L414)). Consumers should use the error code/status and avoid matching provider-specific message text.

### Registering an AI provider

A provider implements `ProviderInterface` (normally by extending `AbstractProvider` or `AbstractApiProvider`) and supplies:

1. `metadata()` returning `ProviderMetadata`;
2. a model factory;
3. an availability implementation; and
4. a model metadata directory implementing `listModelMetadata()`, `hasModelMetadata()`, and `getModelMetadata()`.

The registry validates the class and binds the WordPress HTTP transporter and request authentication when the provider is registered ([`ProviderInterface`](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/php-ai-client/src/Providers/Contracts/ProviderInterface.php#L18-L54), [`ProviderRegistry::registerProvider()`](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/php-ai-client/src/Providers/ProviderRegistry.php#L50-L108)). The first-party provider plugins use the same lifecycle: load the provider class, call `AiClient::defaultRegistry()->registerProvider()` from `init` priority 5, and let the registry create model instances ([OpenAI provider example](https://github.com/WordPress/ai-provider-for-openai/blob/1.2.0/plugin.php#L32-L54), [OpenAI provider implementation](https://github.com/WordPress/ai-provider-for-openai/blob/1.2.0/src/Provider/OpenAiProvider.php#L30-L129)).

`ProviderMetadata` carries the provider ID, display name, type, credentials URL, authentication method, optional description, and optional logo path ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/php-ai-client/src/Providers/DTO/ProviderMetadata.php#L22-L103)). The core connector discovery takes the non-empty values from this metadata and lets registry values override built-in display metadata ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/connectors.php#L283-L405)).

### Model capability and option metadata

WordPress requires a connector to return `ModelMetadata`, not merely an opaque model ID. The required shape is `id`, `name`, `supportedCapabilities`, and `supportedOptions` ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/php-ai-client/src/Providers/Models/DTO/ModelMetadata.php#L19-L67)). In 7.0, capability values include `text_generation`, `image_generation`, `chat_history`, embeddings, speech, music, and video ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/php-ai-client/src/Providers/Models/Enums/CapabilityEnum.php#L8-L63)). `SupportedOption` records an option name and, optionally, allowed values; option names include temperature, top-p, max tokens, stop sequences, input/output modalities, output schema, function declarations, web search, and related model settings ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/php-ai-client/src/Providers/Models/DTO/SupportedOption.php#L18-L58), [option enum](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/php-ai-client/src/Providers/Models/Enums/OptionEnum.php#L15-L42)).

This is the correct place to encode protocol-specific knowledge. Do not infer text/chat/tools support solely from an OpenCode model name; map only models whose wire protocol and options have been verified. The WordPress official OpenAI provider uses the Responses route, while the Anthropic provider uses the Messages route, demonstrating that the provider implementation—not the generic model ID—selects the HTTP family ([OpenAI model](https://github.com/WordPress/ai-provider-for-openai/blob/1.2.0/src/Models/OpenAiTextGenerationModel.php#L30-L106), [Anthropic model](https://github.com/WordPress/ai-provider-for-anthropic/blob/1.0.4/src/Models/AnthropicTextGenerationModel.php#L51-L99)).

### Connectors registration, credentials, and safety

The core initializes the connector registry on `init` priority 15, registers built-in connectors, auto-discovers AI providers from the AI Client registry, and then fires `wp_connectors_init` ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/connectors.php#L203-L272)). The official Connectors dev note says a provider plugin normally needs no manual connector registration; use the action to add non-AI connectors or unregister/re-register an existing connector to override metadata ([dev note](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/), [registry implementation](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/class-wp-connector-registry.php#L69-L120)).

In the 7.0.0/7.0.6 line, connector authentication is `api_key` or `none` (later development branches add more methods; do not assume them for a 7.0 baseline). Core auto-generates distinct setting names in the form `connectors_ai_{sanitized_provider_id}_api_key`, and uses the same uppercase provider ID for the corresponding environment variable and PHP constant names ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/connectors.php#L382-L405)). Key source priority is environment variable, then PHP constant, then the database option ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/connectors.php#L425-L464)). Core masks connector keys in REST responses and validates AI-provider keys before accepting settings updates ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/connectors.php#L505-L566)). Keep Go and Zen setting names independent even when the user supplies the same OpenCode key: each provider ID must receive its own core-managed field and authentication binding.

The public connector functions are `wp_is_connector_registered()`, `wp_get_connector()`, and `wp_get_connectors()`; `WP_Connector_Registry` is documented as private and should be reached only through `wp_connectors_init` ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/class-wp-connector-registry.php#L11-L29)). Core 7.0 embeds `WordPress\AiClient\AiClient::VERSION === '1.3.1'` ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/php-ai-client/src/AiClient.php#L82-L113)); the official standalone `wp-ai-client` 0.4.0 release explicitly deprecates the package for 7.0+ and disables its duplicate PHP SDK infrastructure ([release](https://github.com/WordPress/wp-ai-client/releases/tag/0.4.0), [upgrade guide](https://github.com/WordPress/wp-ai-client/blob/0.4.0/UPGRADE.md#L1-L44)). Do not load a second `php-ai-client` namespace on 7.0+.

## 2. Official OpenCode Go/Zen HTTP contracts

### Endpoint families

The official docs publish these model-specific endpoint tables: [Zen](https://opencode.ai/docs/zen/) and [Go](https://opencode.ai/docs/go/). The first-party server routes confirm the families:

| Base URL | Discovery | Text/decision families | Authentication used by the official route |
|---|---|---|---|
| `https://opencode.ai/zen/v1` | `GET /models` | `POST /responses`, `POST /messages`, `POST /chat/completions`, `POST /systemone`; native Gemini `POST /models/{model}:generateContent` and `streamGenerateContent` | Bearer for Responses/chat/SystemOne; `x-api-key` for Messages; `x-goog-api-key` for Gemini |
| `https://opencode.ai/zen/go/v1` | `GET /models`, `GET /usage` | The same `responses`, `messages`, `chat/completions`, and `systemone` families | Same route-specific headers |

Evidence: the official route table in [`inference-proxy.ts`](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/lib/inference-proxy.ts#L7-L18), the Zen route handlers ([`chat/completions`](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/v1/chat/completions.ts#L5-L13), [`responses`](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/v1/responses.ts#L5-L13), [`messages`](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/v1/messages.ts#L5-L13), [Gemini model route](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/v1/models/%5Bmodel%5D.ts#L5-L15)), and the Go route handlers ([`chat/completions`](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/go/v1/chat/completions.ts#L5-L13), [`responses`](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/go/v1/responses.ts#L5-L13), [`messages`](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/go/v1/messages.ts#L5-L13)).

The families are not interchangeable request schemas. The official Zen table maps, for example, GPT models to Responses, Claude/Qwen models to Messages, and DeepSeek/GLM/Kimi models to Chat Completions. In OpenCode config, the model prefixes are `opencode/<id>` for Zen and `opencode-go/<id>` for Go ([Zen docs](https://opencode.ai/docs/zen/#endpoints), [Go docs](https://opencode.ai/docs/go/#endpoints)). `systemone` is a structured decision endpoint for Jev, not a text-generation model; it should not be advertised as `text_generation` ([Zen Jev section](https://opencode.ai/docs/zen/#jev)).

### Authentication and client identity

- Zen and Go both use an OpenCode API key obtained through the account/console flow ([providers guide](https://opencode.ai/docs/providers/#opencode-zen), [Go guide](https://opencode.ai/docs/go/#how-it-works)). Go adds a subscription entitlement; it is not a new wire credential format.
- Use `Authorization: Bearer $OPENCODE_API_KEY` for OpenAI Responses, OpenAI-compatible chat completions, and SystemOne. Use `x-api-key` for the Anthropic Messages family; the server’s Anthropic adapter adds `anthropic-version: 2023-06-01` by default ([adapter source](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/util/provider/anthropic.ts#L19-L40)). Use `x-goog-api-key` for the native Gemini family ([adapter source](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/util/provider/google.ts#L29-L37)).
- Go’s official guidance asks clients to send a stable `x-opencode-session` value for each conversation and an identifying User-Agent; the server uses that session value for sticky routing/prompt caching ([Go guidance](https://opencode.ai/docs/go/#where-can-i-use-it), [handler source](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/util/handler.ts#L125-L145)). Treat this as recommended client behavior, not as a substitute for an API key.

### Model listing and metadata

The official docs say to fetch the full list at [`https://opencode.ai/zen/v1/models`](https://opencode.ai/docs/zen/#models) and [`https://opencode.ai/zen/go/v1/models`](https://opencode.ai/docs/go/#models). In the current server implementation, the serializer emits:

```json
{
  "object": "list",
  "data": [
    {"id": "…", "object": "model", "created": 0, "owned_by": "opencode"}
  ]
}
```

The route returns the list without requiring a key, while a key can be used to exclude disabled models in the Zen route ([Zen model route](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/v1/models.ts#L14-L42), [Go model route](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/go/v1/models.ts#L10-L15), [serializer](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/util/modelsHandler.ts#L12-L30)). The richer internal model schema does contain cost, provider routing, `formatFilter`, and `rateLimit` fields, but those are not emitted by this public response ([internal schema](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/core/src/model.ts#L10-L79)).

Therefore, `/models` is suitable for catalog discovery, not for proving credentials, credits, entitlement, or text capability. A connector should use a reviewed model map to supply WordPress `ModelMetadata`, and should not use the generic `ListModelsApiBasedProviderAvailability` helper for OpenCode without first proving that the endpoint authenticates. The first-party helper simply calls `listModelMetadata()` and treats any exception as unavailable ([source](https://github.com/WordPress/wordpress-develop/blob/7.0.0/src/wp-includes/php-ai-client/src/Providers/ApiBasedImplementation/ListModelsApiBasedProviderAvailability.php#L9-L50)); that assumption is unsafe for this public catalog.

### Errors, credits, and limits

The current official server maps its error classes to a small JSON envelope:

```json
{
  "type": "error",
  "error": {"type": "CreditsError", "message": "…"}
}
```

The current mapping is 401 for `AuthError`, `CreditsError`, monthly/user limit errors, and `ModelError`; 403 for `RegionError` and `DataPolicyError`; 429 for `RateLimitError`, `FreeUsageLimitError`, `GoUsageLimitError`, and `BlackUsageLimitError`; and 500 for an unclassified internal error. A 429 can include `Retry-After`, and Go limit errors add `metadata.workspace` and `metadata.limitName` ([error classes](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/util/error.ts#L1-L29), [HTTP mapping](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/util/handler.ts#L472-L543)). Clients should branch on status plus `error.type`, honor `Retry-After`, and avoid hard-coding human messages.

- Zen is pay-as-you-go. The docs describe balance auto-reload ($20 when the balance falls below $5 by default), workspace/member monthly limits, disabled models returning an error, and BYOK billing being charged by the underlying provider ([pricing/limits](https://opencode.ai/docs/zen/#pricing), [auto-reload/monthly limits](https://opencode.ai/docs/zen/#auto-reload), [model access/BYOK](https://opencode.ai/docs/zen/#model-access)). Free models may still be present while the account/key is required by the product flow; do not infer that a listed “Free” ID is universally anonymous.
- Go is a $10/month subscription. The current docs define each model’s monthly dollar limit and rolling allowances of 20% over 5 hours, 50% weekly, and 100% monthly; limits and prices may change ([usage limits](https://opencode.ai/docs/go/#usage-limits)). If Go limits are exhausted, the optional **Use balance** setting can fall back to the Zen balance ([usage beyond limits](https://opencode.ai/docs/go/#usage-beyond-limits)).
- `GET https://opencode.ai/zen/go/v1/usage` requires a Bearer key and returns `rolling`, `weekly`, and `monthly` objects, each with `status`, `percent`, and `resetsAt`; missing/invalid keys return 401 and missing Go entitlement returns 403 ([official usage route](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/go/v1/usage.ts#L11-L161)). This is preferable to hard-coding prices or limits in the plugin.
- The server also applies per-key model request limiting (default 1,000 per minute when no model-specific value is configured) and IP limits for anonymous/free traffic ([key limiter](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/util/keyRateLimiter.ts#L6-L36), [IP limiter](https://github.com/anomalyco/opencode/blob/0f549842ee746e400b1f72516b0b2e292e267e2c/packages/console/app/src/routes/zen/util/ipRateLimiter.ts#L8-L54)). These are current implementation limits, not a stable public quota contract; expose backoff and usage rather than promising a fixed throughput.

## 3. Reviewer action: safe pin and controlled float

### Release observed

At the snapshot, the official release page is [v1.22.0](https://github.com/nilesh32236/opencode-ai-reviewer/releases/tag/v1.22.0). The annotated tag object is `e96e3d74a74f82180d6df5eef404ffd1dee82b95`; the peeled release commit is [`103082c963f64cb2cf979ae14b729ec41d40866e`](https://github.com/nilesh32236/opencode-ai-reviewer/commit/103082c963f64cb2cf979ae14b729ec41d40866e). The action manifest at that tag runs Node 24 with `action/lib/index.js` and a post entrypoint ([`action.yml`](https://github.com/nilesh32236/opencode-ai-reviewer/blob/v1.22.0/action.yml#L483-L486)). Its first-party README documents direct and reusable-workflow usage and requires `github_token`; the released action also exposes `opencode_api_key` and leaves `enable_mcp` disabled by default ([README](https://github.com/nilesh32236/opencode-ai-reviewer/blob/v1.22.0/README.md#quick-start--github-action), [inputs](https://github.com/nilesh32236/opencode-ai-reviewer/blob/v1.22.0/action.yml#L1-L42)).

### Pin (recommended)

Use the peeled release commit, with the release version in a comment:

```yaml
# direct action
- uses: nilesh32236/opencode-ai-reviewer@103082c963f64cb2cf979ae14b729ec41d40866e # v1.22.0
  with:
    github_token: ${{ secrets.GITHUB_TOKEN }}
    opencode_api_key: ${{ secrets.OPENCODE_API_KEY }}

# reusable workflow
uses: nilesh32236/opencode-ai-reviewer/.github/workflows/review.yml@103082c963f64cb2cf979ae14b729ec41d40866e # v1.22.0
```

This is the recommended pattern in GitHub’s [secure-use guidance](https://docs.github.com/en/actions/reference/security/secure-use#using-third-party-actions) and [workflow syntax reference](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#using-a-third-party-action): full commit SHAs are immutable; tags and branches can be moved. Keep `enable_mcp: false` unless the campaign has separately reviewed its runtime context behavior. Separately, do not enable the action’s Jev pre-filter without reviewing its documented data-sharing behavior: it sends truncated finding/context/diff summaries to `https://opencode.ai/zen/v1/systemone` ([input documentation](https://github.com/nilesh32236/opencode-ai-reviewer/blob/v1.22.0/README.md#L154-L170)).

### Float (when “latest release” is required)

There is no safe official floating latest-release channel in this repository at the snapshot: `@main` is mutable and can contain unreleased work, while the repository’s `v1` tag is stale (it points to `e1b374f41a3fe85bf8b3504a9d56fd9ed3515e28`, the v1.5.4-era line). A safe equivalent of floating is automated, reviewed updates rather than a live branch:

1. Keep the full SHA reference above.
2. Enable Dependabot’s `github-actions` ecosystem for the repository with a regular schedule (the official [Dependabot options reference](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference) documents `github-actions` and update schedules).
3. Let Dependabot propose the next release-tag SHA, then require normal review/CI before merging. This preserves reproducibility while still moving to the latest release on a controlled cadence.

If exact release discovery is needed at update time, use the release API in an update job, verify the release is non-draft/non-prerelease, resolve the tag to its peeled commit, and write that SHA with a reviewable version comment. Do not replace the pinned SHA with `@main` or the stale `@v1` merely to make the YAML look current.

### Campaign-local observation

As of this research, the repository workflows use mutable `@main` references at [`.github/workflows/ai-review.yml`](../../.github/workflows/ai-review.yml#L83) (also lines 162 and 237) and [`.github/workflows/daily-audit.yml`](../../.github/workflows/daily-audit.yml#L123). No workflow was changed by this research task; the recommendation is to replace those references only in a separately reviewed change after selecting a release SHA.

## Primary-source checklist

- [WordPress AI Client dev note](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/)
- [WordPress Connectors API dev note](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/)
- [WordPress 7.0.0 core source](https://github.com/WordPress/wordpress-develop/tree/7.0.0) and [7.0.6 tag](https://github.com/WordPress/wordpress-develop/tree/7.0.6)
- [OpenCode Zen docs](https://opencode.ai/docs/zen/) and [Go docs](https://opencode.ai/docs/go/)
- [OpenCode source at the inspected commit](https://github.com/anomalyco/opencode/tree/0f549842ee746e400b1f72516b0b2e292e267e2c)
- [Reviewer v1.22.0 release](https://github.com/nilesh32236/opencode-ai-reviewer/releases/tag/v1.22.0) and [release commit](https://github.com/nilesh32236/opencode-ai-reviewer/commit/103082c963f64cb2cf979ae14b729ec41d40866e)
- [GitHub secure action use](https://docs.github.com/en/actions/reference/security/secure-use#using-third-party-actions) and [Dependabot options](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference)
