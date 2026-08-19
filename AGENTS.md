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

## Castor-only QA

**All QA, test, lint, static analysis, and formatting go through Castor.** Do not run raw `vendor/bin/*` except to isolate a Castor failure. Reports land under `var/reports/` (per-run dirs via `HATFIELD_QA_REPORTS_DIR`).

Key commands: `castor check` (includes `docs:validate`), `castor test`, `castor test:tui`, `castor test:controller-replay`, `castor test:llm-real`, `castor deptrac`, `castor phpstan`, `castor cs-check`, `castor cs-fix`, `castor docs:validate`.

Timeouts, check lock, llama-proxy cache guard, ParaTest budgets, preflight, and worker diagnostics: load the `testing` skill (`.agents/skills/testing/SKILL.md`).

## Testing (mandatory before QA or test work)

Before writing, editing, debugging, reviewing, or running tests—and before validating TUI/runtime/Messenger/DB work—every agent, fork, and scout MUST:

1. Load the `testing` skill (`.agents/skills/testing/SKILL.md`).
2. Read `tests/AGENTS.md` (helpers, isolation, E2E patterns, what not to test).

Do this before proposing a test strategy, adding tests, running Castor tests, or handing off validation. Forks must state in handoff that both were read and followed; omit that and the parent must not treat the handoff as CODE-REVIEW/DONE-ready for test-related work.

**Constraints (detail in testing skill + `tests/AGENTS.md`):**

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
- Prefer Symfony-native extension points and typed objects over hand-rolled `instanceof`/string `match` routers, `normalize*()` arrays, or manual payload walkers.
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
- `session_id === run_id`. Metadata in `hatfield_session` DB table. Session dir: `.hatfield/sessions/<id>/` with canonical `events.jsonl` and `state.json`. Transcript projection rebuilds from events on resume. No `metadata.yaml`. Directory name is canonical; embedded IDs validated on read. Details: `docs/session-storage.md`.

## Architecture boundaries

**Authoritative rules: `depfile.yaml` + `castor deptrac`.** The table below is a high-level map only; selected Deptrac-approved seams exist (e.g. TUI application/runtime may use Runtime Contract/Protocol, session, and limited App surfaces; App uses Symfony CLI/HttpKernel infrastructure). Do not invent stricter blanket bans than Deptrac enforces.

| Area | Location | Owns | Core forbid |
|---|---|---|---|
| Core | `src/AgentCore/` | Domain, pipeline, contracts, stores | CodingAgent, Tui, Symfony TUI, HttpKernel/FrameworkBundle |
| App | `src/CodingAgent/` | CLI, runtime boundary, tools, extensions, wiring | Web-serving HTTP stack |
| Platform | `src/Platform/` | Provider bridges/results | Not separately layered; inspect depfile.yaml collectors |
| TUI | `src/Tui/` | Terminal UI, widgets, layout, theme, input | Cross-layer leaks outside approved Deptrac edges |
| Extension API | `.hatfield/extensions/extension-api/` | Public extension contracts | Hatfield internals (see below) |

- TUI↔runtime boundary for product code: `src/CodingAgent/Runtime/Contract`, `Runtime/Protocol`, and `AgentSessionClient` (plus Deptrac-approved projection/session edges where listed).
- HTTP-less product: no web serving surface.

### Extension API

- Package `ineersa/hatfield-extension-api` at `.hatfield/extensions/extension-api/`, namespace `Ineersa\Hatfield\ExtensionApi`. Canonical development in this monorepo; tag publish is a read-only mirror (`docs/distribution.md`).
- Public compatibility surface: must not depend on CodingAgent internals, AgentCore, in-repo TUI (`Ineersa\Tui\*`), Symfony DI/AI, settings, tool registry, runtime adapters, or PHAR packaging.
- Generic TUI contracts under `Ineersa\Hatfield\ExtensionApi\Tui\*` may depend on **Symfony TUI** public widgets/events/input only (`AppExtensionApi` → `SymfonyTui` in Deptrac)—approved public UI extension API.
- Feature UX lives in `.hatfield/extensions/<name>/`; do not add feature-shaped types to ExtensionApi or Runtime Contract. Loader/registry may depend on ExtensionApi; never the reverse. Keep the `Ineersa\Hatfield\ExtensionApi` namespace stable.

## Runtime model

- `AgentSessionClient` is the TUI/runtime boundary.
- `Runtime/Contract` and `Runtime/Protocol` define command/event DTOs.
- `Runtime/InProcess` calls AgentCore directly; `Runtime/Process` uses headless JSONL subprocess.
- `src/CodingAgent/CLI/AgentCommand.php` wires TUI via `Ineersa\Tui\Application\InteractiveMode`.
- Keep transient stream deltas separate from canonical replay. Canonical source: `.hatfield/sessions/<id>/events.jsonl` via `EventStoreInterface`.

## TUI architecture

Single-column layout: header → transcript/history → pending → working/status → extension widgets → editor → footer.

Key types: `TuiSlotRegistry`, `TuiExtensionContext` / `SlotBasedTuiExtensionContext`, `FooterDataProvider` / `FooterSegmentProvider` / `FooterBarWidget`. Chrome (header, status, pending, loaded resources, compact header, footer) renders via native Symfony TUI `AbstractWidget`s mounted directly by `ChatScreen`.

Themes: `ThemeColorEnum`, `ThemePalette`, `DefaultTheme`, `ThemeRegistry`, YAML under `config/themes/` (no separate `ThemeLoader` class). Extensions register status/working/footer state and terminal input through `TuiExtensionContext`; they must not mutate widgets directly. Hotkeys: `/hotkeys` catalog in `src/Tui/Command/Hotkey/` (display metadata, not input routing). Full design: `docs/tui-architecture.md`.

## Task workflow

External task board (not the code repo): `/home/ineersa/projects/agent-core-tasks` under `TODO/`, `IN-PROGRESS/`, `CODE-REVIEW/`, `DONE/`, `ARCHIVE/`, `CANCELLED/` (`.pi/settings.json` → `taskWorkflow.taskRoot`).

Default `task_list` output lists TODO, IN-PROGRESS, CODE-REVIEW, and DONE only; CANCELLED and ARCHIVE are omitted by default — list them with `status=CANCELLED` or `include_archive=true`/`status=ARCHIVE`.

Task status/metadata moves do **not** commit to agent-core. Code branches, worktrees, PRs, merges do. Worktree creation updates parent IDEA module exclusions when present, creates minimal worktree-local `.idea` metadata from the integration primary module, and opens the exact worktree in JetBrains via MCP when available. DONE/CANCELLED cleanup closes that exact project before worktree removal.

**Orchestrator model:** main agent plans and dispatches only—scouts explore, researchers look up, **forks implement all file changes**. Never edit files directly in the main agent; forks implement all file modifications (docs, config, tests, and code).

Phases: `task-explain` → `task-start` → `task-to-pr` → `task-done` (with `task-review-iterate` as needed). Load the `task-workflow` skill (`.pi/skills/task-workflow/SKILL.md`) for every phase procedure, fork instructions, and compaction recovery. After compaction, use `task_list` plus that skill.

## Docs map

- `docs/agents.md` — agent definitions, discovery, catalog, settings
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
- `.pi/skills/task-workflow/SKILL.md` — task phase procedures
- `tests/AGENTS.md` — shared test infrastructure and standards
