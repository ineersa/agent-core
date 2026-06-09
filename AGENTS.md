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

## E2E Testing Strategy

All E2E tests use `llama_cpp_test/test` (port 9052). Test groups: `#[Group('llm-real')]`, `#[Group('tui-e2e')]`. Tests use `var/tmp/test-{uuid}` isolation, never real `.hatfield/sessions/`.

**Load the `testing` skill** when: writing E2E tests, debugging controller/TUI test failures, or needing controller E2E internals, failure diagnostics, or the full testing matrix.

## Required runtime/TUI validation

For changes touching TUI runtime, `AgentSessionClient`, Messenger, `TranscriptProjector`, `RuntimeEventPoller`, or LLM-visible flow: you MUST run `castor check`. Unit/container/mocked tests are not enough. If prerequisites (tmux, llama.cpp:9052) are unavailable, the task MUST stay IN-PROGRESS with the blocker — never mark CODE-REVIEW or DONE without validation.

**Load the `testing` skill** when: touching runtime/TUI code, running validation, or unsure what `castor check` covers.

## Mandatory TUI feature E2E proof

**TUI implementation is NOT complete until there is an automated test using the real test LLM and `TmuxHarness` with a snapshot or assertion proving the FEATURE works exactly as expected through real interactive TUI behavior.** This is a hard gate — no exceptions.

- Tests must exercise the real TUI interaction flow, not just mocked services, DTO assembly, or service-only unit tests.
- Tests must use the project TUI E2E infrastructure (`TmuxHarness`, `#[Group('tui-e2e')]`), the real test LLM endpoint (`llama_cpp_test/test` on port 9052), and isolated `var/tmp/test-{uuid}` directories.
- The following are NOT acceptable substitutes: custom PHP smoke scripts, mocked `AgentSessionClient` passing through a mock runtime, checking only picker visibility or footer text, or manual-run reports from forks.
- For the task workflow: do **not** move a TUI task to CODE-REVIEW or DONE unless a real TmuxHarness E2E proof exists and passes `castor test:tui` as well as `LLM_MODE=true castor check`. If prerequisites (tmux, llama.cpp on port 9052) are unavailable, or no such test has been written, the task MUST stay IN-PROGRESS with that blocker recorded.

**Load the `testing` skill** when: writing, running, or debugging TUI E2E proof tests.

## Development rules

- **Do not delete comments that explain non-obvious logic, invariants, concurrency, lifecycle, or rationale unless the described logic is removed.** When code changes, update those comments instead of deleting them. Remove only stale/noise comments that restate the obvious (e.g., "increment i" or "return the result"). Inline comments explaining why code is shaped a certain way — signal handling, crash resilience, transaction ordering, migration decisions, DB-to-filesystem interaction — are valuable and must be preserved or updated, never silently dropped.
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

Extensions use `TuiExtensionContext` slot methods (`setHeader`, `setFooter`, `setEditorComponent`, `setWidget`, `setStatus`, `setWorkingMessage`, `setWorkingVisible`, `onTerminalInput`) and must not mutate widgets directly.

Themes use `ThemeColorEnum`, `ThemePalette`, `DefaultTheme`, `ThemeRegistry`, `ThemeLoader`, and YAML files in `config/themes/`. See `docs/tui-architecture.md`.

## Task workflow

This project uses a repo-local task board under `tasks/TODO`, `tasks/IN-PROGRESS`, `tasks/CODE-REVIEW`, and `tasks/DONE`. Slash commands `/tasks`, `/tasks-todo`, `/tasks-in-progress`, `/tasks-code-review`, `/tasks-done` list tasks in the TUI.

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

- `docs/settings.md` — Hatfield settings
- `docs/session-storage.md` — sessions, replay, locking, resume/fork design
- `docs/tui-architecture.md` — layout, widgets, slots, themes
- `docs/tui-testing.md` — tmux testing, snapshots, keybindings
- `docs/phar-packaging.md` — PHAR build, runtime, test, and troubleshooting
- `docs/hitl-and-approvals.md` — HITL end-to-end flow, TUI question system, extension approvals, SafeGuard modes
- `docs/datadog.md` — local Datadog setup, structured log fields, event names, spans, and observability privacy rules
- `src/AgentCore/Domain/AGENTS.md` — domain/event docs
- `src/AgentCore/Application/AGENTS.md` — command/handler topology
- `.pi/plans/` — implementation plans
