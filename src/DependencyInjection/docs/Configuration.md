# Configuration

**File:** `Configuration.php`  
**Class:** `Ineersa\AgentCore\DependencyInjection\Configuration`  
**Implements:** `ConfigurationInterface`

## Responsibility

Defines the `agent_loop` configuration tree with validation and defaults. This is the single source of truth for all configurable parameters of the agent loop bundle.

## Configuration Sections

| Section | Key | Default | Description |
|---------|-----|---------|-------------|
| **runtime** | `runtime` | `messenger` | Execution backend: `messenger` (async) or `inline` (sync) |
| **streaming** | `streaming` | `mercure` | Streaming transport: `mercure` or `sse` |
| **llm** | `llm.default_model` | `gpt-4o-mini` | Default LLM model identifier |
| **storage.run_log** | `storage.run_log.flysystem_storage` | `agent_loop.run_logs` | Flysystem storage service name |
| **storage.run_log** | `storage.run_log.base_path` | `%kernel.project_dir%/var/agent-runs` | Local fallback path |
| **storage.hot_prompt** | `storage.hot_prompt.backend` | `doctrine` | Hot prompt state backend |
| **tools.defaults** | `tools.defaults.mode` | `sequential` | Default tool execution: `sequential`, `parallel`, `interrupt` |
| **tools.defaults** | `tools.defaults.timeout_seconds` | `90` | Default tool timeout |
| **tools** | `tools.max_parallelism` | `4` | Max concurrent tool calls |
| **tools.overrides** | `tools.overrides` | `web_search→parallel/120s`, `ask_user→interrupt/90s` | Per-tool overrides keyed by tool name |
| **commands** | `commands.max_pending_per_run` | `100` | Max pending commands per run |
| **commands** | `commands.custom_kind_prefix` | `ext:` | Prefix for extension-defined command kinds (must start with `ext:`) |
| **events** | `events.custom_type_prefix` | `ext_` | Prefix for extension-defined event types (must start with `ext_`) |
| **checkpoints** | `checkpoints.every_turns` | `5` | Checkpoint frequency |
| **checkpoints** | `checkpoints.max_delta_kb` | `256` | Max delta size before forced checkpoint |
| **retention** | `retention.hot_prompt_ttl_hours` | `24` | Hot prompt TTL |
| **retention** | `retention.archive_after_days` | `7` | Archive threshold |

## Validation Rules

- `commands.custom_kind_prefix` **must** start with `ext:`.
- `events.custom_type_prefix` **must** start with `ext_`.
- `tools.defaults.timeout_seconds` minimum is `1`.
- `tools.max_parallelism` minimum is `1`.

## Notes

- All sections use `addDefaultsIfNotSet()` so the bundle works out of the box.
- The tree root name is `agent_loop` — used as the YAML/XML config key.
