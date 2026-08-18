---
builtin: true
description: AI providers, model selection, reasoning levels, HTTP and retry settings.
---

# Model and Provider Settings

Model configuration lives under the top-level `ai:` section in Hatfield settings.
Secrets (API keys) belong in `~/.hatfield/settings.yaml` using `env:VAR` syntax, not plain text in project files when avoidable.

Known providers (`zai`, `deepseek`, `openai-codex`, `grok-cli`) ship in
`config/ai-catalog.yaml` — that file is the source of provider/model defaults.
Settings `models:` overrides the catalog list wholesale. `bin/console providers:update`
refreshes cost/context metadata from models.dev into `~/.hatfield/cache/models-dev.json`.
Connection settings never come from models.dev.

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

## HTTP client (`ai.http`)

Controls outbound LLM HTTP behavior (timeouts, proxies, and related transport options as defined in defaults).
If unset, the app injects a safe default HTTP timeout so requests cannot hang forever.

## Agent retry (`ai.agent_retry`)

Sparse retry policy for transient provider failures (counts, backoff) as defined in built-in defaults and overrides.

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
