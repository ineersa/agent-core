# Hatfield Settings

Hatfield is the application name for the local coding agent configuration system.
It follows the same pattern as `~/.gitconfig` or `.editorconfig`: global
settings in the home directory, overridden by project-local settings.

## First launch

On first launch the global home settings file (`~/.hatfield/settings.yaml`)
is created by copying `config/hatfield.defaults.yaml`. The defaults file is
designed with comments that double as documentation, so the copied home file
is self-documenting.

Edit the home copy to set personal API keys, default model, reasoning level,
and other overrides. The file is **never auto-overwritten**; it remains yours
to maintain across application upgrades.

## Directory layout

```text
~/.hatfield/settings.yaml       # Global user settings (copied from defaults on first launch)
<project>/.hatfield/settings.yaml  # Project-local overrides
.hatfield/sessions/    # Session/run storage (session_id === run_id)
<project>/.hatfield/themes/      # Custom project themes
```

## Precedence

```
built-in defaults  <  ~/.hatfield/settings.yaml  <  <project>/.hatfield/settings.yaml
```

Project settings win over home settings, which win over built-in defaults.

## File format

All settings files use YAML. Example:

```yaml
tui:
    theme: cyberpunk
    theme_paths:
        - '%kernel.project_dir%/config/themes'
        - '.hatfield/themes'
        - '~/.hatfield/themes'

sessions:
    path: '.hatfield/sessions'
```

## Merge rules

- **Associative maps** (with string keys): merge recursively. Higher-priority
  settings override matching keys in lower-priority layers. Unmatched keys are
  preserved.
- **List arrays** (with numeric keys): replace entirely. A list in a
  higher-priority layer completely replaces the list from a lower-priority
  layer.

## Path resolution

Path values support the following placeholders:

| Placeholder | Resolves to |
|---|---|
| `%kernel.project_dir%` | App installation directory |
| `~` | Home directory |
| Relative paths in defaults | App project directory |
| Relative paths in home settings | Home directory |
| Relative paths in project settings | Project working directory |

## Settings keys

### `tui.theme`

The active theme name. Must match a theme registered in one of the
theme search paths.

**Default:** `cyberpunk`

**Built-in themes:** `cyberpunk`, `catppuccin-mocha`, `nord`,
`gruvbox-dark`, `oh-p-dark`, `tokyo-night`

### `tui.theme_paths`

Directories to search for YAML theme files. Ordered by priority:
first match wins. Built-in paths include the app's bundled themes
directory and `.hatfield/themes` for user themes.

**Default:**
```yaml
tui:
    theme_paths:
        - '%kernel.project_dir%/config/themes'
        - '.hatfield/themes'
        - '~/.hatfield/themes'
```

### `sessions.path`

Directory where session/run data is stored. Each session equals
one agent run (session_id === run_id).

**Default:** `.hatfield/sessions`

See [Session Storage](session-storage.md) for the full directory layout,
file purposes, resume flow, locking, future fork tree design, and
backward compatibility.

### `logging.path`

Directory where application log files are stored. Relative paths resolve
against the active project working directory (CWD), just like
`sessions.path`. Supports the same placeholders: `%kernel.project_dir%`
and `~`.

**Default:** `.hatfield/logs` (resolves to `<CWD>/.hatfield/logs`)

### `logging.level`

Minimum log level for application logging. Logs are written as JSONL
under the configured `logging.path` with daily rotation. Use a PSR-3 level name.

**Allowed values:** `debug`, `info`, `notice`, `warning`, `error`,
`critical`, `alert`, `emergency`

**Default:** `info`

### `logging.max_files`

Maximum number of daily rotated log files to retain before deletion.
Files are stored under `logging.path`. The default of 14 keeps two weeks of logs.

**Default:** `14`

### `tools.execution.default_mode`

Default execution mode for tool calls. Controls whether tools run
sequentially (one at a time) or in parallel when multiple tool calls
are dispatched in a single turn.

- `sequential`: one tool at a time (default).
- `parallel`: requires TOOLS-R05 / multi-consumer dispatch.

**Default:** `sequential`

**Example:**

```yaml
tools:
    execution:
        default_mode: sequential
```

---

### `tools.execution.timeout_seconds`

Default timeout in seconds exposed to tool implementations through the
current tool execution context. Concrete tools that own long-running
loops or subprocesses are responsible for checking this value together
with the cancellation token.

**Default:** `300` (5 minutes)

---

### `tools.execution.max_parallelism`

Maximum number of tool calls to execute concurrently when
`default_mode` is `parallel`. Also controls the default number of
parallel `messenger:consume tool` worker processes launched by the
controller — each worker picks up one `ExecuteToolCall` message from
the tool transport queue, enabling up to `max_parallelism` concurrent
tool executions.

