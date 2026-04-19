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

This repository uses a two-level AI index for token-efficient code navigation:
1. **Namespace indexes** (`src/**/ai-index.toon`) — per-namespace class listings with summaries.
2. **Per-file indexes** (`src/**/docs/<Class>.toon`) — method metadata with line numbers, symbol coordinates, and summaries.

All indexes are generated from source docblocks (no LLM calls).

For the full operational guide and summary-authoring prompts, load:
- `.agents/skills/ai-index/SKILL.md`

### Summary policy (mandatory)

**Every class must have a docblock summary as the first description line** (before any blank line or `@tag`).

- Class docblock: `/** <summary> */` (tags may follow)
- The first sentence of the description is extracted as the summary
- Missing class summary = `castor dev:index-methods --strict` failure (and `castor dev:check` failure)
- Method summaries are not indexed and not enforced — use IDE tools or targeted reads for method understanding.

When adding or editing classes, keep class summaries present and accurate.

### Reading policy (mandatory)

**Prefer targeted reads; avoid whole-file reads when possible.**

1. Read root `ai-index.toon` to choose namespace.
2. Read namespace `ai-index.toon` to choose class.
3. Read `docs/<Class>.toon` for method metadata (`start`, `end`, `limit`, `symbolLine`, `symbolColumn`).
4. Use `symbolLine` + `symbolColumn` first for IDE semantic navigation tools.
5. Read only needed slices via `read(path, offset, limit)`.

Example: if index has `start=47` and `limit=162`, read method window with `offset=47`, `limit=162`.

### Index maintenance

Indexes are generated via `castor dev:index-methods`.

Common commands:
- `castor dev:index-methods` — changed files
- `castor dev:index-methods --all --force` — full regeneration
- `castor dev:index-methods --strict --all` — read-only validation
- `castor dev:index-methods --migrate --all` — one-time migration from existing `.toon` summaries into source docblocks

After code changes, regenerate indexes for changed files.

**Never edit generated `src/**/ai-index.toon` or `src/**/docs/*.toon` manually.** Regenerate via Castor. The repository root `ai-index.toon` is curated and should be updated intentionally.

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
  - Public transport-facing API controllers/DTOs/serializers for run start/commands/read/replay and stream payloads.
- `Ineersa\AgentCore\Command`
  - Console operational commands (`agent-loop:health`, etc.).
