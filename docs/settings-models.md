---
builtin: true
description: AI providers, model selection, reasoning levels, HTTP and retry settings.
---

# Model and Provider Settings

Model configuration lives under the top-level `ai:` section in Hatfield settings.
Secrets (API keys) belong in `~/.hatfield/settings.yaml` using `env:VAR` syntax, not plain text in project files when avoidable.

Known providers (`mistral`, `zai`, `deepseek`, `opencode-go`, `openai-codex`, `grok-cli`) ship as the bundled
`config/ai-catalog.yaml` (definitions present, `enabled: false`) and are copied to
`~/.hatfield/ai-catalog.yaml` on first run. That user catalog is the source of
provider/model defaults; enablement is manual (`setup` or sparse settings).
Settings `models:` overrides the catalog list wholesale. Full catalog behavior
(rebase, deltas-only sync, version skew, hand-adding models): [ai-catalog.md](ai-catalog.md).

Core settings overview: [settings.md](settings.md).

## Selection keys

| Key | Meaning |
|---|---|
| `ai.default_model` | Default `provider/model` reference |
| `ai.default_reasoning` | Default reasoning/thinking level when supported |
| `ai.favorite_models` | Ordered `provider/model` list cycled by Ctrl+P and managed with `/model-favourites` (saved to user settings) |

Every selectable model must be listed under its provider. Unknown model names are rejected even if a backend could load arbitrary models.

## Provider entries (`ai.providers`)

Each provider key names a configured provider, such as `deepseek`, `openai-codex`, or `llama-local`. ChatGPT and Grok each use one saved OAuth account. Provider IDs do not select accounts.

For catalog providers, settings may stay sparse — scalars such as `enabled` / `api_key` /
`base_url` override the catalog; an explicit `models:` map replaces the catalog models
wholesale. Unknown provider ids (custom llama.cpp, RunPod, …) remain full definitions
in settings and pass through unchanged.

OpenCode Go uses `OPENCODE_API_KEY` by default. Run `hatfield providers:update`
and enable the provider with the Providers tab in `hatfield setup`. An explicit `api_key`
setting overrides the default, including `env:` references.

Muse uses the Responses endpoint; the other bundled models use chat completions.
OpenCode restricts Muse by region and permits training on Contributor prompts
and completions. `/usage` does not report Go subscription limits because the
console endpoint requires separate browser-session credentials.

### ChatGPT GPT-6.2 models

Run `hatfield providers:update` to refresh an existing user catalog, then select
`openai-codex/gpt-6.2-astra` (GPT-6.2 Astra) or
`openai-codex/gpt-6.2-sol` (GPT-6.2 Sol). This does not switch your default model.
Both support text/image input, function tools, and API reasoning efforts
`low`, `medium`, `high`, `xhigh`, and `max`. A requested UI level of `off` or
`minimal` omits the effort option; it does not disable reasoning or send
`none`/`minimal` to the API.

