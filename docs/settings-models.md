---
builtin: true
description: AI providers, model selection, reasoning levels, HTTP and retry settings.
---

# Model and Provider Settings

Model configuration lives under the top-level `ai:` section in Hatfield settings.
Secrets (API keys) belong in `~/.hatfield/settings.yaml` using `env:VAR` syntax, not plain text in project files when avoidable.

Known providers (`zai`, `deepseek`, `openai-codex`, `grok-cli`) ship as the bundled
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

Each provider key is a logical account/name (for example `deepseek`, `openai-codex`, `openai-codex-work`).

For catalog providers, settings may stay sparse — scalars such as `enabled` / `api_key` /
`base_url` override the catalog; an explicit `models:` map replaces the catalog models
wholesale. Unknown provider ids (custom llama.cpp, RunPod, …) remain full definitions
in settings and pass through unchanged.

Common fields:

| Field | Meaning |
|---|---|
| `type` | Provider bridge type (for example `generic`, `codex`, `grok`) |
| `enabled` | Whether the provider is active |
| `base_url` | API base URL |
| `api` | Wire API family (for example `openai-completions`) |
| `api_key` | Secret or `env:NAME` |
| `completions_path` | Completions path when non-default |
| `supports_completions` / `supports_embeddings` | Capability flags |
| `compatibility` | Transport quirks (thinking format, required fields) |
| `models` | Map of model id → model metadata |

OAuth providers:

- `type: codex` stores tokens under `~/.hatfield/auth.json` key `openai-codex` via `bin/console auth:codex`.
- `type: grok` (Grok CLI / cli-chat-proxy) stores tokens under key `grok-cli` via `bin/console auth:grok`. Do not set `api_key`.

Model metadata typically includes display `name`, `context_window`, `max_tokens`,
`input` modalities, `tool_calling`, `reasoning`, optional `thinking_level_map`, and `cost`.

## Reasoning / thinking levels

When a model advertises reasoning support, Hatfield maps user-facing levels
(`minimal`, `low`, `medium`, `high`, `xhigh`, …) through the model’s `thinking_level_map`
or provider compatibility rules. Unsupported levels are rejected or coerced per provider bridge.

`ai.default_reasoning` supplies the session default; TUI `/model` flows may persist sparse overrides.

Codex Astra's model compatibility flag `supports_reasoning_configuration_updates`
keeps the first request's reasoning effort fixed for the active session. Later
requests insert the selected effort as a `configuration_update` before new input.
This applies to plain WebSocket, cached WebSocket, and SSE. Resume or a model
change starts a new baseline from the current selection. Explicit compaction
overrides remain separate. The flag defaults to false and is enabled only for
`gpt-6-astra` in the bundled catalog. A settings-level `models` map replaces the
catalog models, so pinned Astra definitions must include the flag to enable it.

## HTTP client (`ai.http`)

Controls outbound LLM HTTP timeouts and the **application** retry budget for one platform invocation.

| Key | Default | Meaning |
|---|---|---|
| `timeout` | `30` | Idle timeout per HTTP request (seconds) |
| `max_duration` | `120` | Total request duration budget (seconds) |
| `max_retries` | `5` | Retries after the initial attempt (six attempts total) |
| `base_delay_ms` | `1000` | Exponential backoff base delay |
| `max_delay_ms` | `60000` | Cap for a single backoff delay |

Transport-layer `RetryableHttpClient` retries stay at **0**. `LlmRequestRetryExecutor` owns the single bounded budget, including HTTP status errors, idle timeouts, failed stream chunks, and thinking-only recoveries. Retry progress is emitted as transient `llm.request_retrying` (seq=`0`) for the TUI working status. User cancellation stops further attempts and backoff. Failed partial streams are discarded before the next attempt; tool side effects are not replayed.

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
