# Agent Core Monorepo

Modular monolith, single Composer app. Layer rules live in `depfile.yaml` and are enforced by `castor deptrac`. Nested instructions (skills, `tests/AGENTS.md`, module `AGENTS.md`, prompts) may specialize procedure; they must not weaken root safety, Castor/QA, architecture, or task-workflow constraints—contexts concatenate.

## Layout

```text
src/AgentCore/    Core loop, domain, contracts, storage/infrastructure
src/CodingAgent/  HTTP-less Symfony CLI app, runtime boundary, tools, wiring
src/Tui/          Terminal UI: screens, widgets, theme, renderer
src/Platform/     Provider bridge/result adapters (e.g. Codex)
tests/            Mirrors src modules
config/           YAML config; only bundles.php stays PHP
bin/console       CLI entry point
castor.php        Task runner
depfile.yaml      Deptrac rules (authoritative boundaries)
```

## Local instructions

Before touching an area, read its nearest nested `AGENTS.md`. Nested instructions add local invariants and procedure but cannot weaken this root file.

| Area | Local instructions |
|---|---|
| Domain and application | `src/AgentCore/Domain/AGENTS.md`, `src/AgentCore/Application/AGENTS.md` |
| Runtime | `src/CodingAgent/Runtime/AGENTS.md` (then any deeper `AGENTS.md`) |
| TUI | `src/Tui/AGENTS.md` |
| Extension API | `.hatfield/extensions/extension-api/AGENTS.md` |
| Tests | `tests/AGENTS.md` |

## Castor-only QA

**All QA, test, lint, static analysis, and formatting go through Castor.** Do not run raw `vendor/bin/*` except to isolate a Castor failure. Reports land under `var/reports/` (per-run dirs via `HATFIELD_QA_REPORTS_DIR`).

Key commands: `castor check` (includes `docs:validate`), `castor test`, `castor test:tui`, `castor test:controller-replay`, `castor test:llm-real`, `castor deptrac`, `castor phpstan`, `castor cs-check`, `castor cs-fix`, `castor docs:validate`.

Timeouts, check lock, llama-proxy cache guard, ParaTest budgets, preflight, and worker diagnostics: load the `testing` skill (`.agents/skills/testing/SKILL.md`).

## Testing (mandatory before QA or test work)

Before writing, editing, debugging, reviewing, or running tests—and before validating TUI/runtime/Messenger/DB work—every agent, fork, and scout MUST:

1. Load the `testing` skill (`.agents/skills/testing/SKILL.md`).
2. Read `tests/AGENTS.md` (helpers, isolation, E2E patterns, what not to test).

Do this before proposing a test strategy, adding tests, running Castor tests, or handing off validation. Forks must state in handoff that both were read and followed; omit that and the parent must not treat the handoff as CODE-REVIEW/DONE-ready for test-related work.

**Hard quality gate (authoritative detail: `tests/AGENTS.md`; procedure: testing skill):**

- **Bad tests are worse than missing tests.** A flaky, timing-window, soft-assert, or duplicate-layer test MUST be deleted or demoted. Do not keep a test that cannot be made deterministic.
- Individual cases MUST finish in **≤10s** under normal Castor load. A case that exceeds 10s solo **or under relevant concurrent lanes** is unacceptable: rewrite, demote, or delete. Rare exceptions MUST document the unique external/process contract in the test and why lower layers cannot prove it.
- MUST NOT use arbitrary `sleep` / `usleep` / delayed fixtures, elapsed-time races, retry-until-green, or timeout increases as the fix for flakes or hangs.
- Prove at the **lowest correct layer**. Live LLM / tmux ONLY for contracts unavailable below. One minimal terminal/live smoke beats repeated journeys.
- Every spawned process/resource MUST have explicit ownership, isolation, and deterministic teardown. Leaks are product/harness bugs.
- Solo green is **insufficient** for known parallel/contention flakes—validate under the relevant concurrent Castor lanes.

**Lane / proof constraints (detail in testing skill + `tests/AGENTS.md`):**

