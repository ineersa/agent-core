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

---

### `compaction.auto_enabled`

Controls auto-compaction only. Manual `/compact` is always available.

**Default:** `true`

### `compaction.compact_after_tokens`

Flat token threshold for auto-compaction trigger. When the estimated
context exceeds this threshold, auto-compaction is triggered.
Per-provider and per-model overrides allow per-model thresholds.

**Default:** `120000`

### `compaction.keep_recent_tokens`

Approximate number of newest tokens to retain raw after compaction.
Messages are kept whole — the cut never splits a message — so the actual
retained token count may modestly exceed this target when the nearest safe
boundary is further back (to avoid splitting tool-call groups).

**Default:** `20000`

### `compaction.model`

Summarization model override in `provider/model` format (e.g.
`llama_cpp/flash`). When `null`, the active session model is used.

**Default:** `null`

### `compaction.thinking_level`

Thinking/reasoning level for summarization calls. Typical values:
`off`, `minimal`, `low`, `medium`, `high`, `xhigh`. When `null`,
the session's active thinking level is used.

**Default:** `null`

### `compaction.provider_overrides`

Per-provider compaction override settings. Keys are provider IDs
(e.g. `openai`, `llama_cpp`). Each entry may set `compact_after_tokens`,
`model`, and/or `thinking_level`.

**Default:** `{}`

### `compaction.model_overrides`

Per-model compaction override settings. Keys are `provider/model`
strings (e.g. `openai/gpt-4.1`). Each entry may set `compact_after_tokens`,
`model`, and/or `thinking_level`. Model overrides win over provider
overrides, which win over global settings.

**Default:** `{}`

**Example:**
```yaml
compaction:
    auto_enabled: true
    compact_after_tokens: 120000
    keep_recent_tokens: 20000
    model: null
    thinking_level: null
    provider_overrides:
        openai:
            compact_after_tokens: 120000
            model: openai/gpt-4.1-mini
            thinking_level: low
    model_overrides:
        openai/gpt-4.1:
            compact_after_tokens: 140000
            model: openai/gpt-4.1-mini
            thinking_level: off
```

## Environment variables

### `HATFIELD_CAPTURE_ERRORS`

Controls whether uncaught infrastructure exceptions are converted
into user-visible runtime/TUI failures or allowed to crash the process.

- `1` (default): **Capture mode.** A Symfony `ConsoleEvents::ERROR`
  subscriber and top-level callback wrappers (Revolt event-loop,
  TUI polling) convert exceptions into user-visible runtime events
  (e.g. `run.failed`, `protocol.error`, error TranscriptBlock). The
  TUI shows what happened.
- `0`: **Crash mode.** Boundaries rethrow the original exception
  after logging. The process exits with a loud crash. This is
  intended for test/CI/SDK harnesses that need to fail fast on real
  errors.

This is an environment variable, not a YAML setting key. It is read at
process startup by `RuntimeErrorCaptureConfig` and used by the centralized
`ConsoleErrorSubscriber` and top-level callback wrappers.

**Default:** `1` (enabled)

**Intended usage:**

```bash
# Normal user-facing agent run (capture errors, show in TUI)
bin/console agent

# Test configuration (crash on real errors)
HATFIELD_CAPTURE_ERRORS=0 bin/console agent --controller
HATFIELD_CAPTURE_ERRORS=0 bin/console agent
```

### `HATFIELD_LLM_RAW_STREAM_CAPTURE`

Enable raw LLM provider stream capture for debugging `DurableResultConverter`
behaviour.  When set to `1`, every raw SSE `data:` chunk and the converted
deltas produced by the converter are written to a JSONL file.  Only applies
to generic OpenAI-compatible providers (not Codex).

See `docs/llm-replay.md` for JSONL shape, privacy warnings, and how to
use artifacts for regression tests.

**Default:** (unset, disabled).

### `HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH`

Override the output path for the raw stream capture JSONL file.  The
directory is created automatically when capture is enabled.

**Default:** `<cwd>/var/tmp/llm-raw-stream-capture-<timestamp>-<id>.jsonl`

