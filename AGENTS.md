# Agent Core Monorepo

Modular monolith, single Composer app, boundaries enforced by Deptrac.

## Layout

```text
src/AgentCore/    Core loop, domain, contracts, storage/infrastructure
src/CodingAgent/  HTTP-less Symfony CLI app, runtime boundary, tools, wiring
src/Tui/          Terminal UI: screens, widgets, theme, renderer
tests/            Mirrors src modules
config/           YAML config; only bundles.php stays PHP
bin/console       CLI entry point
castor.php        Task runner
depfile.yaml      Deptrac rules
```

## ⚠️ MANDATORY: Use Castor for ALL QA and tooling commands

**ALL QA, test, lint, static-analysis, and formatting commands MUST go through Castor.** Never run raw `vendor/bin/*` commands — always use the Castor equivalent. The only exception is diagnosing a Castor failure by isolating the raw tool's output.

Castor wraps each tool with correct flag combinations, output summarization for LLM consumption, report persistence to `var/qa/`, and proper environment variables. Bypassing it silently drops all of this.

Key commands: `castor check` (full validation), `castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`, `castor cs-fix`.

**Load the `testing` skill** when: running any test, writing tests, debugging test failures, touching runtime/TUI/Messenger code, or needing the full command reference.

## ⚠️ MANDATORY: Read testing docs before touching tests or running QA

Before writing, editing, debugging, reviewing, or running tests — and before touching TUI/runtime/Messenger/DB code that requires validation — every agent, fork, and scout MUST:

1. **Load the `testing` skill** (`.agents/skills/testing/SKILL.md`).
2. **Read `tests/AGENTS.md`** for shared test infrastructure, helpers, isolation conventions, TUI E2E patterns, controller E2E patterns, and what NOT to test.

This must happen before proposing a test strategy, adding tests, running Castor tests, or handing off validation results. Forks must mention in their handoff that they read both files and followed the shared conventions. A fork handoff that omits this for test-related work is incomplete — the parent agent must not accept the handoff as valid for CODE-REVIEW or DONE without confirming the conventions were followed.

Before re-running `castor check`, `castor test:controller`, or `castor test:tui`, kill stale worker processes from prior runs that are owned by the current user (e.g., `messenger:consume`, `agent --controller`, orphaned PHPUnit/Castor children). Orphaned consumers steal queue messages and can make passing tests appear hung.

**Never kill, signal, restart, or otherwise touch root-owned worker processes.** In particular, do not touch the root-owned `php bin/console messenger:consume --all --exclude-receivers=failed` process (currently observed as PID 3361). If a root-owned process appears stale, report it to the user and leave it alone.

## E2E Testing Strategy

Replay-backed controller/TUI E2E tests use deterministic fixtures (no live LLM).  Live LLM smoke tests use `llama_cpp_test/test` (port 9052).  Test groups: `#[Group('llm-real')]`, `#[Group('tui-e2e-replay')]`, `#[Group('controller-replay')]`.  All E2E tests use `var/tmp/test-{uuid}` isolation, never real `.hatfield/sessions/`.

See `tests/AGENTS.md` for full test standards: shared helpers, isolation, test doubles, what not to test, and cleanup conventions.

**Load the `testing` skill** when: writing E2E tests, debugging controller/TUI test failures, or needing controller E2E internals, failure diagnostics, or the full testing matrix.

### TUI E2E snapshot artifacts

After `castor test:tui`, passing test snapshots are kept at `var/tmp/tui-e2e-*/` for inspection. Each isolated test directory contains:
- `.hatfield/tmp/tui/smoke/*.ansi` — ANSI terminal snapshots captured by `saveAnsiSnapshot()`
- `.hatfield/sessions/<id>/events.jsonl` — canonical event log for resumed sessions

After failures, diagnostics go to `var/tmp/tui-failures/` (ANSI snapshots + plain text dumps).

Run `castor cleanup` to remove all temp/test artifacts.

## Required runtime/TUI validation

For changes touching TUI runtime, `AgentSessionClient`, Messenger, `TranscriptProjector`, `RuntimeEventPoller`, or LLM-visible flow: you MUST run `castor check`. Unit/container/mocked tests are not enough. If tmux is unavailable, TUI tasks MUST stay IN-PROGRESS with the blocker — never mark CODE-REVIEW or DONE without validation.

Default `castor check` is fully deterministic (replay-backed controller and TUI E2E, no live LLM). Live LLM smoke is opt-in via `castor test:llm-real` and `castor test:controller`.

### Focused live LLM provider validation