- Changes touching TUI runtime, `AgentSessionClient`, Messenger, `TranscriptProjector`, `RuntimeEventPoller`, or LLM-visible flow require `castor check`. Unit/container/mocked tests alone are not enough. If required tmux is unavailable, stay IN-PROGRESS with the blocker.
- TUI proof at the **lowest correct layer**: virtual/`castor test` → controller-replay → minimal `castor test:tui`. Do not default every feature to tmux. Custom smoke scripts, service-only DTO tests, picker/footer-only checks, or manual fork reports are not sole proof.
- Replay is a regression guard, not proof of live correctness. When a user-reported hang/freeze/stuck state survives replay, trust live reproduction (`#[Group('llm-real')]`, real controller subprocess) over more fixture-only proofs.
- Focused `castor test:llm-real` is for provider/LLM-visible changes only (schemas, prompts, streaming, model routing)—not every task. `castor test:controller` stays opt-in live controller E2E.
- Tests protect user-visible behavior, stable runtime/protocol contracts, safety boundaries, or known regressions. Prefer smallest failing repro for bugs; avoid implementation-mirroring/coverage-only tests; do not mix broad test refactors into implementation tasks.
- Leaked `messenger:consume`, `agent --controller`, PHPUnit, or Castor children are lifecycle bugs—fix teardown at source. `castor check` does not auto-kill workers. Diagnostics: `castor clean:cleanup:workers:list`; last resort after recording the leak: `castor clean:cleanup:workers` (current-user orphans in this checkout only).
- **Never signal, kill, restart, or otherwise touch root-owned workers**, or processes tagged with `HATFIELD_SESSION_ID`. If a root-owned process looks stale, report it and leave it alone.
- DB-touching tests boot the Symfony kernel and use the test container (`IsolatedKernelTestCase` / skill docs).

## JetBrains IDE tools

When JetBrains IDE integration is available in the active coding agent/runtime, prefer those tools for semantic navigation, references/call hierarchy, diagnostics, and semantic rename/move. Target the exact checkout using that runtime's project-scoping and open-project capability. Fall back to filesystem/`rg`/`find` for docs, generated artifacts, bulk ops, or when IDE tools are unavailable/insufficient. Exact tool names and capabilities come from the active coding agent's system instructions (Pi and Hatfield expose different names).

## Specification fidelity and minimality

- Implement only finalized task requirements. No new setting, API, storage field, command, or user-visible behavior unless explicitly requested.
- Smallest solution using existing code/platform. No speculative config, compatibility shims, abstractions, or future-proofing. Architecture-required indirection stays minimal.
- Ambiguity on behavior or public surface is a question, not implementation authority. Reviewers **REQUEST CHANGES** for unmapped surface or unnecessary complexity.

## Development rules

- Do not delete comments that explain non-obvious logic, invariants, concurrency, lifecycle, or rationale unless that logic is removed; update them when code changes. Drop only noise that restates the obvious.
- **Never run `git reset --hard` or other destructive git (history rewrite, working-tree reset, forced push) without explicit user approval.** Inspect first (`git status`, `git log --oneline --decorate -5`, `git diff`). Prefer `git revert`, `git restore <file>`, `git merge --abort`. If you cannot name exactly what would be lost, do not proceed.
- No backward-compatibility code during active development unless the user asks or the surface is a published API (e.g. `ExtensionApi`) with a documented deprecation window. Replace old behavior; update tests/docs.
- Semantic type suffixes: `EventTypeEnum`, `UserEventService`, `RuntimeEventMapper`, `SettingsProvider`, `TranscriptProjector`, `Repository`, `Factory`, `DTO`, etc.
- **MUST use existing project/framework facilities—including Symfony components, Doctrine ORM, Serializer, Validator, EventDispatcher, Messenger, Lock, and TUI abstractions—rather than custom or lower-level replacements unless the user explicitly approves an exception.**
- No production APIs or paths solely for tests. No `ReflectionClass::newInstanceWithoutConstructor()`, `Closure::bind()`, or constructor bypass in production. Test helpers stay in tests.
- Every caught exception must be rethrown/propagated or explicitly logged as intentional local degradation. Empty catch blocks are forbidden.
- Runtime logs: structured event-style messages with correlation fields (`run_id`, `session_id`, `component`, `event_type`); do not log raw prompts, tool output, env values, API keys, or full session content by default. See `docs/datadog.md`.

## Symfony setup

