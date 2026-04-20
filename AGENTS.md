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
2. **Per-file indexes** (`src/**/docs/<Class>.toon`) — method coordinates, signatures, and call relationships (callers/callees).

All indexes are auto-generated from source code (no LLM calls). `castor dev:check` regenerates them on every run.

### Summary policy

**Every class must have a docblock summary as the first description line.** Missing class summary = `dev:check` failure. Method summaries are not indexed.

### Key rules

- **Never edit** generated `src/**/ai-index.toon` or `src/**/docs/*.toon` manually.
- Root `ai-index.toon` is curated and should be updated intentionally.
- When code changes alter command/event/message relationships, update the affected nested `AGENTS.md` files.

For detailed index schema and navigation guidance, load `.agents/skills/ai-index/SKILL.md`.

## Architecture notes

Architecture maps live in nested `AGENTS.md` files near the code (e.g. `src/Application/AGENTS.md`, `src/Domain/Event/AGENTS.md`). These document relationship views (command→handler, event→projector, message→dispatched-by). Update them when relationships change.

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

## Standard workflows

### Workflow: Edit existing code
When making modifications to existing classes or methods:
1. Locate the target class in the `ai-index.toon` files.
2. Read `docs/<Class>.toon` to find the exact method coordinates (`start`, `limit`).
3. Check the `callers`/`callees` lists within the `.toon` file to understand the impact of your change.
4. Read only the target method using `read(path, offset=start, limit=limit)`.
5. Apply the edit.
6. Verify your work by running `LLM_MODE=true castor dev:check` before claiming completion.

### Workflow: Adding a New Feature
When asked to implement a new feature (e.g., a new Command, Event, or Service):
1. **Navigate**: Read the root `ai-index.toon` and relevant namespace `ai-index.toon` to determine where the new class belongs.
2. **Implement**: Create the new class(es). Ensure every new class has a valid PHP docblock summary as its first line.
3. **Verify & Update Index**: Run `LLM_MODE=true castor dev:check` (this automatically updates the `.toon` indexes and verifies quality gates).
4. **Update Architecture Docs**: Before claiming the task is done, if the feature introduces new commands, events, or handlers, update the relevant nested `AGENTS.md` maps (e.g., `src/Application/AGENTS.md`).

### Workflow: Refactoring & Renaming
When asked to rename a concept, class, or method, or move files:
1. **Locate**: Use `ai-index.toon` to find the target's precise coordinates (`symbolLine` and `symbolColumn`).
2. **Refactor via IDE**: **Never** use `sed` or text replacement. Always use the JetBrains IDE MCP refactor tools (e.g., `jetbrains_index_ide_refactor_rename` or `jetbrains_index_ide_move_file`).
3. **Verify & Update Index**: Run `LLM_MODE=true castor dev:check` to ensure no usages were missed and to regenerate the stale `.toon` indexes.
4. **Update Architecture Docs**: Check if the renamed concept is mentioned in any nested `AGENTS.md` maps and update them manually before claiming completion.

### Workflow: Bug Investigation
When investigating a bug or error trace:
1. **Analyze Error**: Identify the failing class/method from logs or `jetbrains_index_ide_diagnostics`.
2. **Targeted Read**: Use `docs/<Class>.toon` to find the exact offset and limit for the failing method. Use `read(path, offset, limit)` to inspect only that method.
3. **Trace Execution**: Check the `callers` list in the `.toon` file from step 2 to quickly see what invokes the failing method.
4. **Fix & Test**: Apply the fix. Run the specific test via `castor dev:test --filter <TestName>` before running the full `castor dev:check` suite.
