# Agent Core Library

Framework-agnostic agent loop core — domain model, pipeline, contracts, infrastructure adapters.

This package must stay usable without Symfony HttpKernel or FrameworkBundle. Do not add direct
`symfony/http-kernel` or `symfony/framework-bundle` dependencies back to this library.

## Namespace responsibilities

- `Ineersa\AgentCore\DependencyInjection` — DI extension loading and config validation for host applications.
- `Ineersa\AgentCore\Contract` — Stable interfaces for runner API, storage abstractions, tools, hooks, and extensions.
- `Ineersa\AgentCore\Domain` — Framework-agnostic core models: run state, commands, events, message envelopes, tool DTOs.
- `Ineersa\AgentCore\Application` — Runtime coordination and flow: orchestrator, reducer, command router, effect dispatchers.
- `Ineersa\AgentCore\Infrastructure` — Concrete adapters/integrations (Flysystem run logs, Mercure publisher, in-memory stores, Symfony AI bridge).
- `Ineersa\AgentCore\Schema` — Shared payload contract schemas, event-name mapping, and command/event normalizers.

## Library setup

- Composer package: `ineersa/agent-core` (`type: library`).
- Runtime dependencies use Symfony components directly (`console`, `dependency-injection`, `messenger`, `event-dispatcher`, etc.).
- The library currently keeps broad `^8.0` Symfony component constraints because it does not need Symfony 8.1-only APIs.
- It can be consumed by the Symfony 8.1 HTTP-less app in `apps/coding-agent` via the path repository.
- Framework-specific bootstrapping belongs in the consuming app or a dedicated bundle, not in this core package.

## Architecture maps

- [`src/Application/AGENTS.md`][app-arch] — Pipeline flow, command→handler topology
- [`src/Domain/AGENTS.md`][domain-arch] — Domain model aggregate
- [`src/Domain/Message/AGENTS.md`][domain-msg] — Message types
- [`src/Domain/Event/AGENTS.md`][domain-event] — Event types
- [`src/Infrastructure/Doctrine/AGENTS.md`][infra-doctrine] — Doctrine persistence schema

[app-arch]: src/Application/AGENTS.md
[domain-arch]: src/Domain/AGENTS.md
[domain-msg]: src/Domain/Message/AGENTS.md
[domain-event]: src/Domain/Event/AGENTS.md
[infra-doctrine]: src/Infrastructure/Doctrine/AGENTS.md

See `packages/agent-core/README.md` for the full local-dev setup and bundle integration docs.
