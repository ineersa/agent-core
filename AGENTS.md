# Agent Core monorepo

This is a modular monolith in one Composer app. Layer rules live in `depfile.yaml`; `castor deptrac` enforces them. Nested instructions include skills, `tests/AGENTS.md`, module `AGENTS.md` files, and prompts. They may add local procedure, but they must not weaken root safety, Castor/QA, architecture, or task-workflow rules. All applicable instructions apply together.

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

Key commands: `castor check` (includes `docs:validate` and `dead-code`), `castor test`, `castor test:tui`, `castor test:controller-replay`, `castor test:llm-real`, `castor deptrac`, `castor phpstan`, `castor dead-code`, `castor cs-check`, `castor cs-fix`, `castor docs:validate`.

Timeouts, check lock, llama-proxy cache guard, ParaTest budgets, preflight, and worker diagnostics: load the `testing` skill (`.agents/skills/testing/SKILL.md`).

## Testing prerequisites

Before writing, editing, debugging, reviewing, or running tests, every agent, fork, and scout MUST complete the steps below. The same rule applies before validating TUI, runtime, Messenger, or database work.

1. Load the `testing` skill (`.agents/skills/testing/SKILL.md`).
2. Read `tests/AGENTS.md` (helpers, isolation, E2E patterns, what not to test).

Do this before proposing a test strategy, adding tests, running Castor tests, or handing off validation. Forks must state in handoff that both were read and followed; omit that and the parent must not treat the handoff as CODE-REVIEW/DONE-ready for test-related work.

**Hard quality gate (authoritative detail: `tests/AGENTS.md`; procedure: testing skill):**

- **Bad tests are worse than missing tests.** A flaky, timing-window, soft-assert, or duplicate-layer test MUST be deleted or demoted. Do not keep a test that cannot be made deterministic.
- Individual cases MUST finish in **≤10s** under normal Castor load. A case that exceeds 10s alone or under relevant concurrent lanes is unacceptable. Rewrite, demote, or delete it. Rare exceptions MUST document the unique external or process contract in the test and why lower layers cannot prove it.
- MUST NOT use arbitrary `sleep`, `usleep`, delayed fixtures, elapsed-time races, retry-until-green, or timeout increases to fix flakes or hangs.
- Prove behavior at the **lowest correct layer**. Use a live LLM or tmux only for contracts unavailable below. One minimal terminal or live smoke is better than repeated journeys.
- Every spawned process or resource MUST have explicit ownership, isolation, and deterministic teardown. Leaks are product or test helper bugs.
- A solo green run is **insufficient** for known parallel or contention flakes. Validate under the relevant concurrent Castor lanes.

**Lane / proof constraints (detail in testing skill + `tests/AGENTS.md`):**

- Changes touching TUI runtime, `AgentSessionClient`, Messenger, `TranscriptProjector`, `RuntimeEventPoller`, or LLM-visible flow require `castor check`. Unit/container/mocked tests alone are not enough. If required tmux is unavailable, stay IN-PROGRESS with the blocker.
- TUI proof at the **lowest correct layer**: virtual/`castor test` → controller-replay → minimal `castor test:tui`. Do not default every feature to tmux. Custom smoke scripts, service-only DTO tests, picker/footer-only checks, or manual fork reports are not sole proof.
- Replay is a regression guard, not proof of live correctness. When a user-reported hang/freeze/stuck state survives replay, trust live reproduction (`#[Group('llm-real')]`, real controller subprocess) over more fixture-only proofs.
- Focused `castor test:llm-real` is only for provider or LLM-visible changes such as schemas, prompts, streaming, or model routing. Do not run it for every task. `castor test:controller` stays opt-in live controller E2E.
- Tests protect user-visible behavior, stable runtime or protocol contracts, safety boundaries, and known regressions. Prefer the smallest failing reproduction for bugs. Avoid tests that mirror implementation or exist only for coverage. Do not mix broad test refactors into implementation tasks.
- Leaked `messenger:consume`, `agent --controller`, PHPUnit, or Castor children are lifecycle bugs. Fix teardown at the source. `castor check` does not auto-kill workers. Diagnose leaks with `castor clean:cleanup:workers:list`. Use `castor clean:cleanup:workers` only as a last resort after recording the leak, and only for current-user orphans in this checkout.
- **Never signal, kill, restart, or otherwise touch root-owned workers**, or processes tagged with `HATFIELD_SESSION_ID`. If a root-owned process looks stale, report it and leave it alone.
- DB-touching tests boot the Symfony kernel and use the test container (`IsolatedKernelTestCase` / skill docs).

## JetBrains IDE tools

