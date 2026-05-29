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

## Castor only

Use Castor for QA. Do not run raw `vendor/bin/*` as the primary command; raw tools are only for isolating failures already reported by Castor.

```bash
castor install
castor check             # deptrac → phpunit → phpstan → cs-check
castor test              # excludes tui-e2e and llm-real
castor test --filter=X
castor test:tui          # tmux TUI e2e snapshots
castor test:tui-update
castor test:llm-real     # real llama.cpp smoke (ControllerSmokeTest, LlamaCppSmokeTest)
castor test:controller   # controller E2E smoke test (spawns --controller)
castor deptrac
castor phpstan [path]
castor phpstan:baseline
castor cs-fix [path]
castor cs-check
castor cache:clear       # clears QA caches and Symfony cache
castor log:tail [--level=ERROR] [--lines=50] [--search=term]
castor log:search <query> [--level=WARNING] [--from="-1 hour"] [--to=now]
castor log:files         # list log files with size and modification date
castor log:clear [--older-than=7d]
castor run:agent         # launch TUI in tmux
castor run:agent-test    # deterministic tmux session for snapshots
castor worktree:remove <slug> --force [--delete-branch]
castor idea:run-configs
```

`castor check` intentionally skips tmux/real-LLM tests. Run `castor test:tui`, `castor test:llm-real`, or `castor run:agent-test` explicitly for user-visible runtime/TUI work.

## E2E Testing Strategy

### Test LLM

All E2E tests use `llama_cpp_test/lfm2.5` (port 9052). This is a fast local
model for deterministic smoke testing. Never use production LLM providers
in E2E tests.

### Test groups

- `#[Group('llm-real')]` — all tests that hit a real LLM endpoint
- `#[Group('tui-e2e')]` — TUI tmux snapshot tests

### Isolation

All E2E tests must use `var/tmp/test-{uuid}` isolation. They must NOT
read or write to the real `.hatfield/sessions/` directory. On failure,
tests dump session artifacts to stderr.

### What each castor command tests

| Command | What it tests | Requires |
|---|---|---|
| `castor test` | Unit/integration tests | Nothing (pure PHP) |
| `castor test:llm-real` | Real LLM smoke: `ControllerSmokeTest`, `LlamaCppSmokeTest` | llama.cpp on port 9052 |
| `castor test:controller` | Controller E2E: spawns `--controller`, JSONL protocol | llama.cpp on port 9052 |
| `castor test:tui` | Tmux TUI E2E snapshot tests | tmux, llama.cpp on port 9052 |
| `castor run:agent-test` | Interactive tmux session for manual inspection | tmux, llama.cpp on port 9052 |
| `castor run:agent` | Launch agent in tmux | tmux, LLM provider |

### Controller E2E testing

`ControllerSmokeTest` (`tests/CodingAgent/Runtime/Controller/E2E/`):

1. Creates isolated `var/tmp/test-{uuid}` with `.hatfield/settings.yaml`
2. Spawns `bin/console agent --controller` via proc_open
3. Waits for `runtime.ready` event on stdout
4. Sends `start_run` JSONL command on stdin with a deterministic prompt
5. Reads JSONL events from stdout, collecting them until terminal state
6. Asserts event sequence:
   - `runtime.ready` received
   - `command.ack` received for start_run
   - `run.started` received
   - `assistant.text_started` or `assistant.message_completed` received
   - `run.completed` or `run.failed` received (within 60s timeout)
7. Verifies session artifacts (`state.json`, `events.jsonl`, `transcript.jsonl`)
8. On failure, dumps all collected events, session artifacts, and messenger DB

This exercises the full async runtime pipeline:
- Controller event loop (Revolt `EventLoop::onReadable`/`repeat`/`onSignal`)
- Messenger consumer processes (run_control, llm, tool)
- LLM consumer stdout streaming of transient deltas
- Event drain and publish transport polling

### Failure diagnostics

