# agent-core

This repository uses **Castor** as the task runner and operational interface.

## Mandatory operations policy

- **Castor usage is mandatory and preferred** for project operations.
- Always use `castor ...` commands when a task exists.
- Do not default to raw binaries (`vendor/bin/...`) or ad-hoc shell commands if there is an equivalent Castor task.
- Discover commands via:
  - `castor list`
  - `castor list dev`

## Definition of done (required before claiming completion)

Before stating a task is complete, always run all quality gates:

1. `castor dev:cs-fix`
2. `castor dev:phpstan`
3. `castor dev:test`

Or use the aggregate command:

- `castor dev:check`

If any of these fail, the work is **not complete**.

### LLM mode

- For LLM-driven Castor execution, set `LLM_MODE=true`.
- In LLM mode, Castor tasks must stay token-efficient (no progress bars / fluff output).
- Reports are written to `var/reports/` (`phpstan.json`, `phpstan.log`, `php-cs-fixer.json`, `php-cs-fixer.log`, `phpunit.junit.xml`, `phpunit.log`).

## PHP implementation guidance

- Target modern PHP (`>=8.5`) and prefer modern language features where they improve clarity and maintainability.
- Use strict typing and expressive constructs.
- Prefer modern patterns/features, including:
  - pipe operator
  - property hooks
- Apply these features pragmatically (readability first, no novelty for novelty’s sake).

## Repository structure (current)

```text
src/
  AgentLoopBundle.php
  DependencyInjection/      # Bundle extension + configuration tree
  Contract/                 # Public contracts (runner, stores, hooks, extension seams)
    Hook/
    Extension/
    Tool/
  Domain/                   # Pure domain messages/events/commands/run/tool value objects
    Run/
    Message/
    Tool/
    Event/
    Command/
  Application/              # Use-case orchestration, routing, reducer, dispatching
    Orchestrator/
    Handler/
    Reducer/
  Infrastructure/           # Integrations/adapters (storage, mercure, messenger, Symfony AI)
    Doctrine/
    Messenger/
    SymfonyAi/
    Storage/
    Mercure/
  Api/                      # HTTP/API layer placeholders for later stages
    Http/
    Dto/
  Command/                  # Symfony console commands
config/
  services.php              # DI service wiring
  messenger.php             # Messenger buses/transports/routing defaults
  doctrine.php              # Doctrine integration placeholder
implementation/             # Staged implementation plan + progress tracker
```

## Namespace responsibilities

- `Ineersa\AgentCore\DependencyInjection`
  - Bundle extension loading, config validation, framework config prepend.
- `Ineersa\AgentCore\Contract`
  - Stable interfaces for runner API, storage abstractions, tools, hooks, and extensions.
- `Ineersa\AgentCore\Domain`
  - Framework-agnostic core models: run state, commands, events, message envelopes, tool DTOs.
- `Ineersa\AgentCore\Application`
  - Runtime coordination and flow: orchestrator, reducer, command router, effect dispatchers.
- `Ineersa\AgentCore\Infrastructure`
  - Concrete adapters/integrations (Flysystem run logs, Mercure publisher, in-memory stores, Symfony AI bridge).
- `Ineersa\AgentCore\Api`
  - Public transport-facing API contracts/controllers/DTOs (planned in later stages).
- `Ineersa\AgentCore\Command`
  - Console operational commands (`agent-loop:health`, etc.).