When JetBrains IDE integration is available in the active coding agent/runtime, prefer those tools for semantic navigation, references/call hierarchy, diagnostics, and semantic rename/move. Target the exact checkout using that runtime's project-scoping and open-project capability. Fall back to filesystem/`rg`/`find` for docs, generated artifacts, bulk ops, or when IDE tools are unavailable/insufficient. Exact tool names and capabilities come from the active coding agent's system instructions (Pi and Hatfield expose different names).

## Specification fidelity and minimality

- Implement only finalized task requirements. No new setting, API, storage field, command, or user-visible behavior unless explicitly requested.
- Smallest solution using existing code/platform. No speculative config, compatibility shims, abstractions, or future-proofing. Architecture-required indirection stays minimal.
- Ask the user when behavior or a public API is ambiguous. Reviewers **REQUEST CHANGES** for unsupported behavior, unsupported APIs, or unnecessary complexity.

## Development rules

- Do not delete comments that explain non-obvious logic, invariants, concurrency, lifecycle, or rationale unless that logic is removed; update them when code changes. Drop only noise that restates the obvious.
- **Never run `git reset --hard`, rewrite history, reset the working tree, or force-push without explicit user approval.** Inspect first with `git status`, `git log --oneline --decorate -5`, and `git diff`. Prefer `git revert`, `git restore <file>`, or `git merge --abort`. If you cannot name exactly what would be lost, do not proceed.
- Do not add backward-compatibility code during active development unless the user asks or the code belongs to a published API such as `ExtensionApi` with a documented deprecation window. Replace old behavior and update its tests and docs.
- Semantic type suffixes: `EventTypeEnum`, `UserEventService`, `RuntimeEventMapper`, `SettingsProvider`, `TranscriptProjector`, `Repository`, `Factory`, `DTO`, etc.
- **MUST use existing project and framework facilities instead of custom or lower-level replacements unless the user explicitly approves an exception.** This includes Symfony components, Doctrine ORM, Serializer, Validator, EventDispatcher, Messenger, Lock, and the project TUI abstractions.
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
- Project `.hatfield/settings.yaml` is both local configuration and an example. Keep it in sync with `docs/settings.md` when adding keys. Do not recreate `.hatfield.example/`.
- Theme selection/search paths use Hatfield settings, not container parameters.
- `session_id === run_id`. Metadata in `hatfield_session` DB table. Session dir: `.hatfield/sessions/<id>/` with canonical `events.jsonl`. Transcript projection rebuilds from events on resume. No `metadata.yaml`. Directory name is canonical; embedded IDs validated on read. Details: `docs/session-storage.md`.

## Architecture boundaries

**`depfile.yaml` and `castor deptrac` are authoritative.** The table below is only a summary. Deptrac allows specific dependencies that the table does not list. For example, TUI application and runtime code may use Runtime Contract and Protocol code, session code, and limited App code. App code may use Symfony CLI and HttpKernel infrastructure. Do not invent stricter blanket bans than Deptrac enforces.

| Area | Location | Owns | Core forbid |
|---|---|---|---|
| Core | `src/AgentCore/` | Domain, pipeline, contracts, stores | CodingAgent, Tui, Symfony TUI, HttpKernel/FrameworkBundle |
| App | `src/CodingAgent/` | CLI, runtime boundary, tools, extensions, wiring | Web-serving HTTP stack |
| Platform | `src/Platform/` | Provider bridges/results | Not separately layered; inspect depfile.yaml collectors |
| TUI | `src/Tui/` | Terminal UI, widgets, layout, theme, input | Cross-layer leaks outside approved Deptrac edges |
| Extension API | `.hatfield/extensions/extension-api/` | Public extension contracts | Hatfield internals (see below) |

- TUI↔runtime boundary for product code: `src/CodingAgent/Runtime/Contract`, `Runtime/Protocol`, and `AgentSessionClient` (plus Deptrac-approved projection/session edges where listed).
- This is an HTTP-less product. Do not add web-serving code.

Module-specific Runtime, TUI, and Extension API rules live in their nearest local `AGENTS.md`; the table above routes to them.

## Task workflow

External task board (not the code repo): `/home/ineersa/projects/agent-core-tasks` under `TODO/`, `IN-PROGRESS/`, `CODE-REVIEW/`, `DONE/`, `ARCHIVE/`, `CANCELLED/` (`.pi/settings.json` → `taskWorkflow.taskRoot`).

By default, `task_list` lists TODO, IN-PROGRESS, CODE-REVIEW, and DONE. Use `status=CANCELLED` to list cancelled tasks. Use `include_archive=true` or `status=ARCHIVE` to list archived tasks.

