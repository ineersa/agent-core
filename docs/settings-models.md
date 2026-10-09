---
builtin: true
description: AI providers, model selection, reasoning levels, HTTP and retry settings.
---

# Model and Provider Settings

Model configuration lives under the top-level `ai:` section in Hatfield settings.
Secrets (API keys) belong in `~/.hatfield/settings.yaml` using `env:VAR` syntax, not plain text in project files when avoidable.

Known providers (`zai`, `deepseek`, `opencode-go`, `openai-codex`, `grok-cli`) ship as the bundled
`config/ai-catalog.yaml` (definitions present, `enabled: false`) and are copied to
`~/.hatfield/ai-catalog.yaml` on first run. That user catalog is the source of
provider/model defaults; enablement is manual (`providers:setup` or sparse settings).
Settings `models:` overrides the catalog list wholesale. Full catalog behavior
(rebase, deltas-only sync, version skew, hand-adding models): [ai-catalog.md](ai-catalog.md).

Core settings overview: [settings.md](settings.md).

## Selection keys

| Key | Meaning |
|---|---|
| `ai.default_model` | Default `provider/model` reference |
| `ai.default_reasoning` | Default reasoning/thinking level when supported |
| `ai.favorite_models` | Optional quick-pick list for the TUI model picker |

Every selectable model must be listed under its provider. Unknown model names are rejected even if a backend could load arbitrary models.

## Provider entries (`ai.providers`)

Each provider key names a configured provider, such as `deepseek`, `openai-codex`, or `llama-local`. ChatGPT and Grok each use one saved OAuth account. Provider IDs do not select accounts.

For catalog providers, settings may stay sparse — scalars such as `enabled` / `api_key` /
`base_url` override the catalog; an explicit `models:` map replaces the catalog models
wholesale. Unknown provider ids (custom llama.cpp, RunPod, …) remain full definitions
in settings and pass through unchanged.

OpenCode Go uses `OPENCODE_API_KEY` by default. Run `hatfield providers:update`
and enable the provider with `hatfield providers:setup`. An explicit `api_key`
setting overrides the default, including `env:` references.

Muse uses the Responses endpoint; the other bundled models use chat completions.
OpenCode restricts Muse by region and permits training on Contributor prompts
and completions. `/usage` does not report Go subscription limits because the
console endpoint requires separate browser-session credentials.

Common fields:

| Field | Meaning |
|---|---|
| `type` | Provider bridge type (for example `generic`, `chatgpt`, `grok`) |
| `enabled` | Whether the provider is active |
| `base_url` | API base URL |
| `api` | Wire API family (for example `openai-completions`) |
| `api_key` | Secret or `env:NAME` |
| `completions_path` | Completions path when non-default |
| `supports_completions` / `supports_embeddings` | Capability flags |
| `compatibility` | Transport quirks (thinking format, required fields) |
| `models` | Map of model id → model metadata |

OAuth providers:

- `type: chatgpt` uses verified direct-token OAuth in `~/.hatfield/chatgpt-auth.json` via `bin/console auth:chatgpt login`. The bundled identifier remains `openai-codex` to preserve existing model references. Old Codex grants are not reused.
- `type: grok` (Grok CLI / cli-chat-proxy) stores tokens under key `grok-cli` via `bin/console auth:grok`. Do not set `api_key`.

ChatGPT uses HTTP/SSE only at `https://api.openai.com/v1/responses` and sends full history on every request. There are no `transport` or WebSocket-cache settings. Remove old `type: codex` overrides; they are unsupported. The configured YAML model list is not proof of account entitlement. `/usage` shows the plan-management URL, not a numeric ChatGPT quota.

Model metadata typically includes display `name`, `context_window`, `max_tokens`,
`input` modalities, `tool_calling`, `reasoning`, optional `thinking_level_map`, and `cost`.

## Reasoning / thinking levels

When a model advertises reasoning support, Hatfield maps user-facing levels
(`minimal`, `low`, `medium`, `high`, `xhigh`, …) through the model’s `thinking_level_map`
or provider compatibility rules. Unsupported levels are rejected or coerced per provider bridge.

`ai.default_reasoning` supplies the session default; TUI `/model` flows may persist sparse overrides.

ChatGPT sends the selected effort directly with each stateless request. It does not freeze a reasoning baseline, emit `configuration_update`, or manage response-chain continuation.

`gpt-6.1-sol` supports `low`, `medium`, `high`, `xhigh`, and `max` reasoning
efforts. Selecting `off` or `minimal` sends no effort value; neither disables
reasoning.

## HTTP client (`ai.http`)

Controls outbound LLM HTTP timeouts and the **application** retry budget for one platform invocation. ChatGPT generation overrides the shared defaults with 300 seconds idle and `max_duration: 0`; there is no hard total generation deadline. OAuth operations have separate bounded budgets. Known permanent ChatGPT subscription-limit errors are terminal and are not retried.

| Key | Default | Meaning |
|---|---|---|
| `timeout` | `30` | Idle timeout per HTTP request (seconds) |
| `max_duration` | `120` | Total request duration budget (seconds) |
| `max_retries` | `5` | Retries after the initial attempt (six attempts total) |
| `base_delay_ms` | `1000` | Exponential backoff base delay |
| `max_delay_ms` | `60000` | Cap for a single backoff delay |

`timeout` is the SSE/body idle timeout while waiting for the next chunk after headers; it is not the total wall budget (`max_duration`). A silent HTTP 200 stream therefore fails over through `LlmRequestRetryExecutor` at `timeout`, not only when `max_duration` elapses. User cancel during that wait aborts the in-flight request through the HTTP progress hook instead of waiting for either timer.

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