- Symfony 8.1 HTTP-less CLI app. Kernel: `Ineersa\CodingAgent\Kernel` extends `Symfony\Component\HttpKernel\Kernel` (`src/CodingAgent/Kernel.php`).
- `bin/console` uses `Symfony\Component\Console\Application` with the kernel container.
- `config/bundles.php`: FrameworkBundle, MonologBundle, ConsoleBundle, DoctrineBundle (+ migrations; DAMA in `test` only).
- FrameworkBundle is for CLI/container infrastructure only (Messenger, Serializer, PropertyInfo, Lock, Monolog, Console, DI). **No** HTTP controllers, routes, `public/index.php`, Router/Session web stack, or web-serving Framework features. HTTP/routing/session/profiler are disabled in `config/packages/framework.yaml`.
- Prefer invokable commands (`__invoke()`) and YAML config.

## Hatfield settings and sessions

Settings precedence: built-in defaults < `~/.hatfield/settings.yaml` < project `.hatfield/settings.yaml`.

- `.hatfield/` is tracked; runtime dirs (`sessions/`, `tmp/`, `cache/`, `logs/`) are ignored.
- Project `.hatfield/settings.yaml` is local config and example—keep in sync with `docs/settings.md` for new keys. Do not recreate `.hatfield.example/`.
- Theme selection/search paths use Hatfield settings, not container parameters.
- `session_id === run_id`. Metadata in `hatfield_session` DB table. Session dir: `.hatfield/sessions/<id>/` with canonical `events.jsonl`. Transcript projection rebuilds from events on resume. No `metadata.yaml`. Directory name is canonical; embedded IDs validated on read. Details: `docs/session-storage.md`.

## Architecture boundaries

**Authoritative rules: `depfile.yaml` + `castor deptrac`.** The table below is a high-level map only; selected Deptrac-approved seams exist (e.g. TUI application/runtime may use Runtime Contract/Protocol, session, and limited App surfaces; App uses Symfony CLI/HttpKernel infrastructure). Do not invent stricter blanket bans than Deptrac enforces.

| Area | Location | Owns | Core forbid |
|---|---|---|---|
| Core | `src/AgentCore/` | Domain, pipeline, contracts, stores | CodingAgent, Tui, Symfony TUI, HttpKernel/FrameworkBundle |
| App | `src/CodingAgent/` | CLI, runtime boundary, tools, extensions, wiring | Web-serving HTTP stack |
| Platform | `src/Platform/` | Provider bridges/results | Not separately layered; inspect depfile.yaml collectors |
| TUI | `src/Tui/` | Terminal UI, widgets, layout, theme, input | Cross-layer leaks outside approved Deptrac edges |
| Extension API | `.hatfield/extensions/extension-api/` | Public extension contracts | Hatfield internals (see below) |

Dependency direction:

- AgentCore must not depend on CodingAgent, TUI, ExtensionApi, or concrete extensions.
- CodingAgent may depend on AgentCore and public ExtensionApi contracts; it must not depend on TUI or concrete extension implementations.
- TUI may depend directly on CodingAgent. Direct TUI dependencies on AgentCore are allowed only where Deptrac explicitly lists them and are usually a design smell; prefer the owning CodingAgent service.
- Concrete extensions depend on ExtensionApi contracts (plus explicitly approved public vendor APIs such as Symfony TUI), not AgentCore, CodingAgent internals, or in-repo `Ineersa\Tui` classes.
- ExtensionApi must remain independent of AgentCore, CodingAgent internals, in-repo TUI, and concrete extensions. Host product code may implement or consume ExtensionApi contracts but must not depend on concrete extension implementations. App built-ins under `src/CodingAgent/Extension/Builtin` are host code, not external extensions.
- Runtime Contract/Protocol types are for real session/runtime protocol and projection seams, not wrappers invented merely to prevent TUI from using an owning CodingAgent service.
- HTTP-less product: no web serving surface.

Module-specific Runtime, TUI, and Extension API rules live in their nearest local `AGENTS.md`; the table above routes to them.

## Task workflow

External task board (not the code repo): `/home/ineersa/projects/agent-core-tasks` under `TODO/`, `IN-PROGRESS/`, `CODE-REVIEW/`, `DONE/`, `ARCHIVE/`, `CANCELLED/` (`.pi/settings.json` → `taskWorkflow.taskRoot`).