Task status/metadata moves do **not** commit to agent-core. Code branches, worktrees, PRs, merges do. Worktree creation updates parent IDEA module exclusions when present, creates minimal worktree-local `.idea` metadata from the integration primary module, and opens the exact worktree in JetBrains via MCP when available. DONE/CANCELLED cleanup closes that exact project before worktree removal.

### Implementation ownership

Main owns the initial exploration. It reads the task, referenced documents, and applicable `AGENTS.md` files. It then inspects likely entry points, callers, tests, and module boundaries. This pass must identify cohesive slices, important unknowns, and required validation. Stop before working out exact edits for a slice that may go to a fork.

Main owns implementation by default. Keep the work with main when it is one cohesive change, stays in one area or a small group of files, has clear entry points, and does not need broad discovery. Main should also keep work when focused validation can prove it without repeated live or process-test iteration, no useful independent slice exists, or explaining and reviewing a fork would cost about as much as implementing the change. File count is evidence, not a rule. If the choice is close, main owns it.

Use a fork only when all of these statements are true:

1. The slice has a clear boundary and acceptance criteria.
2. The fork can explore, implement, and validate it without making product decisions.
3. Main can review the diff and validation evidence without relearning the whole area.
4. Delegation will save meaningful context or repeated investigation.

Good reasons to use a fork include unfamiliar internals, mechanical changes across many files, an isolated module, an independently testable task item, substantial runtime or test iteration, and external research tied to implementation. Task size alone is not a reason. Main keeps tightly coupled work and asks the user about unresolved behavior instead of sending ambiguity to a fork.

Give each fork the goal, acceptance criteria, constraints, known entry points, ownership boundary, and validation contract. Do not give it a line-by-line design. One fork owns each delegated slice. Scouts, researchers, and reviewers remain read-only. Write-capable owners work sequentially in one worktree because they share the Git index, generated files, formatters, and test artifacts. Parallel writers require separate branches or worktrees and an explicit integration order. Every ownership change needs an explicit handoff. Independent review remains required.

### No dead code or unsupported fallback paths

Delete code, branches, prompts, adapters, tests, and compatibility paths that are dead, unreachable, superseded, or have no supported caller. Do not retain them "just in case." Do not add fallback behavior, compatibility shims, or preservation paths unless a finalized requirement or published compatibility contract requires them. Required error handling and documented local degradation remain valid.

### Workflow instruction authority

Root `AGENTS.md` owns global rules and routing. Nested `AGENTS.md` files own module rules. The active runtime's task-workflow skill owns phase procedures. Slash prompts only check arguments and dispatch to a phase. `WorkflowPrompt` only makes the workflow discoverable. Task tool definitions describe executable parameters, preconditions, side effects, and errors. They do not replace workflow procedure.

The phases are `task-explain`, `task-start`, `task-to-pr`, and `task-done`. Use `task-review-iterate` when review requires another implementation pass.

Before starting phase work or calling `move_task`, the main agent MUST read the active runtime's task-workflow `SKILL.md` and the exact phase procedure linked by its router. Reading only the slash prompt, router, previous phase, or a fork handoff is insufficient. Read the phase procedure again after every phase change and after compaction. The main agent checks phase preconditions and performs status transitions. Forks do not transition tasks.

Load the task-workflow skill when deciding implementation ownership or preparing a fork handoff outside a phase. After compaction, run `task_list`, reload the skill router, and read the current phase procedure.

## Docs map

- `docs/agents.md`: agent definitions, discovery, catalog, and settings
- `docs/skills.md`: skill discovery, `/skill:`, and on-demand-only skills with `disable-model-invocation`
- `docs/settings.md`: Hatfield settings, plus settings models and agents
- `docs/ai-catalog.md`: AI provider catalog, `providers:update`, and settings overlay
- `docs/compaction.md`: compaction, `/compact`, events, and hooks
- `docs/session-storage.md`: sessions, replay, locking, resume, and fork
- `docs/tui-architecture.md`: layout, widgets, slots, and themes
- `docs/tui-testing.md`: tmux testing, snapshots, and keybindings
- `docs/distribution.md`: release artifacts, installer, and publishing
- `docs/phar-packaging.md`: PHAR build, runtime, and tests
- `docs/static-packaging.md`: native PHP-micro binaries
- `docs/approvals.md` and `docs/human-input.md`: HITL, questions, and extension approvals
- `docs/datadog.md`: structured logs, privacy, and local Datadog
- `docs/llm-replay.md`: LLM fixture replay
- `src/AgentCore/Domain/AGENTS.md`: domain and event docs
- `src/AgentCore/Application/AGENTS.md`: command and handler topology
- `.agents/skills/testing/SKILL.md`: QA and test commands and runbooks
- Active runtime `task-workflow` skill: task phase procedures
- `tests/AGENTS.md`: shared test infrastructure and standards
