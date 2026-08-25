---
builtin: true
description: Settings precedence, file locations, core runtime keys, and environment overrides.
---

# Hatfield Settings

Hatfield configuration is sparse YAML: built-in defaults apply until you override keys
in home or project settings. Hatfield does **not** copy defaults into your home directory.

## Precedence

Later sources win via structured overlay (`AppConfigLoader`):

1. AI catalog (`~/.hatfield/ai-catalog.yaml`, bootstrapped from bundled `config/ai-catalog.yaml`)
2. Built-in defaults shipped with the install (`config/hatfield.defaults.yaml`)
3. `~/.hatfield/settings.yaml`
4. `<project>/.hatfield/settings.yaml`

Merge rules:

- **Associative maps** deep-merge (higher layer overrides matching keys; untouched lower keys survive).
- **Provider `models:` maps** replace wholesale (pin/trim); they do not deep-merge individual model ids.
- **Lists** (sequential arrays) are replaced entirely by the higher layer — no append/index-merge.
- **Scalars** (and `null`) in a higher layer replace the lower value.

Known providers in user/project settings are **sparse overlays** (for example
`{ enabled: true, api_key: env:ZAI_API_KEY }`). Full model lists and connection
defaults come from the AI catalog. See [ai-catalog.md](ai-catalog.md) for the
precedence diagram, `hatfield providers:update`, and version-skew warning.

Use only keys you intend to change. Full snapshots of defaults are unnecessary.

Related focused references:

- AI catalog → [ai-catalog.md](ai-catalog.md)
- Models and providers → [settings-models.md](settings-models.md)
- Agents, prompts, skills, extensions → [settings-agents.md](settings-agents.md)
- Sessions → [session-storage.md](session-storage.md)
- Compaction → [compaction.md](compaction.md)
- Approvals / SafeGuard → [approvals.md](approvals.md)
- Background processes → [background-processes.md](background-processes.md)

## Directory layout

| Path | Role |
|---|---|
| `~/.hatfield/ai-catalog.yaml` | User AI catalog (see [ai-catalog.md](ai-catalog.md)) |
| `~/.hatfield/settings.yaml` | User overrides |
| `<cwd>/.hatfield/settings.yaml` | Project overrides |
| `<cwd>/.hatfield/sessions/` | Session storage (ignored by git) |
| `<cwd>/.hatfield/tmp/` | Tool/output-cap/bg temp data |
| `~/.hatfield/agents/`, `<cwd>/.hatfield/agents/` | Agent definitions |
| `~/.hatfield/prompts/`, `<cwd>/.hatfield/prompts/` | Prompt templates |
| `~/.hatfield/mcp.json`, `<cwd>/.hatfield/mcp.json` | MCP servers |

Tracked monorepo `.hatfield/` may include example settings and extensions; runtime dirs stay local.

## Path resolution

Relative paths in settings resolve against the **active project CWD** (not the install/PHAR path).
`~` expands to the user home. Absolute paths are used as-is.

## Core keys

### TUI

| Key | Meaning | Default (built-in) |
|---|---|---|
| `tui.theme` | Theme id | `cyberpunk` |
| `tui.theme_paths` | Theme YAML search paths (list replace) | `%kernel.project_dir%/config/themes`, `.hatfield/themes`, `~/.hatfield/themes` |
| `tui.transcript.thinking.visible` | Show thinking blocks | `true` |
| `tui.transcript.thinking.style` | Thinking presentation | `dim_italic` |
| `tui.transcript.previews.expanded_by_default` | Expand previews | `false` |
| `tui.transcript.previews.tool_result_lines` | Tool result preview lines | `8` |
| `tui.transcript.previews.diff_lines` | Diff preview lines | `20` |

### Sessions and logging

| Key | Meaning |
|---|---|
| `sessions.path` | Base directory for sessions (relative → CWD) |
| `logging.path` | Log directory |
| `logging.level` | Monolog level |
| `logging.max_files` | Rotated file retention |

### Compaction (summary)