Hatfield pins both context windows to the curated **272,000-token** pricing tier
and caps output at **128,000 tokens**. The upstream API model pages advertise a
raw 1.05M-token window and premium pricing above 272K; these are not Hatfield's
configured limits. Codex metadata also differs (Astra 272K, Sol 500K), so neither
upstream API nor Codex windows should replace the curated cap during updates.
See the official [Astra](https://developers.openai.com/api/docs/models/gpt-6.2-astra)
and [Sol](https://developers.openai.com/api/docs/models/gpt-6.2-sol) model pages,
and [Pi's Codex catalog](https://github.com/earendil-works/pi/blob/cead615b48b80d1c61eecab2f0b576d16eeaf4c5/packages/ai/catalog/providers/openai-codex.ts).

### Mistral and Vibe subscription keys

Mistral uses Symfony AI's native Mistral bridge at `https://api.mistral.ai`, including
Mistral-specific tool/message formatting and streamed thinking content. Run
`hatfield providers:update`, then the Providers tab in `hatfield setup` and select **Mistral**.
The default environment variable is `MISTRAL_API_KEY`; set it in the environment
that launches Hatfield. A manually obtained Vibe/subscription API key or a regular
Mistral API key can be supplied. Hatfield does not perform Mistral browser sign-in
or read Vibe's credential files.

Sparse settings are sufficient:

```yaml
ai:
  providers:
    mistral:
      enabled: true
      api_key: env:MISTRAL_API_KEY
```

Only these models are bundled (prices in USD per million tokens):

| Model reference | Input | Cached input | Output | Reasoning levels |
|---|---:|---:|---:|---|
| `mistral/zai-glm-5-3-flash` | 0.15 | 0.03 | 0.50 | low, high, max |
| `mistral/deepseek-v4-1-flash-1017` | 0.30 | 0.007 | 1.20 | off, low, high, xhigh, max |
| `mistral/zai-glm-5-3` | 1.40 | 0.14 | 4.40 | low, high, max |
| `mistral/mistral-large-4` | 0.70 | 0.07 | 2.10 | low, medium, high |

All four support tools; all except GLM 5.3 support image input. GLM 5.3/Flash
and Large 4 cannot disable reasoning; an inherited **off** setting leaves the
API's default reasoning enabled. Model access depends on the key and plan.
An explicit `api_key` setting overrides the catalog default.

Subscription usage consumes the plan's shared Studio/API/Vibe budget; it is not
unlimited. Regular API keys may incur ordinary API charges. Hatfield's token cost
display uses API-equivalent prices, not your subscription balance or bill.
Hatfield does not currently query Mistral subscription quota.

### Z.ai (GLM)

The `zai` provider ships `glm-5.3`, `glm-5.3-flash`, and `glm-5.4` against the
GLM Coding Plan endpoint (`https://api.z.ai/api/coding/paas/v4`). Sparse settings
stay sufficient:

```yaml
ai:
  providers:
    zai:
      enabled: true
      api_key: env:ZAI_API_KEY
```

**GLM 5.4** (released 2026-11-23, multimodal: text/image/video/pdf → text, 1M
context, 128K output, thinking levels `low`/`high`/`max`) is available through the
GLM Coding Plan only; its Model API access is still pending security assessment and
no pay-as-you-go rates are published, so the catalog entry intentionally omits
`cost` (unknown, not free). The bundled `thinking_level_map` and `zai_tool_stream`/`supports_reasoning_effort`
compatibility mirror GLM 5.3/Flash.

Common fields:

| Field | Meaning |
|---|---|
| `type` | Provider bridge type: `generic` (default, OpenAI-compatible chat completions), `mistral`, `opencode-go`, `chatgpt`, or `grok` |
| `enabled` | Whether the provider is active |
| `base_url` | API base URL |
| `api` | Descriptive wire API label in catalog entries (for example `openai-completions`); the bridge is selected by `type`, not this field |
| `api_key` | Secret or `env:NAME` |
| `completions_path` | Completions path when non-default |
| `embeddings_path` | Embeddings path when non-default (default `/v1/embeddings`) |
| `supports_completions` / `supports_embeddings` | Capability flags (defaults `true` / `false`) |
| `supports_thinking_levels` | Set `false` to disable reasoning-level cycling for the provider's models (default `true`) |
| `compatibility` | Transport quirks (thinking format, required fields) |
| `models` | Map of model id → model metadata |

OAuth providers:

- `type: chatgpt` uses verified direct-token OAuth in `~/.hatfield/chatgpt-auth.json` via `bin/console auth:chatgpt login`. The bundled identifier remains `openai-codex` to preserve existing model references. Old Codex grants are not reused.
- `type: grok` (Grok CLI / cli-chat-proxy) stores tokens under key `grok-cli` via `bin/console auth:grok`. Do not set `api_key`.

ChatGPT uses HTTP/SSE only at `https://api.openai.com/v1/responses` and sends full history on every request. There are no `transport` or WebSocket-cache settings. Remove old `type: codex` overrides; they are unsupported. The configured YAML model list is not proof of account entitlement. `/usage` shows the plan-management URL, not a numeric ChatGPT quota.

Model metadata typically includes display `name`, `context_window`, `max_tokens`,
`input` modalities, `tool_calling`, `reasoning`, optional `thinking_level_map`, `cost`,
and optional per-model `compatibility` (for example `pin_context_window`).

## Reasoning / thinking levels

When a model advertises reasoning support, Hatfield maps user-facing levels
(`off`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max`) through the model’s
`thinking_level_map` and provider compatibility rules. A level the map does not
cover, or maps to `null`, sends no reasoning option, so the provider default applies.
`off` is the exception for z.ai and DeepSeek thinking formats, which explicitly
disable thinking.

`ai.default_reasoning` supplies the session default; TUI `/model` flows may persist sparse overrides.

ChatGPT sends the selected reasoning level as request-level `reasoning.effort` on every request, including resumed sessions and forks. Changing the effort can reduce prompt-cache reuse. Native inline configuration updates are unsupported.

`gpt-6.1-sol` supports `low`, `medium`, `high`, `xhigh`, and `max` reasoning
efforts. Selecting `off` or `minimal` sends no effort value; neither disables
reasoning.

## HTTP client (`ai.http`)

Controls outbound LLM HTTP timeouts and the **application** retry budget for one platform invocation. ChatGPT generation overrides the shared defaults with 300 seconds idle and `max_duration: 0`; there is no hard total generation deadline. OAuth operations have separate bounded budgets. Known permanent ChatGPT subscription-limit errors are terminal and are not retried.

| Key | Default | Meaning |
|---|---|---|
| `timeout` | `30` | Idle timeout per HTTP request (seconds) |
| `max_duration` | `0` | Total request duration budget (seconds); `0` = no total limit |
| `max_retries` | `5` | Retries after the initial attempt (six attempts total) |
| `base_delay_ms` | `1000` | Exponential backoff base delay |
| `max_delay_ms` | `60000` | Cap for a single backoff delay |

`timeout` is the SSE/body idle timeout while waiting for the next chunk after headers. A silent HTTP 200 stream therefore fails over through `LlmRequestRetryExecutor` at `timeout`. User cancel during that wait aborts the in-flight request through the HTTP progress hook instead of waiting for the timer.

`max_duration` caps the whole request, including a stream that is still producing reasoning or output. It is `0` (off) by default so that long generations are not cut off; set a positive number only to force a total wall budget on every provider.

`LlmRequestRetryExecutor` owns the single bounded budget, including HTTP status errors, idle timeouts, failed stream chunks, and thinking-only recoveries. Outbound LLM HTTP clients apply only `timeout` / `max_duration`; they are not wrapped in Symfony `RetryableHttpClient`. Retry progress is emitted as transient `llm.request_retrying` (seq=`0`) for the TUI working status. User cancellation stops further attempts and backoff. Failed partial streams are discarded before the next attempt; tool side effects are not replayed.

After the application budget is exhausted, failures are terminal (`retryable: false`) and emit `llm_step_failed` then `agent_end(reason=failed)`. Messenger `llm` transport retries are not a second hidden LLM retry budget for these classified provider errors.

## Model reference format

Use `providerKey/modelId` (illustrative example: `deepseek/deepseek-v4-pro`). The provider key is the map key under `ai.providers`, not necessarily a public vendor slug.

## Minimal example

Illustrative only — provider/model names and context sizes below are **examples**, not built-in defaults. Copy and adapt to your real providers.

```yaml
ai:
  default_model: deepseek/deepseek-v4-pro
  default_reasoning: medium
  providers:
    deepseek:
      type: generic
      enabled: true
      base_url: https://api.deepseek.com
      api: openai-completions
      api_key: env:DEEPSEEK_API_KEY
      supports_completions: true
      models:
        deepseek-v4-pro:
          name: DeepSeek V4 Pro
          context_window: 1000000
          max_tokens: 384000
          input: [text]
          tool_calling: true
          reasoning: true
```

## Related

- Compaction model overrides: [compaction.md](compaction.md)
- Session identity / provider cache key: [session-storage.md](session-storage.md)
