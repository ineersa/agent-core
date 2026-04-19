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

## AI Documentation Index

The repository uses a two-level documentation system:
1. **Namespace indexes** (`ai-index.toon`) — per-namespace files listing classes with summaries
2. **Per-file indexes** (`docs/<File>.toon`) — per-class method listings with line numbers and one-line summaries

All indexes use TOON format (Token-Oriented Object Notation) for ~28% token reduction vs JSON.

### Reading policy (mandatory)

**Never read an entire source file when you can target a specific range.** The index system provides line numbers for exactly this purpose.

1. **Root first** — read `ai-index.toon` in the project root to understand the namespace layout.
2. **Namespace index** — read the namespace's `ai-index.toon` to find classes and their summaries.
3. **Per-file index** — read `docs/<File>.toon` to get method metadata:
   - `commentStart` (PHPDoc start)
   - `signatureLine` (method signature line)
   - `symbolLine` + `symbolColumn` (1-based IDE symbol location)
   - `end` (method end line)
4. **Targeted read** — use `read(path, offset=<commentStart>, limit=<end-commentStart+1>)` (or `signatureLine`) to read only the method(s) you need.

Example: the per-file index shows `execute,commentStart=47,signatureLine=47,end=208`. To read just that method:
```
read("src/Application/Handler/ToolExecutor.php", offset=47, limit=162)  # 208-47+1
```

When using IDE symbol navigation tools, pass `symbolLine` + `symbolColumn` as-is (both are 1-based).

This saves massive context compared to reading entire files.

### Index maintenance

Indexes are maintained via `castor dev:index-methods`. This command:
- Extracts method signatures via PHP-Parser AST
- Sends them to a local LLM for structured summaries
- Writes per-file `.toon` indexes and regenerates namespace `ai-index.toon` files

Usage:
- `castor dev:index-methods` — process git-changed files
- `castor dev:index-methods --all --force` — full regeneration
- `castor dev:index-methods --dry-run` — preview without writing

#### When to run

- **At session end** — after code changes are complete, run `castor dev:index-methods` to update indexes for changed files.
- **On demand** — when the user asks to regenerate indexes.

**The main agent MUST NOT edit `ai-index.toon` or `docs/*.toon` files manually.** Always use `castor dev:index-methods`.

## Architecture notes (README-driven, mandatory)

The `ai-index.toon` system is for **navigation indexes** (class/method lookup), not for architectural relationship maps.

- Keep architecture maps in nested `README.md` files near the code (for example `src/Application/README.md`, `src/Domain/Event/README.md`, `src/Domain/Message/README.md`).
- These nested READMEs should document relationship views such as:
  - command -> handler
  - event -> projector/listener
  - message -> dispatched-by / handled-by
- When code changes alter those relationships, the agent must update the affected nested `README.md` files in the same change.

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