### `HATFIELD_CWD`

Override the runtime working directory. When set, the active CWD (where
`.hatfield/` state lives) is resolved from this value instead of
`getcwd()`. Absolute or relative paths are accepted; relative paths
resolve against the process CWD.

This is primarily used by `bin/console` early bootstrap when processing
the `--cwd` CLI option, and by subprocess spawning to ensure controller
and consumer processes resolve the same runtime directory.

**Default:** `getcwd()` (process working directory).

### `HATFIELD_CACHE_DIR`

Override the Symfony container cache directory. Absolute or relative paths
are accepted; relative paths resolve against the runtime CWD (from
`HATFIELD_CWD` or `getcwd()`).

**Default:** `.hatfield/cache/<env>` (relative to runtime CWD).

### `HATFIELD_LOG_DIR`

Override the application log directory. Absolute or relative paths are
accepted; relative paths resolve against the runtime CWD.

**Default:** `.hatfield/logs` (relative to runtime CWD).

### `HATFIELD_BINARY_PATH`

Override the agent executable path for subprocess spawning. When set,
`ConfigExecutableLocator` returns this path, taking priority over PHAR
self-reference and source-tree resolution. Used by test harnesses to
inject the built PHAR:

```bash
# Build PHAR, then run controller E2E tests against it
castor phar:build
HATFIELD_BINARY_PATH=var/tmp/phar/hatfield.phar vendor/bin/phpunit --filter ControllerSmokeTest
```

Relative paths resolve against the runtime CWD.

---

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

### `tools.execution` notes

Execution mode per tool is set at registration time by the tool
author/provider in `ToolDefinitionDTO`, not from settings overrides.
File-mutation tools (`write`, `edit`) are explicitly registered as
`Sequential` in their `HatfieldToolProviderInterface::definition()`.

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

### `tools.background_process.path`

Storage directory for background process log files. Each backgrounded
process gets its own log file under this directory, capturing both
stdout and stderr. The storage is shared across all tool consumers.

Relative paths resolve against the active project CWD.

**Default:** `.hatfield/tmp/bg` (resolves to `<CWD>/.hatfield/tmp/bg`)

### `tools.background_process.retention`

Maximum age in seconds for background process log files and DB records
before they are cleaned up. Cleanup is triggered on demand via the
manager's cleanup operations.

**Default:** `86400` (24 hours)

### `tools.background_process.stop_grace_seconds`

Grace period in seconds for SIGTERM before sending SIGKILL when
stopping a background process.

**Default:** `5`

### `tools.background_process.log_tail_chars`

Maximum number of characters returned by the `bg_status` tool's `log`
action. Output exceeding this limit is truncated with a continuation
marker.

**Default:** `5000`

### `tools.bash.default_timeout_seconds`

Default timeout in seconds for bash commands when no explicit timeout
is provided by the model.

**Default:** `300`

### `tools.bash.max_timeout_seconds`

Maximum timeout the model is allowed to request. Timeouts exceeding
this limit are rejected with a validation error, preventing the model
from tying up a tool worker with unbounded execution time.

**Default:** `3600` (1 hour)

### `tools.bash.background_prompt_threshold_seconds`

Elapsed seconds before the bash tool offers to move the command to
background. The default adapter declines (TOOLS-09); a future TUI
integration (TOOLS-09B) will wire this to a user question.

**Default:** `30`

### `tools.bash.poll_interval_micros`

Supervision loop poll interval in microseconds. Controls how frequently
the tool checks process status, cancellation, and timeout.

**Default:** `100000` (100 ms)

### `tools.bash.log_tail_chars`

Maximum characters returned as final or partial command output for
bash tool execution. Output exceeding this limit is passed through
`tools.output_cap` for truncation and may be saved to a file.

**Default:** `20000`

### `prompts`

A list of additional prompt-template file or directory paths. Prompt
templates are Markdown files invoked with `/name` in the TUI, where `name`
is the lowercase filename without `.md` (e.g. `review.md` → `/review`).

Auto-discovery always scans these non-recursive directories before
loading explicit paths:
- `~/.hatfield/prompts/*.md` (global)
- `<cwd>/.hatfield/prompts/*.md` (project)