See `docs/tool-execution.md` for the durable batch store and
dispatch pipeline.

**Default:** `4`

---

### `tools.output_cap.path`

Storage directory for persisted oversized tool output. Tool output that
exceeds the configured character cap is written to this directory and
replaced with a model-facing capped notice containing the saved path and
inspection hints (`head -50` / `grep`).

Relative paths resolve against the active project CWD.

**Default:** `.hatfield/tmp/output-cap` (resolves to `<CWD>/.hatfield/tmp/output-cap`)

### `tools.output_cap.default_cap`

Maximum number of characters for non-doc-like tool output (code files,
binary paths, unknown extensions). Output exceeding this limit is capped
and persisted.

**Default:** `20000`

### `tools.output_cap.doc_cap`

Maximum number of characters for doc-like tool output (`.md`, `.txt`,
`.toon` extensions). Doc-like files get a higher cap because they
are typically intended for human reading.

**Default:** `50000`

### `tools.output_cap.retention`

Maximum age in seconds for persisted output cap files before they are
deleted during cleanup. Cleanup runs automatically on first use of the
output capping service.

**Default:** `86400` (24 hours)

### `tools.output_cap.session_prefix`

Optional session/run prefix for persisted output filenames. When set,
filenames use the format `<session_prefix>-<random_hex>.txt` instead of
`<date>-<random_hex>.txt`. When unset, persisted output files use a
`Ymd` date prefix.

**Default:** `null` (falls back to `Ymd` date prefix)

### `tools.image.max_bytes`

Maximum file size in bytes for the `view_image` tool. Images larger
than this are rejected with a clear error message before reading the
full file content, avoiding unnecessary I/O.

**Default:** `10485760` (10 MB)

### `tools.image.max_width`

Maximum image width in pixels for the `view_image` tool. Images wider
than this are rejected after dimension detection.

**Default:** `4096`

### `tools.image.max_height`

Maximum image height in pixels for the `view_image` tool. Images taller
than this are rejected after dimension detection.

**Default:** `2000`

### `tools.image.max_dimension`

Maximum pixel dimension for the resize-to-fit pipeline. Images exceeding
this in either dimension are scaled down to fit within a
`max_dimension × max_dimension` bounding box while preserving aspect ratio.
This is the **resize target**, not a rejection limit.

**Default:** `2000`

### `tools.image.encoded_max_bytes`

Maximum allowed base64-encoded payload length in bytes for provider-safe
image delivery. If the encoded image exceeds this limit after resizing,
the processor tries quality reduction, format conversion (JPEG/WebP), and
progressive dimension reduction (0.75× steps) to stay under the limit.

**Default:** `4718592` (4.5 MiB — safe below Anthropic/OpenAI 5 MiB limits)

### `tools.image.jpeg_quality`

Starting JPEG/WebP compression quality (1–100) for encoded image output.
Higher values produce larger files.

**Default:** `80`

### `tools.image.jpeg_min_quality`

Minimum JPEG/WebP compression quality the processor may attempt during
size-reduction fallback. The processor descends from `jpeg_quality` down
to this minimum in steps before reducing image dimensions.

**Default:** `40`

### `extensions.enabled`

List of enabled extension class names. Each class must implement
`Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface`.

Extensions are loaded before the agent runtime starts, allowing them to
register tools and other integrations.

Extension packages installed via Composer under `.hatfield/extensions/vendor/`
are autoloaded automatically at startup when this list is non-empty.

**Default:** `[]` (no extensions enabled).

**Example:**

```yaml
extensions:
  enabled:
    - Acme\HatfieldTaskWorkflow\TaskWorkflowExtension
```

See `.pi/plans/extension-api-phar-plan.md` for the full extension loading
and isolation model.

### `ai.default_model`

The default model used for new agent sessions. Format is
`provider_id/model_name`.

**Default:** first available configured model (when absent).

**Example:** `deepseek/deepseek-v4-pro`

Precedence: CLI option > session metadata > `ai.default_model` > first available.

### `ai.default_reasoning`

The default reasoning/thinking level for new sessions.

**User-facing values:** `off`, `minimal`, `low`, `medium`, `high`, `xhigh`

**Default:** `medium` (when absent).

The user-facing level is translated to provider-specific options
through the selected model's `thinking_level_map`.

### `ai.favorite_models`

A list of favorited models shown first in the `/model` list and cycled
with `Ctrl+P`. Each entry is a `provider_id/model_name` string.

**Default:** empty (`[]` — no favorites).