Default `task_list` output lists TODO, IN-PROGRESS, CODE-REVIEW, and DONE only; CANCELLED and ARCHIVE are omitted by default — list them with `status=CANCELLED` or `include_archive=true`/`status=ARCHIVE`.

Task status/metadata moves do **not** commit to agent-core. Code branches, worktrees, PRs, merges do. Worktree creation updates parent IDEA module exclusions when present, creates minimal worktree-local `.idea` metadata from the integration primary module, and opens the exact worktree in JetBrains via MCP when available. DONE/CANCELLED cleanup closes that exact project before worktree removal.

**Implementation ownership:** delegation is context management, not a prohibition on main-agent edits. After a shallow routing pass, choose main-owned versus fork-owned implementation **before** deep implementation exploration. If main already has the detailed implementation model, it retains the cohesive slice. Otherwise, the fork owns detailed exploration, implementation, and focused validation within its bounded scope; give it the goal, acceptance criteria, constraints, known entry points, ownership boundaries, and validation contract—not a parent-completed implementation design. Each bounded fork-owned implementation slice has exactly one fork owner. Scouts, researchers, and reviewers are read-only subagents. Delegate a fork only when transfer reduces total context/rereading (mechanical migrations, isolated modules, independently testable work, investigation+implementation, or context-heavy internals). Write-capable owners execute sequentially in one worktree, even for disjoint files: Git index/status, generated files, formatters, and test artifacts are shared. Parallel write-capable forks require separate branches/worktrees and an explicit integration order. Each new fork requires an explicit ownership handoff. Main may implement and validate; independent review remains required.

**No dead code or uncited fallback paths:** delete code, branches, prompts, adapters, tests, and compatibility paths that are dead, unreachable, superseded, or have no supported caller in the same change. Do not retain them “just in case.” Do not add fallback behavior, compatibility shims, or preservation paths unless an explicit finalized requirement or published compatibility contract requires them. This does not prohibit required error handling or intentional local degradation that is explicitly documented by the requirement.

### Workflow instruction authority

Root `AGENTS.md` owns global invariants and routing; nested `AGENTS.md` files own module-local invariants. The active runtime's task-workflow skill owns phase procedures; thin slash prompts only guard arguments and dispatch. `WorkflowPrompt` provides discoverability only. Task tool definitions own executable parameters, preconditions, side effects, and errors—not orchestration checklists.

Phases: `task-explain` → `task-start` → `task-to-pr` → `task-done` (with `task-review-iterate` as needed). Load the active runtime's `task-workflow` skill for every phase procedure, implementation ownership or delegated-fork handoffs, and compaction recovery. After compaction, use `task_list` plus that skill.

## Docs map

- `docs/agents.md` — agent definitions, discovery, catalog, settings
- `docs/skills.md` — skill discovery, `/skill:`, on-demand-only (`disable-model-invocation`)
- `docs/settings.md` — Hatfield settings (see also settings-models, settings-agents)
- `docs/ai-catalog.md` — AI provider catalog, providers:update, settings overlay
- `docs/compaction.md` — compaction, `/compact`, events, hooks
- `docs/session-storage.md` — sessions, replay, locking, resume/fork
- `docs/tui-architecture.md` — layout, widgets, slots, themes
- `docs/tui-testing.md` — tmux testing, snapshots, keybindings
- `docs/distribution.md` — release artifacts, installer, publish
- `docs/phar-packaging.md` — PHAR build/runtime/test
- `docs/static-packaging.md` — native PHP-micro binaries
- `docs/approvals.md / docs/human-input.md` — HITL, questions, extension approvals
- `docs/datadog.md` — structured logs, privacy, local Datadog
- `docs/llm-replay.md` — LLM fixture replay
- `src/AgentCore/Domain/AGENTS.md` — domain/event docs
- `src/AgentCore/Application/AGENTS.md` — command/handler topology
- `.agents/skills/testing/SKILL.md` — QA/test command matrix and runbooks
- Active runtime `task-workflow` skill — task phase procedures
- `tests/AGENTS.md` — shared test infrastructure and standards