| Key | Meaning |
|---|---|
| `compaction.auto_enabled` | Automatic compaction |
| `compaction.compact_after_tokens` | Threshold |
| `compaction.keep_recent_tokens` | Retained tail |
| `compaction.model` / `thinking_level` | Compaction model selection |
| `compaction.provider_overrides` / `model_overrides` | Sparse overrides |

Full compaction behavior: [compaction.md](compaction.md).

### Context-budget reminders

| Key | Meaning |
|---|---|
| `context_budget_reminders.early_input_tokens` | Early wrap-up advisory threshold |
| `context_budget_reminders.urgent_remaining_tokens` | Urgent remaining-token reserve |

### Tool execution

There is **no** global tool timeout. Blocking tools own their deadlines
(bash, subagent, MCP, per-tool registration budgets). `ToolExecutor` does not rewrite
a successful late result into a timeout failure.

| Key | Meaning | Default |
|---|---|---|
| `tools.execution.default_mode` | `sequential` or `parallel` | `sequential` |
| `tools.execution.max_parallelism` | Concurrent tool workers | `4` |
| `runtime.llm_worker_count` | LLM worker processes | `4` |

### Output cap

| Key | Meaning | Default |
|---|---|---|
| `tools.output_cap.path` | Persisted oversized output dir | `.hatfield/tmp/output-cap` |
| `tools.output_cap.default_cap` | Non-doc char cap | `20000` |
| `tools.output_cap.doc_cap` | Doc-like char cap | `50000` |
| `tools.output_cap.retention` | Stale file seconds | `86400` |

### Images

| Key | Default |
|---|---|
| `tools.image.max_bytes` | `10485760` |
| `tools.image.max_width` | `4096` |
| `tools.image.max_height` | `2000` |
| `tools.image.max_dimension` | `2000` |
| `tools.image.encoded_max_bytes` | `4718592` |
| `tools.image.jpeg_quality` | `80` |
| `tools.image.jpeg_min_quality` | `40` |

### Bash / background

| Key | Default |
|---|---|
| `tools.bash.default_timeout_seconds` | `300` |
| `tools.bash.max_timeout_seconds` | `3600` |
| `tools.bash.background_prompt_threshold_seconds` | `15` |
| `tools.background_process.path` | `.hatfield/tmp/bg` |
| `tools.background_process.retention` | `86400` |
| `tools.background_process.stop_grace_seconds` | `5` |
| `tools.background_process.log_tail_chars` | `5000` |

See [background-processes.md](background-processes.md).

## Environment variables

| Variable | Role |
|---|---|
| `HATFIELD_CAPTURE_ERRORS` | `1` (default) surface caught errors in TUI; `0` crash for tests |
| `HATFIELD_CWD` | Override project CWD |
| `HATFIELD_CACHE_DIR` | Override cache directory |
| `HATFIELD_LOG_DIR` | Override log directory |
| `HATFIELD_BINARY_PATH` | Subprocess/runtime executable override (tests, custom installs) |
| `HATFIELD_LLM_RAW_STREAM_CAPTURE` | Enable raw stream capture |
| `HATFIELD_WRITE_PROMPT_CACHE_DIAGNOSTICS` | `0` (default) skips append-only structural prompt-cache diagnostics; launch future runs with `HATFIELD_WRITE_PROMPT_CACHE_DIAGNOSTICS=1` to enable privacy-safe `diagnostics/prompt-cache.jsonl` records for `session:cache:inspect` |
| `HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH` | Capture path |
| `HATFIELD_APPROVAL_CHANNEL` | Non-empty capability signal that an approval channel exists (see [approvals.md](approvals.md)) |

## `.hatfield/` policy

- Settings and extension enablement are project/user config.
- Sessions, tmp, cache, logs are runtime and should stay untracked.
- Extensions load from `.hatfield/extensions` Composer autoload when enabled.
- Built-in docs for the model are selected with `builtin: true` under approved roots; repository-only docs stay unmarked.

## Validation tip

After editing docs or packaging selection:

```bash
castor docs:validate
```