Paths support the usual placeholders (`~`, `%kernel.project_dir%`,
relative paths). Because list settings replace rather than append, a
project `.hatfield/settings.yaml` `prompts:` list will replace the home
`prompts:` list entirely. The auto-discovery directories are always
scanned regardless of the `prompts:` list.

The CLI flag `--no-prompt-templates` disables auto-discovery and
settings `prompts:` paths. CLI `--prompt-template <path>` paths (repeatable)
still load even when `--no-prompt-templates` is set. There is no
`-np` shortcut.

**Default:** `[]` (empty — only auto-discovery directories are scanned).

**Example:**
```yaml
prompts:
  - .hatfield/team-prompts
  - ~/shared/prompts/review.md
```

See [Prompt Templates](prompt-templates.md) for full syntax, loading
order, collisions, placeholder reference, and current limitations.

### `extensions.enabled`

List of enabled extension class names. Each class must implement
`Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface`.

Extensions are loaded before the agent runtime starts, allowing them to
register tools and other integrations.

Extension packages installed via Composer under `.hatfield/extensions/vendor/`
are autoloaded automatically at startup when this list is non-empty.

**Default:** The built-in SafeGuard extension is enabled by default
(`Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension`).

**Example:**

```yaml
extensions:
  enabled:
    - Acme\HatfieldTaskWorkflow\TaskWorkflowExtension
```

See `.pi/plans/extension-api-phar-plan.md` for the full extension loading
and isolation model.

### `extensions.settings`

Generic settings map exposed to extensions through the Extension API
via `ExtensionApiInterface::getSettings(string $key): array`.

Each extension reads its own settings section by key (e.g. SafeGuard
reads `extensions.settings.safe_guard`).

**Default:** `{}` (empty — no settings).

### `extensions.settings.safe_guard`

SafeGuard policy configuration. All fields are optional — the defaults
provide sensible security with no user intervention.

Settings are read at extension load time through
`ExtensionApiInterface::getSettings('safe_guard')`.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `tool_names` | map | `{bash: bash, write: write, edit: edit, read: read}` | Maps internal tool labels to tool call names. Change if you register custom aliases. |
| `allow_command_patterns` | list | `[]` | Command substrings that bypass destructive/dangerous checks (case-insensitive substring match). |
| `allow_write_outside_cwd` | list | `[]` | Absolute paths where writes/edits outside the project CWD are always allowed. |
| `allow_destructive_in_paths` | list | `[]` | Reserved for serialization compatibility. Not currently wired to classification logic. |
| `protected_read_patterns` | list | `[]` | Additional filename/path patterns requiring confirmation to read. Added **on top** of built-in defaults — defaults cannot be removed. |
| `dangerous_command_patterns` | list | `[]` | Extra command substrings treated as dangerous, added to built-in regexes. |
| `auto_deny_in_noninteractive` | bool | `true` | Fail-closed when no approval channel (controller/TUI) is available. Interactive TUI contexts set `HATFIELD_APPROVAL_CHANNEL=controller` automatically so SafeGuard prompts even when this is `true`. Set to `false` only when an external approval broker (parent process, headless subagent host, policy engine) will relay questions and send `answer_human` back — otherwise the run hangs in `WaitingHuman` forever. |

**Example:**

```yaml
extensions:
    settings:
        safe_guard:
            tool_names:
                bash: bash
                write: write
                edit: edit
                read: read
            allow_command_patterns: []
            allow_write_outside_cwd:
                - /home/user/shared-tmp
            protected_read_patterns:
                - secrets.json
                - .env.production
            dangerous_command_patterns:
                - python -c "import os"
            auto_deny_in_noninteractive: true
```

### `ai.default_model`

The default model used for new agent sessions. Format is
`provider_id/model_name`.

**Optional.** When absent or empty, Hatfield selects the first
available configured model. When set, it must reference an
enabled, configured provider/model — boot-time validation
fails loudly with a clear error message otherwise.

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

### `ai.http`