`castor check` is deterministic and must NOT include `castor test:llm-real` by default. Run `castor test:llm-real` as opt-in focused validation when changes touch:
- Symfony AI provider/factory/platform integration
- LLM provider config, model catalog/resolution/routing/selection
- Tool schemas, tool-call conversion, or tool argument prompts
- LLM-visible system/developer prompts or prompt templates
- Live provider compatibility, streaming conversion, stop_reason/usage/tool-call deltas
- Controller live-provider path behavior where replay cannot prove provider compatibility

`castor test:controller` remains opt-in for live controller E2E when appropriate. Do NOT require live LLM validation for every normal task — only for provider/LLM-visible changes.

## Mandatory TUI feature E2E proof

**TUI implementation is NOT complete until there is an automated test using the real interactive TUI (`TmuxHarness`) with a snapshot or assertion proving the FEATURE works exactly as expected.** This is a hard gate — no exceptions. Default TUI E2E uses replay-backed fixtures for model interaction; live llama.cpp is not required for TUI feature proof.

- Tests must exercise the real TUI interaction flow, not just mocked services, DTO assembly, or service-only unit tests.
- Tests must use the project TUI E2E infrastructure (`TmuxHarness`, `#[Group('tui-e2e-replay')]`), replay fixtures where model output is needed, and isolated `var/tmp/test-{uuid}` directories.
- The following are NOT acceptable substitutes: custom PHP smoke scripts, mocked `AgentSessionClient` passing through a mock runtime, checking only picker visibility or footer text, or manual-run reports from forks.
- For the task workflow: do **not** move a TUI task to CODE-REVIEW or DONE unless a real TmuxHarness E2E proof exists and passes `castor test:tui`. The default `castor test:tui` is deterministic/replay-backed; it does not require llama.cpp. If tmux is unavailable, the task MUST stay IN-PROGRESS with that blocker recorded.

**Load the `testing` skill** when: writing, running, or debugging TUI E2E proof tests.

## Test value and scope

Tests must protect a **user-visible behavior**, a **stable runtime/protocol contract**, a **safety or security boundary**, or a **previously observed bug/regression**. Before adding or changing tests, state the test thesis: what contract or bug would fail without the production fix.

- **Bug fixes**: prefer the smallest failing repro first, then fix. Do not add extra tests unless they protect a distinct contract.
- **Avoid excessive implementation-mirroring tests**: enum case lists, trivial DTO constructor/getter/roundtrip tests, private-helper exact-behavior mirrors, mapper tests that just repeat the implementation, coverage-only tests, and broad snapshot churn unless justified.
- **Default test budget** for implementation tasks: one real TUI E2E proof when the change is TUI-visible, plus 1–3 focused contract or regression tests. More tests require explicit justification.
- Ask whether the tests would have caught the actual smoke or user-reported bug; if not, reconsider.
- **Do not broaden implementation tasks into test refactors.** Broad test cleanup or restructuring belongs in separate tasks, not mixed with production changes.

Existing mandatory Castor QA and TUI E2E proof requirements (above) remain in full force. The goal is to improve test signal density, not to eliminate testing.

## Development rules

- **Do not delete comments that explain non-obvious logic, invariants, concurrency, lifecycle, or rationale unless the described logic is removed.** When code changes, update those comments instead of deleting them. Remove only stale/noise comments that restate the obvious (e.g., "increment i" or "return the result"). Inline comments explaining why code is shaped a certain way — signal handling, crash resilience, transaction ordering, migration decisions, DB-to-filesystem interaction — are valuable and must be preserved or updated, never silently dropped.
- **⚠️ Never run `git reset --hard` or any destructive git operation (history rewrite, working-tree reset, forced push) without explicit user approval.** When you think cleanup or undo is needed, default to inspect-first: `git status`, `git log --oneline --decorate -5`, `git diff` to understand exactly what you would discard. Then ask the user. Prefer non-destructive alternatives: `git revert` for published changes, `git restore <file>` for specific file undo, `git merge --abort` only during an active failed merge. If you cannot name exactly which commits would be lost and why, you do not have enough information to proceed.
- **DB-touching tests must boot the Symfony kernel and use the test container.** Load the `testing` skill for full DB testing setup.
- **No backward-compatibility code during active development.** Do not add fallback readers, migration shims, dual-format support, legacy ID handling, or compatibility paths unless the user explicitly asks for them, or the code is a published compatibility surface (e.g. `ExtensionApi`) with a documented deprecation window. Replace old behavior and update tests/docs instead of adding compatibility layers. New features should replace, not accumulate, prior implementations.
- Use explicit semantic suffixes in type names: `EventTypeEnum`, `EventsRecordingsTrait`, `UserEventService`, `RuntimeEventMapper`, `SettingsProvider`, `TranscriptProjector`, `Repository`, `Factory`, `DTO`, etc. Avoid ambiguous bare names.
- Prefer Symfony-native extension points and typed objects over hand-rolled routers/mappers. Before adding `instanceof` dispatch chains, stringly `match` routers, `normalize*()` arrays, or manual payload walkers, check Symfony events/subscribers/listeners, Serializer/Normalizer, Messenger handlers, or Symfony AI DTOs.
- Never add production APIs or code paths solely for tests. Use production constructors/factories or test-local fixtures/builders.
- Never use `ReflectionClass::newInstanceWithoutConstructor()`, `Closure::bind()`, or constructor/property bypass tricks in production code.
- Test helpers belong in tests, not production.
- **Every caught exception/error must be propagated forward or explicitly documented as intentional local degradation with diagnostic logging. Empty catch blocks are forbidden.**
- Runtime logs must use structured event-style messages with correlation fields (`run_id`, `session_id`, `component`, `event_type`) and must not include raw prompts, tool output, environment values, API keys, or full session content by default; see `docs/datadog.md`.

