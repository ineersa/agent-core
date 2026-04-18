# AgentLoopExtension

**File:** `AgentLoopExtension.php`  
**Class:** `Ineersa\AgentCore\DependencyInjection\AgentLoopExtension`  
**Extends:** `Symfony\Component\DependencyInjection\Extension\Extension`  
**Implements:** `PrependExtensionInterface`

## Responsibility

Central Symfony DI extension for the AgentLoop bundle. Handles two-phase configuration:

### Phase 1 — `prepend()`

Prepends framework-level config from `config/messenger.php` and `config/doctrine.php` into the container. Each config file returns an array keyed by extension alias (e.g. `framework`, `doctrine`) with arrays of config dicts. Missing extensions are silently skipped.

### Phase 2 — `load()`

1. Processes the `agent_loop` configuration via the `Configuration` class.
2. Sets 14+ container parameters under the `agent_loop.*` namespace:
   - `agent_loop.config` — full resolved config
   - `agent_loop.runtime` — `messenger` or `inline`
   - `agent_loop.streaming` — `mercure` or `sse`
   - `agent_loop.llm.default_model` — default LLM model name
   - `agent_loop.storage.run_log.*` — Flysystem storage config
   - `agent_loop.storage.hot_prompt.backend` — `doctrine` etc.
   - `agent_loop.tools.*` — execution mode, parallelism, per-tool overrides
   - `agent_loop.commands.*` — max pending, custom kind prefix
   - `agent_loop.events.custom_type_prefix`
   - `agent_loop.checkpoints.*` — turn interval, delta size
   - `agent_loop.retention.*` — TTL and archive policies
3. Loads `config/services.php` via `PhpFileLoader`.
4. Creates a non-public alias `agent_loop.run_log.storage` pointing to the configured Flysystem storage.

## Key Dependencies

- `Configuration` — config tree definition
- `config/messenger.php`, `config/doctrine.php` — prepend source files
- `config/services.php` — service wiring

## Notes

- All config keys have sensible defaults — the bundle works with zero custom config.
- The `agent_loop.config` parameter holds the entire resolved tree for programmatic access.