HTTP client retry and timeout configuration for LLM provider requests.
Settings are applied globally (not per-provider) by wrapping the underlying
`HttpClient` with automatic retry/backoff logic.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `timeout` | int | `30` | Per-request timeout in seconds |
| `max_duration` | int | `120` | Max total request duration in seconds |
| `max_retries` | int | `2` | Max retry attempts (0 disables retries) |
| `base_delay_ms` | int | `1000` | Base retry backoff delay in milliseconds |
| `max_delay_ms` | int | `60000` | Max delay for any single retry in milliseconds |

Every value supports the `env:VARNAME` syntax to reference an environment
variable (e.g. `timeout: env:HATFIELD_HTTP_TIMEOUT`). The environment
variable must contain a numeric value; unset or empty variables are silently
treated as absent (falling back to the default).

**Example:**
```yaml
ai:
    http:
        timeout: 45
        max_retries: 0
```

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
| `thinking_format` | string | How reasoning is signalled. `zai` means `enable_thinking: boolean`; `deepseek` means `thinking.type` + `reasoning_effort`; `codex` means Codex Responses API `reasoning.effort`. Null = standard OpenAI `reasoning_effort`. |
| `requires_reasoning_content_on_assistant_messages` | bool | Whether assistant messages without thinking must include an empty `reasoning_content` field (DeepSeek). Default: `false`. |

Model-level `compatibility`:

| Key | Type | Description |
|-----|------|-------------|
| `zai_tool_stream` | bool | Whether the model supports streaming tool-call deltas (z.ai provider only). When `true`, `tool_stream: true` is sent in the request. |
| `requires_reasoning_content_on_assistant_messages` | bool | Per-model override for the provider-level flag. |

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

**Compat quirks:**

- `thinking_format: deepseek` — emits `thinking.type` alongside `reasoning_effort` when reasoning is enabled.
- `requires_reasoning_content_on_assistant_messages: true` — every assistant message includes `reasoning_content: ""` even when no thinking was produced. Without this, multi-turn conversations fail.

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

#### openai-codex

OpenAI Codex backend via OpenAI Responses API (`type: codex`). Uses the
OpenAICodex platform bridge which talks to the ChatGPT backend at
`https://chatgpt.com/backend-api/codex/responses`.

**Authentication:**

- Run `bin/console auth:codex` for OAuth PKCE login (browser-based
  authorization_code flow with local loopback callback).
- Credentials are stored in `~/.hatfield/auth.json` (0600) keyed
  by `openai-codex` (default) or `openai-codex-<profile>` when a
  profile is used. The stored record is automatically loaded by the
  Codex provider matching the `auth_key` config field.
- Do **not** set `api_key` or `account_id` in YAML for this provider
  type — Codex credentials are OAuth-only and live in the auth file.
  If credentials are missing, the provider emits a clear error with
  the matching auth command.

**Multiple accounts (profiles):**

You can authenticate with multiple OpenAI accounts by using the
`--auth-profile` option (the `--profile` flag is reserved by Symfony
Console as a global option and cannot be used for Codex auth profiles).
Each profile stores credentials under a separate key in
`~/.hatfield/auth.json`:

```bash
# Default account (key: openai-codex)
bin/console auth:codex

# Work account (key: openai-codex-work)
bin/console auth:codex --auth-profile work

# Personal account (key: openai-codex-personal)
bin/console auth:codex --auth-profile personal
```

To use a specific profile, configure the provider with `auth_key`:

```yaml
openai-codex-work:
    type: codex
    enabled: true
    auth_key: openai-codex-work
    # ... other settings

openai-codex-personal:
    type: codex
    enabled: true
    auth_key: openai-codex-personal
    # ... other settings
```

When `auth_key` is not set, the default `openai-codex` key is used.

**⚠ Important:** Profile switching is **manual**. Hatfield does NOT
automatically fail over to another account when one reaches a rate
limit or usage cap. Profiles exist purely for managing multiple
paid OpenAI accounts separately — not for usage-limit circumvention.

**Compat quirks:**

- Uses `reasoning.effort` (not `enable_thinking` or `reasoning_effort`)
  for thinking signalled via `thinking_format: codex`.