**Example:**
```yaml
ai:
    favorite_models: [deepseek/deepseek-v4-pro, zai/glm-5.1]
```

Favorites can be toggled via `/model fav <provider/modelname>` in the
TUI. Changes are persisted to home settings immediately and are visible
to other TUI controls in the same session without restarting.

### `ai.providers`

A map of provider IDs to provider configuration. Each provider exposes
one or more explicitly listed models.

**Key:** provider ID — used in model references as `provider_id/model_name`.

Every model must be explicitly listed under its provider. Unknown model
names are rejected, even for local providers that could serve arbitrary
loaded models (e.g. llama.cpp).

#### Provider keys

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `type` | string | yes | Provider type. Currently `generic` (OpenAI chat-completions-style). |
| `enabled` | bool | yes | Whether the provider is available. |
| `base_url` | string | yes | API base URL. |
| `api` | string | yes | API protocol. Currently `openai-completions`. |
| `api_key` | string | yes | API key. Use `env:VAR` to reference an environment variable. |
| `completions_path` | string | no | Chat completions endpoint path. Default: `/chat/completions`. |
| `embeddings_path` | string | no | Embeddings endpoint path (if supported). |
| `supports_completions` | bool | no | Enable completions client. Default: `true`. |
| `supports_embeddings` | bool | no | Enable embeddings client. Default: `false`. |
| `supports_thinking_levels` | bool | no | Whether reasoning-level cycling (Shift+Tab) is meaningful for this provider. When `false`, reasoning levels are not cycled for models from this provider. Default: `true`. |
| `compatibility` | map | no | Provider-level transport quirks (see below). |
| `models` | map | yes | Model definitions keyed by model name. |

#### Compatibility keys

Provider `compatibility` documents transport quirks for providers that are
OpenAI chat-completions-style but may need request-shaping adjustments.

| Key | Type | Description |
|-----|------|-------------|
| `supports_developer_role` | bool | Whether the provider accepts the OpenAI `developer` role. When `false`, map to `system` role. |
| `supports_reasoning_effort` | bool | Whether the provider accepts `reasoning_effort`. When `false`, do not send this parameter. |
| `thinking_format` | string | How reasoning is signalled. `zai` means `enable_thinking: boolean` instead of `reasoning_effort`. |

Model-level `compatibility`:

| Key | Type | Description |
|-----|------|-------------|
| `zai_tool_stream` | bool | Whether the model supports streaming tool-call deltas (z.ai provider only). |

These are **internal Hatfield metadata** consumed by the request-shaping
layer. They are not native to Symfony's generic bridge.

#### Model keys

Each model entry under `providers.<id>.models` supports:

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `name` | string | no | Human-readable display name. Defaults to the model key. |
| `context_window` | int | yes | Maximum context window in tokens. |
| `max_tokens` | int | yes | Maximum output tokens. |
| `input` | list | yes | Supported input modalities: `text`, `image`. |
| `tool_calling` | bool | yes | Whether the model supports tool/function calling. |
| `reasoning` | bool | yes | Whether the model supports thinking/reasoning. |
| `thinking_level_map` | map | no | Maps user-facing levels to provider values (e.g. `{ minimal: high, xhigh: max }`). |
| `compatibility` | map | no | Model-level compatibility quirks. |
| `cost` | map | no | Cost breakdown: `input`, `output`, `cache_read`, `cache_write` (per 1M tokens). |

### Reasoning / thinking levels

User-facing reasoning levels:

```text
off | minimal | low | medium | high | xhigh
```

These are global session/user settings. On each turn the selected
model's `thinking_level_map` translates the user-facing level into a
provider-specific value.

- If the model has `reasoning: false` or the selected level maps to
  `null`, reasoning options are omitted.
- For z.ai (binary reasoning), map any non-off level through
  `thinking_level_map` to `enabled` and send `enable_thinking: true`.
- For providers with `compatibility.supports_reasoning_effort: false`, never
  send the `reasoning_effort` parameter.

### Configured providers

The following providers are documented as examples. Enable and configure
them in your home settings (`~/.hatfield/settings.yaml`).

#### deepseek

OpenAI chat-completions-style provider. Requires `DEEPSEEK_API_KEY` environment variable.

Seed models: `deepseek-v4-pro`, `deepseek-v4-flash`.

#### llama_cpp

Local OpenAI-compatible server. Uses `dummy` API key.

Seed model: `flash` (200k context, 65,536 max tokens, text+image, tool calling).

#### zai

z.ai GLM models via OpenAI chat-completions-style API. Requires `ZAI_API_KEY` environment variable.

**Compat quirks:**

