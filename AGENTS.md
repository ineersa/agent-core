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
castor test:llm-real     # real llama.cpp smoke
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

## Required runtime/TUI validation

For changes touching TUI runtime behavior, `AgentSessionClient`, model routing, Messenger wiring, `TranscriptProjector`, `RuntimeEventPoller`, transcript rendering, or LLM-visible execution flow, unit/container/mocked tests are not enough.

You MUST run and report a product-level Castor workflow:

- `castor run:agent-test` to drive the agent in tmux and capture snapshots, or
- `castor test:tui` for tmux snapshot/e2e assertions, or
- `castor test:llm-real` for real-model paths such as `llama_cpp/flash`.

Validation must exercise the real user flow: start agent, type prompt, submit, wait for visible assistant response or visible error block, and capture TUI snapshot plus session artifacts (`events.jsonl`, `runtime-events.jsonl`, `transcript.jsonl`) on failure. Do not claim runtime/TUI work is done based only on DTO tests, mocked pollers, container compilation, or isolated service tests.

## Development rules

- Use explicit semantic suffixes in type names: `EventTypeEnum`, `EventsRecordingsTrait`, `UserEventService`, `RuntimeEventMapper`, `SettingsProvider`, `TranscriptProjector`, `Repository`, `Factory`, `DTO`, etc. Avoid ambiguous bare names.
- Prefer Symfony-native extension points and typed objects over hand-rolled routers/mappers. Before adding `instanceof` dispatch chains, stringly `match` routers, `normalize*()` arrays, or manual payload walkers, check Symfony events/subscribers/listeners, Serializer/Normalizer, Messenger handlers, or Symfony AI DTOs.
- Never add production APIs or code paths solely for tests. Use production constructors/factories or test-local fixtures/builders.
- Never use `ReflectionClass::newInstanceWithoutConstructor()`, `Closure::bind()`, or constructor/property bypass tricks in production code.
- Test helpers belong in tests, not production.

## Symfony setup

- Symfony 8.1 HTTP-less app using `Symfony\Component\DependencyInjection\Kernel\AbstractKernel` + `KernelTrait`.
- `bin/console` uses `Symfony\Component\Console\Application` with the kernel container.
- `config/bundles.php` registers `FrameworkBundle`, `MonologBundle`, and `ConsoleBundle`.
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