- `supports_developer_role: false` — maps `developer` to `system` role.
- `supports_reasoning_effort: false` — uses `reasoning.effort` instead
  of the standard `reasoning_effort` parameter.

**⚠ Experimental.** The Codex backend API uses the `chatgpt.com/backend-api`
endpoint, which is not an officially documented OpenAI API surface.
Use at your own risk. Only the OAuth PKCE authentication path is
supported (run `bin/console auth:codex`).

Seed models: `gpt-5.5`, `gpt-5.4`, `gpt-5.4-mini`.

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

## SafeGuard extension

SafeGuard is a built-in extension that blocks dangerous operations to
protect the user from accidental data loss, privilege escalation, or
sensitive information exposure. It is **enabled by default** via
`extensions.enabled` in `config/hatfield.defaults.yaml`.

SafeGuard registers a `tool_call` hook through the Extension API
(`Ineersa\Hatfield\ExtensionApi\ToolCallHookInterface`) that intercepts
`bash`, `write`, `edit`, and `read` tool calls before execution and
returns a structured denial result to the LLM when a policy violation
is detected.

### Classification rules

#### Hard-blocked operations (never negotiable)

These operations are always denied regardless of policy:

- `sudo`
- `su`
- Any command attempting privilege escalation that cannot be made safe
  by allowlisting

#### Dangerous commands

Blocked unless allowlisted via `allow_command_patterns`:

- `rm`, `rmdir`
- `git clean`, `git reset --hard`, `git checkout -- .`
- `mkfs`, `dd if=`
- `chmod` with a 3- or 4-digit octal mode granting 777
- `chown -R`, `chown -r`
- `mv ... /dev/null`
- Shell redirection to `/dev/null` combined with destructive commands

#### Dangerous git operations

Blocked unless allowlisted:

- `git push --force`, `git push -f`
- `git branch -d`, `git branch -D`
- `git tag -d`
- `git rebase`
- `git reflog expire`

#### Sensitive information exposure

Blocked unless allowlisted via `allow_command_patterns`:

- `env` (and piped variants like `env | grep`)
- `printenv` (and piped variants)

#### Protected reads

Blocked unless allowlisted via `allow_command_patterns`. Built-in
defaults (always active, cannot be removed):

- `.env.local`, `.env.dev.local`, `.env.prod.local`,
  `.env.staging.local`, `.env.test.local`
- `auth.json`, `credentials.json`, `.netrc`, `.npmrc`
- `.bashrc`, `.zshrc`, `.bash_profile`, `.zprofile`, `.profile`,
  `.bash_history`, `.zsh_history`
- `.ssh/id_*`, `.ssh/config`, `.ssh/known_hosts`
- `.aws/credentials`, `.aws/config`
- `.kube/config`
- `.gcp/`, `.config/gcloud/`, `.azure/`
- `*.pem`, `*.pkcs12`, `*.p12`, `*.pfx`
- Files containing `service-account` in their path

Additional patterns can be added via
`extensions.settings.safe_guard.protected_read_patterns` but the
built-in list cannot be removed.

#### Writes and edits outside CWD

Writes (`write` tool) and edits (`edit` tool) targeting paths outside
the current working directory are blocked by default. They can be
allowlisted via
`extensions.settings.safe_guard.allow_write_outside_cwd` by listing
the absolute paths where writes should be permitted.

### Behaviour

SafeGuard applies policy decisions and, for relaxable violations (e.g.
write outside CWD, destructive commands), prompts the user for approval
via the HITL question system when an approval channel is available.

In interactive TUI mode the user sees an approval overlay with three options:

- **Allow once** — approve the current operation for this session only
- **Always allow** — approve AND persist the pattern to `.hatfield/settings.yaml`
  so the operation is allowed in future sessions
- **Deny** — block the operation immediately

In headless/noninteractive contexts with `auto_deny_in_noninteractive: true`
(the default), relaxable violations are auto-blocked without prompting.

See [HITL and Approval Architecture](hitl-and-approvals.md) for the full
end-to-end flow.