## Symfony setup

- Symfony 8.1 HTTP-less app using `Symfony\Component\DependencyInjection\Kernel\AbstractKernel` + `KernelTrait`.
- `bin/console` uses `Symfony\Component\Console\Application` with the kernel container.
- `config/bundles.php` registers `FrameworkBundle`, `MonologBundle`, `ConsoleBundle`, and `DoctrineBundle`.
- FrameworkBundle is allowed **only for CLI/container infrastructure** (Messenger buses, Serializer, PropertyInfo, Lock, Monolog, Console commands, DI container services).
- Do **not** add HTTP controllers, routes, `public/index.php`, HTTP stack, Router, Session, or FrameworkBundle features that imply web serving.
- HTTP/routing/session/profiler features are explicitly disabled in `config/packages/framework.yaml`.
- Prefer Symfony 8.1 invokable commands (`__invoke()`) and YAML config.

## Hatfield settings and sessions

Settings precedence: built-in defaults < `~/.hatfield/settings.yaml` < project `.hatfield/settings.yaml`.

- `.hatfield/` is tracked; runtime dirs (`sessions/`, `tmp/`, `cache/`, `logs/`) are ignored.
- Project `.hatfield/settings.yaml` is both local config and example. Keep it and `docs/settings.md` in sync for new keys.
- Do not recreate `.hatfield.example/`.
- Theme selection/search paths use Hatfield settings, not container parameters.
- `session_id === run_id`. Session metadata lives in the `hatfield_session` DB table. Session directory: `.hatfield/sessions/<id>/` with canonical `events.jsonl` and `state.json`. Transcript projection is rebuilt from events.jsonl on resume. Metadata is queried from the DB; no `metadata.yaml` is written.
- Directory name is canonical; embedded IDs are validated on read. See `docs/session-storage.md`.

## Architecture boundaries

| Layer | Location | Owns | Must not depend on |
|---|---|---|---|
| Core | `src/AgentCore/` | Domain, pipeline, contracts, in-memory/session stores | `CodingAgent`, `Tui`, HTTP/FrameworkBundle |
| App | `src/CodingAgent/` | CLI app, runtime boundary, tools, extensions, wiring | HTTP/FrameworkBundle |
| TUI | `src/Tui/` | Terminal UI, widgets, layout, theme, input | `AgentCore`, Messenger, HTTP/FrameworkBundle |

TUI talks to runtime only through `src/CodingAgent/Runtime/Contract`, `Protocol`, and `AgentSessionClient`. Enforce with `castor deptrac`.

### Extension API boundary

- Public extension contracts live under `src/CodingAgent/ExtensionApi/` with namespace `Ineersa\Hatfield\ExtensionApi` until they are split into a standalone Composer package later.
- `ExtensionApi` code is a public compatibility surface. It must not depend on CodingAgent internals, AgentCore, TUI, Symfony DI, Symfony AI, settings, tool registry, runtime, or PHAR packaging code. Keep it to PHP-native types, enums, interfaces, DTOs, and narrow value objects.
- Extension loader/registry/runtime code may depend on `ExtensionApi`; `ExtensionApi` must never depend back on loader, registry, runtime, tools, settings, or packaging code.
- Preserve the `Ineersa\Hatfield\ExtensionApi` namespace so future extraction to `ineersa/hatfield-extension-api` is a package/CI change, not a downstream extension breaking change.
- Enforce this with `castor deptrac`; the `AppExtensionApi` layer has no allowed dependency on other project layers.

