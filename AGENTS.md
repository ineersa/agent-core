# Agent Core Monorepo

This is the monorepo for `ineersa/agent-core` and its ecosystem.

## Workspaces

### `packages/agent-core/`
Core library: agent loop, domain model, contracts, infrastructure adapters, Symfony AI bridge.  
See `packages/agent-core/AGENTS.md` for detailed architecture maps.

### `packages/tui-bundle/`
Symfony TUI component integration bundle (skeleton).

### `apps/coding-agent/`
Symfony CLI application that consumes both packages. CLI commands, tool implementations,
extension loader, session persistence, TUI widget wiring.

## Development

```bash
castor install    # Install all dependencies
castor check      # QA across all workspaces
castor lib:check  # QA for agent-core library only
```

## Architecture boundaries

| Layer | Location | Owns |
|-------|----------|------|
| Core library | `packages/agent-core/` | Domain model, pipeline, contracts, in-memory stores |
| TUI rendering | `packages/tui-bundle/` | Terminal engine, keybindings, themes, widgets |
| Application | `apps/coding-agent/` | CLI commands, tools, extensions, session, TUI wiring |

See `packages/agent-core/src/Application/AGENTS.md` for command/event/message topology.
