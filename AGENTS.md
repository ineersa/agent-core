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

## AI Documentation Index (ai-index.json)

The repository uses a hierarchical `ai-index.json` + `docs/` system for codebase navigation.
Every namespace and sub-namespace has its own `ai-index.json` and a `docs/` directory with per-file documentation.

### Reading policy (mandatory)

- **Always read `ai-index.json` in the project root first** to understand the full namespace layout before exploring any code.
- When working within a specific namespace, read its `ai-index.json` to find relevant files and their responsibilities.
- When exploring a file, read its corresponding `docs/*.md` for detailed documentation before reading the source code.
- This saves context by avoiding broad source file reads — use the index to locate exactly what you need.

### Index maintenance (mandatory delegation)

**The main agent MUST NOT update ai-index.json or docs directly.** All index maintenance is delegated to the **index-maintainer** subagent.

The index-maintainer subagent:
- Receives its skill (index-maintainer) pre-loaded in its system prompt
- Uses scout subagents internally for code exploration
- Only processes the specific paths you give it — never rescans the whole repo

#### Session tracking (mandatory)

During every session, **track all namespaces, sub-namespaces, and files you create, modify, or rename** in `src/`. At the end of the session (after all user-requested work is done), launch the index-maintainer subagent with the full list of touched paths.

Example task for index-maintainer:
```
Update ai-index.json and docs for these paths:
- src/Application/Handler/ (modified ToolExecutor.php, added NewHandler.php)
- src/Domain/Event/ (added CustomEvent.php)
- src/Infrastructure/Storage/ (renamed InMemoryRunStore.php)
```

#### When to run index-maintainer

- **Always once at session end** — after all code changes are complete, before claiming done.
- **On demand** — when the user explicitly asks to update docs/fix indexes, or runs `/index-maintainer`.
- **Never mid-task** — let the main agent finish code changes first, then delegate cleanup.

#### What index-maintainer expects

You must pass:
1. The list of namespaces/sub-namespaces/files that changed
2. What changed (added, modified, renamed, removed)
3. Enough context for it to launch scouts effectively

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
