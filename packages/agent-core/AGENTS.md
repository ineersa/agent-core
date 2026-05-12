# Agent Core Library

Framework-agnostic agent loop core — domain model, pipeline, contracts, infrastructure adapters.

## Namespace responsibilities

- `Ineersa\AgentCore\DependencyInjection` — Bundle extension loading, config validation, framework config prepend.
- `Ineersa\AgentCore\Contract` — Stable interfaces for runner API, storage abstractions, tools, hooks, and extensions.
- `Ineersa\AgentCore\Domain` — Framework-agnostic core models: run state, commands, events, message envelopes, tool DTOs.
- `Ineersa\AgentCore\Application` — Runtime coordination and flow: orchestrator, reducer, command router, effect dispatchers.
- `Ineersa\AgentCore\Infrastructure` — Concrete adapters/integrations (Flysystem run logs, Mercure publisher, in-memory stores, Symfony AI bridge).
- `Ineersa\AgentCore\Schema` — Shared payload contract schemas, event-name mapping, and command/event normalizers.

## Architecture maps

- `src/Application/AGENTS.md` — Pipeline flow, command→handler topology
- `src/Domain/AGENTS.md` — Domain model aggregate
- `src/Domain/Message/AGENTS.md` — Message types
- `src/Domain/Event/AGENTS.md` — Event types

See `packages/agent-core/README.md` for the full local-dev setup and bundle integration docs.