## Runtime model

- `AgentSessionClient` is the TUI/runtime boundary.
- `Runtime/Contract` and `Runtime/Protocol` define commands/events DTOs.
- `Runtime/InProcess` calls AgentCore services directly; `Runtime/Process` uses headless JSONL subprocess.
- `src/CodingAgent/CLI/AgentCommand.php` wires TUI mode through `Ineersa\Tui\Application\InteractiveMode`.
- Keep transient stream deltas separate from canonical replay events. Canonical replay source is `.hatfield/sessions/<id>/events.jsonl` through `EventStoreInterface`.

## TUI architecture

Single-column layout: header → transcript/history → pending messages → working/status → extension widgets → editor → footer.

Key APIs: `TuiWidget`, `TuiSlotRegistry`, `ChatLayout`, `TuiExtensionContext`, `SlotBasedTuiExtensionContext`, `FooterDataProvider`, `FooterSegmentProvider`, `FooterBarWidget`.

Hotkeys: `/hotkeys` renders a live catalog of keyboard shortcuts grouped by context (Global, Editor, Completion, History, Model). Registry is in `src/Tui/Command/Hotkey/` (display-only metadata — not input routing). Editor hotkeys reflect the active EditorWidget keybindings. There is no user-configurable YAML keybinding loader.

Extensions use `TuiExtensionContext` slot methods (`setHeader`, `setFooter`, `setEditorComponent`, `setWidget`, `setStatus`, `setWorkingMessage`, `setWorkingVisible`, `onTerminalInput`) and must not mutate widgets directly.

Themes use `ThemeColorEnum`, `ThemePalette`, `DefaultTheme`, `ThemeRegistry`, `ThemeLoader`, and YAML files in `config/themes/`. See `docs/tui-architecture.md`.

## Task workflow

This project uses an **external task board** (outside the code repo) under `TODO/`, `IN-PROGRESS/`, `CODE-REVIEW/`, and `DONE/`.
The task board lives at `/home/ineersa/projects/agent-core-tasks`, configured in `.pi/settings.json`→`taskWorkflow.taskRoot`.

Slash commands `/tasks`, `/tasks-todo`, `/tasks-in-progress`, `/tasks-code-review`, `/tasks-done` list tasks in the TUI.

**Task status/metadata moves do NOT commit to the agent-core code repository.**
Task board changes affect the external task board files only. This prevents code-branch pollution from task bookkeeping.

Code operations (branches, worktrees, PRs, merges) still run against this code repository.
Worktree creation updates the parent worktree IDEA module exclusions (when present) instead of copying `.idea/` into individual worktrees.

### Orchestrator model

The main agent is an **orchestrator**, not an implementor. **Never edit files directly** — use scouts for exploration, researchers for web lookup, and forks for ALL implementation. If you catch yourself about to open an editor — stop and launch a fork instead.

### Workflow phases

```
task-explain → task-start → task-to-pr → task-done
 (discuss)     (implement)  (review+PR)  (merge)
                  ↕
            task-review-iterate
              (address feedback)
```

**Load the `task-workflow` skill** when: starting any task phase (task-start, task-to-pr, task-review-iterate, task-done), or when preparing fork instructions and reviewer workflows.

### Compaction resilience

After compaction, the `task-workflow` skill documents next steps. Use `task_list` to inspect active tasks, and load this skill for exact phase procedures.

## Docs map

- `docs/agents.md` — agent definitions, discovery, catalog, built-in agents
- `docs/settings.md` — Hatfield settings
- `docs/compaction.md` — context compaction guide, `/compact` command, settings, events, hooks, validation
- `docs/session-storage.md` — sessions, replay, locking, resume/fork design
- `docs/tui-architecture.md` — layout, widgets, slots, themes
- `docs/tui-testing.md` — tmux testing, snapshots, keybindings
- `docs/phar-packaging.md` — PHAR build, runtime, test, and troubleshooting
- `docs/hitl-and-approvals.md` — HITL end-to-end flow, TUI question system, extension approvals, SafeGuard modes
- `docs/datadog.md` — local Datadog setup, structured log fields, event names, spans, and observability privacy rules
- `src/AgentCore/Domain/AGENTS.md` — domain/event docs
- `src/AgentCore/Application/AGENTS.md` — command/handler topology
- `.pi/plans/` — implementation plans