- No developer role — role mapping sends `system` instead of `developer`.
- No reasoning effort — use `enable_thinking` (binary) rather than `reasoning_effort`.
- `thinking_format: zai` — signals the request mapper to use z.ai reasoning conventions.
- `zai_tool_stream` — per-model flag for streaming tool-call deltas; enabled on `glm-5.1` and `glm-5v-turbo`.

Seed models: `glm-5.1`, `glm-5v-turbo`.

All z.ai models have zero cost (plan-based billing).

### Model reference format

Model references use the format `provider_id/model_name`:

```text
deepseek/deepseek-v4-pro
llama_cpp/flash
zai/glm-5.1
```

Models can only be selected if their provider is `enabled: true` and the
model is explicitly listed under that provider's `models` block.

### Example: full ai section

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
            completions_path: /chat/completions
            supports_completions: true
            supports_embeddings: false
            models:
                deepseek-v4-pro:
                    name: DeepSeek V4 Pro
                    context_window: 1000000
                    max_tokens: 384000
                    input: [text]
                    tool_calling: true
                    reasoning: true
                    thinking_level_map:
                        minimal: high
                        low: high
                        medium: high
                        high: high
                        xhigh: max
                    cost:
                        input: 0.435
                        output: 0.87
                        cache_read: 0.003625
                        cache_write: 0
                deepseek-v4-flash:
                    name: DeepSeek V4 Flash
                    context_window: 1000000
                    max_tokens: 384000
                    input: [text]
                    tool_calling: true
                    reasoning: true
                    thinking_level_map:
                        minimal: high
                        low: high
                        medium: high
                        high: high
                        xhigh: max
                    cost:
                        input: 0.14
                        output: 0.28
                        cache_read: 0.0028
                        cache_write: 0

        llama_cpp:
            type: generic
            enabled: true
            base_url: http://192.168.2.38:8052/v1
            api: openai-completions
            api_key: dummy
            completions_path: /chat/completions
            embeddings_path: /embeddings
            supports_completions: true
            supports_embeddings: false
            models:
                flash:
                    name: flash
                    context_window: 200000
                    max_tokens: 65536
                    input: [text, image]
                    tool_calling: true
                    reasoning: false
                    cost:
                        input: 0
                        output: 0
                        cache_read: 0
                        cache_write: 0

        zai:
            type: generic
            enabled: true
            base_url: https://api.z.ai/api/coding/paas/v4
            api: openai-completions
            api_key: env:ZAI_API_KEY
            completions_path: /chat/completions
            supports_completions: true
            supports_embeddings: false
            compatibility:
                supports_developer_role: false
                supports_reasoning_effort: false
                thinking_format: zai
            models:
                glm-5.1:
                    name: GLM 5.1
                    context_window: 200000
                    max_tokens: 131072
                    input: [text]
                    tool_calling: true
                    reasoning: true
                    thinking_level_map:
                        minimal: enabled
                        low: enabled
                        medium: enabled
                        high: enabled
                    compatibility:
                        zai_tool_stream: true
                    cost:
                        input: 0
                        output: 0
                        cache_read: 0
                        cache_write: 0
                glm-5v-turbo:
                    name: GLM 5V Turbo
                    context_window: 200000
                    max_tokens: 131072
                    input: [text, image]
                    tool_calling: true
                    reasoning: true
                    thinking_level_map:
                        minimal: enabled
                        low: enabled
                        medium: enabled
                        high: enabled
                    compatibility:
                        zai_tool_stream: true
                    cost:
                        input: 0
                        output: 0
                        cache_read: 0
                        cache_write: 0
```

## Adding a custom theme

1. Create your theme YAML file:

   ```yaml
   # .hatfield/themes/my-theme.yaml
   name: my-theme
   vars:
       accent: "#8abeb7"
   colors:
       accent: accent
       muted: "#718096"
       header: accent
       # ... other tokens from ThemeColorEnum enum
   ```

2. Select it in project settings:

   ```yaml
   # .hatfield/settings.yaml
   tui:
       theme: my-theme
   ```

## `.hatfield/` policy

The `.hatfield/` directory is **tracked** at the top level so that
project settings (`.hatfield/settings.yaml`) and team themes
(`.hatfield/*.yaml`) can be committed and shared.

Only runtime/generated subdirectories are ignored via
`.hatfield/.gitignore`:

```
sessions/
tmp/
cache/
logs/
```

This lets you commit your `.hatfield/settings.yaml` to share
project-specific settings while keeping transient data out of
version control.

The committed `.hatfield/settings.yaml` in this project serves as both
the project settings file and the example. Customize its values for
your workflow.