On E2E test failure, the test dumps:
- All collected JSONL events (with types and count)
- Session artifacts: `state.json`, `events.jsonl`, `transcript.jsonl`, `metadata.yaml`
- Messenger DB (`messenger.sqlite`) with pending message counts per queue
- Controller stderr output

## Required runtime/TUI validation

For changes touching TUI runtime behavior, `AgentSessionClient`, model routing,
Messenger wiring, `TranscriptProjector`, `RuntimeEventPoller`, transcript
rendering, or LLM-visible execution flow, unit/container/mocked tests are not
enough.

You MUST run and report a product-level Castor workflow:

- `castor run:agent-test` to drive the agent in tmux and capture snapshots, or
- `castor test:tui` for tmux snapshot/e2e assertions, or
- `castor test:llm-real` for real-model paths (LlamaCppSmokeTest), or
- `castor test:controller` for controller process E2E.

Validation must exercise the real user flow: start agent, type prompt, submit,
wait for visible assistant response or visible error block, and capture TUI
snapshot plus session artifacts (`events.jsonl`, `transcript.jsonl`) on failure.
Do not claim runtime/TUI work is done based only on DTO tests, mocked pollers,
container compilation, or isolated service tests.

## Development rules

- Use explicit semantic suffixes in type names: `EventTypeEnum`, `EventsRecordingsTrait`, `UserEventService`, `RuntimeEventMapper`, `SettingsProvider`, `TranscriptProjector`, `Repository`, `Factory`, `DTO`, etc. Avoid ambiguous bare names.
- Prefer Symfony-native extension points and typed objects over hand-rolled routers/mappers. Before adding `instanceof` dispatch chains, stringly `match` routers, `normalize*()` arrays, or manual payload walkers, check Symfony events/subscribers/listeners, Serializer/Normalizer, Messenger handlers, or Symfony AI DTOs.
- Never add production APIs or code paths solely for tests. Use production constructors/factories or test-local fixtures/builders.
- Never use `ReflectionClass::newInstanceWithoutConstructor()`, `Closure::bind()`, or constructor/property bypass tricks in production code.
- Test helpers belong in tests, not production.
- **Every caught exception or error MUST be propagated forward to the user/runtime/TUI, or explicitly documented as intentional local degradation.**
  - Critical infrastructure errors (data persistence, process launch, transport): emit a user-visible runtime event (e.g. `run.failed`, `protocol.error`) or error TranscriptBlock via the centralized `RuntimeErrorCaptureService`. Must not silently log/ignore/return null.
  - Intentional local degradation (image EXIF, log line parsing, optional observer hooks): document with a comment in the catch block explaining why it is safe and use `RuntimeErrorCaptureService::handleDegradation()` for consistent diagnostics. Never leave an empty catch body.
  - Centralized error capture respects `HATFIELD_CAPTURE_ERRORS=1` (default, user-facing mode — captures and converts to user-visible failure) and `HATFIELD_CAPTURE_ERRORS=0` (test mode — rethrows/crashes loudly so test harnesses see real exceptions).
  - **Static enforcement**: A future Rector/PHPStan custom rule should flag empty catch blocks, log-only catch blocks without `throw`/structured error return/centralized handler call. Until then, review catch blocks during code review for this rule. See `src/CodingAgent/Runtime/ErrorCapture/` for the centralized handler.

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
- `session_id === run_id`. Session directory: `.hatfield/sessions/<id>/` with `metadata.yaml`, canonical `events.jsonl`, `state.json`, plus projections `transcript.jsonl` and `runtime-events.jsonl`.
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

## Docs map

- `docs/settings.md` — Hatfield settings
- `docs/session-storage.md` — sessions, replay, locking, resume/fork design
- `docs/tui-architecture.md` — layout, widgets, slots, themes
- `docs/tui-testing.md` — tmux testing, snapshots, keybindings
- `src/AgentCore/Domain/AGENTS.md` — domain/event docs
- `src/AgentCore/Application/AGENTS.md` — command/handler topology
- `.pi/plans/` — implementation plans
