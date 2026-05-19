# Agent Core Monorepo

**Modular Monolith.** Single Composer application with logical module boundaries enforced by Deptrac.

## Source layout

```
src/
  AgentCore/       Core agent loop, domain model, contracts, infrastructure (Ineersa\AgentCore)
  CodingAgent/     Symfony 8.1 HTTP-less CLI app: commands, runtime, tools, extensions (Ineersa\CodingAgent)
  Tui/             Terminal UI: screens, widgets, theme, keybinding, renderer (Ineersa\Tui)

tests/
  AgentCore/       AgentCore test suite (Ineersa\AgentCore\Tests)
  CodingAgent/     CodingAgent test suite (Ineersa\CodingAgent\Tests)

bin/console        Single CLI entry point
config/            Symfony config (YAML preferred; only bundles.php is PHP)
castor.php         Castor task runner entry point
depfile.yaml       Deptrac boundary enforcement config
phpstan.dist.neon  PHPStan config (baseline in phpstan-baseline.neon)
.php-cs-fixer.dist.php  PHP CS Fixer config
```

## Development

### YOU MUST use Castor — never raw tool commands

**All QA and test commands MUST be run through the Castor task runner.**
Castor applies the correct flags, excludes, config-file paths, and
argument ordering that this project requires. Raw vendor-bin commands
bypass these guarantees.

**Always use Castor first.** Raw vendor-bin commands are only
acceptable to isolate a specific failure that Castor already reported.
Never use raw commands as the primary way to run tools.

| Raw command (debug only) | Correct Castor command (always use first) |
|---|---|
| `vendor/bin/phpunit` | `castor test` |
| `vendor/bin/phpunit --filter=X` | `castor test --filter=X` |
| `vendor/bin/phpstan analyse` | `castor phpstan` |
| `vendor/bin/phpstan analyse <path>` | `castor phpstan <path>` |
| `vendor/bin/php-cs-fixer fix` | `castor cs-fix` |
| `vendor/bin/php-cs-fixer fix <path>` | `castor cs-fix <path>` |
| `vendor/bin/deptrac analyze` | `castor deptrac` |

Running raw commands directly will produce **wrong results** — missing
required flags (`--exclude-group`, `--config`, `--no-progress`,
`--colors=always`). Castor applies them automatically.

### Castor command reference

```bash
castor install           # composer install
castor check             # Full QA: deptrac → phpunit → phpstan → cs-fixer
castor test              # PHPUnit (excludes tui-e2e and llm-real groups)
castor test --filter=X   # PHPUnit filtered to matching tests
castor test:tui          # TUI e2e snapshot tests (requires tmux)
castor test:tui-update   # TUI e2e + update golden snapshots
castor test:llm-real     # Real llama.cpp smoke test
castor deptrac           # Deptrac boundary enforcement
castor phpstan           # PHPStan static analysis (full project)
castor phpstan src/X/    # PHPStan on a specific path
castor phpstan:baseline  # Regenerate PHPStan baseline
castor cs-fix            # PHP CS Fixer (fix in place, full project)
castor cs-fix src/X/     # CS Fixer on a specific path
castor cs-check          # PHP CS Fixer (dry-run check only)
castor cache:clear       # Remove generated QA caches
castor run:agent         # Launch agent TUI in a tmux session
castor run:agent-test    # Deterministic tmux session for snapshot testing
castor worktree:remove <slug> --force [--delete-branch]
castor idea:run-configs  # Generate PhpStorm run configurations
```

`castor check` does NOT run tmux e2e tests (`tui-e2e` group) because they
require tmux and are environment-sensitive. Use `castor test:tui`
explicitly when testing TUI rendering changes.

## Development rules

- **Use explicit semantic suffixes in type names so the role is knowable from the name alone.**
  Prefer names such as `EventTypeEnum`, `EventRecordingsTrait`, `UserEventService`,
  `RuntimeEventMapper`, `SettingsProvider`, or `TranscriptProjector`; do not use
  ambiguous bare names when a suffix can explain whether the artifact is an enum,
  trait, service, mapper, provider, projector, repository, factory, or DTO.
- **Never add production APIs or code paths that exist solely to support tests.**
  Tests must use production constructors, factories, or create test-local
  fixtures/builders. Do not add test-only static factory methods, test-only
  visibility modifiers, or hooks to production code.
- **Never use \ReflectionClass::newInstanceWithoutConstructor(),**
  **\Closure::bind(),** or similar constructor/property bypass techniques
  in production code to create test fixtures. These belong in test helpers
  (if anywhere); production code must be testable through its public API.
- If tests require a helper to construct a complex object, place that helper
  in the test class or a test-local utility — not in production code.

## Symfony setup

- The application targets Symfony 8.1 HTTP-less architecture.
- Boots with `Symfony\Component\DependencyInjection\Kernel\AbstractKernel` + `KernelTrait`.
- `bin/console` uses `Symfony\Component\Console\Application` with the kernel container as the third constructor argument.
- `config/bundles.php` registers `Symfony\Component\Console\ConsoleBundle`.
- `ConsoleBundle` pulls in `ServicesBundle` via Symfony's `#[RequiredBundle]` chain.
- Do not reintroduce `FrameworkBundle`, `HttpKernel`, `public/index.php`, or FrameworkBundle-only config.
- Commands should prefer Symfony 8.1 invokable command style (`__invoke()`) and console argument resolvers over manual `InputInterface` parsing when practical.
- Configuration files in `config/` should prefer YAML over PHP. The only PHP config file kept is `config/bundles.php` (required by Symfony for bundle registration); all other settings use YAML.

## Hatfield settings

User-facing configuration uses the Hatfield settings system:

- **Global:** `~/.hatfield/settings.yaml` (user-level settings)
- **Project:** `<project>/.hatfield/settings.yaml` (project-local overrides)
- **Defaults:** `config/hatfield.defaults.yaml` (shipped with the app)

Precedence: built-in defaults < home settings < project settings.

The `.hatfield/` directory is **tracked** (not gitignored) so project
settings can be shared. Only runtime subdirectories (`sessions/`, `tmp/`,
`cache/`, `logs/`) are ignored via `.hatfield/.gitignore`.

Theme selection and theme search paths are configured via Hatfield settings,
not via Symfony container parameters. The `AppConfigResolver` service
loads and merges all layers at runtime.

The committed `.hatfield/settings.yaml` in this project serves as both
the project-local settings file and the example. When adding new
settings keys, update `docs/settings.md` and keep
`.hatfield/settings.yaml` comments in sync.

**Do not recreate `.hatfield.example/`** — the `.hatfield.example/`
directory has been removed. All example/commented settings live inside
`.hatfield/settings.yaml`.

**Session storage:** `session_id === run_id` — a single identity for
both the TUI session and the AgentCore run. Each session is a
self-contained directory under `.hatfield/sessions/<id>/` with
`metadata.yaml`, canonical `events.jsonl` and `state.json` for
AgentCore, plus `transcript.jsonl` and `runtime-events.jsonl` as
projections. The directory name is the canonical ID; embedded IDs in
files are validated on read. See `docs/session-storage.md` for full
details including locking, resume flow, future fork tree design, and
known gaps.

See `docs/settings.md` for full settings documentation.

## Architecture boundaries

| Layer | Location | Owns | Must not depend on |
|-------|----------|------|--------------------|
| Core library | `src/AgentCore/` | Domain model, pipeline, contracts, in-memory stores | `CodingAgent`, `Tui`, `HttpKernel`, `FrameworkBundle` |
| TUI presentation | `src/Tui/` | Terminal UI: application screens, layout composition, widgets, status/footer extensibility, slot registry | `AgentCore`, `HttpKernel`, `FrameworkBundle` |
| Application | `src/CodingAgent/` | HTTP-less CLI app, commands, runtime boundary, tools, extensions, session, wiring | (may depend on both) |

## TUI architecture

The TUI follows a single-column layout with extensible slots inspired by pi-mono's ExtensionUIContext pattern:

```text
header
─────────────────
transcript / history
pending messages
working status
status panel (keyed entries)
above-editor extension widgets
─────────────────
editor
below-editor extension widgets
─────────────────
footer
```

### Key contracts

| Interface/Class | File | Role |
|-----------------|------|------|
| `TuiWidget` | `src/Tui/Widget/TuiWidget.php` | Lightweight renderable interface (`render(TuiRenderContext): list<string>`) |
| `TuiSlotRegistry` | `src/Tui/Layout/TuiSlotRegistry.php` | Central registry for replaceable slots (header, footer, editor, widgets, status, working state, input handlers) |
| `ChatLayout` | `src/Tui/Layout/ChatLayout.php` | Composes widgets in the defined order; merges default and replacement widgets |
| `TuiExtensionContext` | `src/Tui/Extension/TuiExtensionContext.php` | Extension contract for slot manipulation; extensions receive this, never mutate widgets directly |
| `SlotBasedTuiExtensionContext` | `src/Tui/Extension/SlotBasedTuiExtensionContext.php` | Concrete implementation delegating to `TuiSlotRegistry` |
| `FooterDataProvider` | `src/Tui/Footer/FooterDataProvider.php` | Aggregates `FooterSegmentProvider` instances; exposes read-only projection for extensions |
| `FooterSegmentProvider` | `src/Tui/Footer/FooterSegmentProvider.php` | Extension interface: return `list<FooterSegment>` with priority-sorted ordering |
| `FooterBarWidget` | `src/Tui/Footer/FooterBarWidget.php` | Renders segments with priority, right-aligned status entries, width truncation |

### Extension/override points

Extensions use `TuiExtensionContext` to interact with the TUI. All overrides are slot-based:

| Method | Effect |
|--------|--------|
| `setHeader(?TuiWidget)` | Replace the header widget |
| `setFooter(?TuiWidget)` | Replace the footer bar |
| `setEditorComponent(?TuiWidget)` | Replace the prompt editor |
| `setWidget(key, ?TuiWidget, placement)` | Add/remove widgets above or below the editor |
| `setStatus(key, ?string)` | Set/remove a status entry in the status panel |
| `setWorkingMessage(?string)` | Override the working indicator text |
| `setWorkingVisible(bool)` | Show/hide the working indicator row |
| `onTerminalInput(callable)` | Register a raw terminal input interceptor |

### Default widgets

| Widget | File | Renders |
|--------|------|--------|
| `HeaderWidget` | `src/Tui/Header/HeaderWidget.php` | Hatfield ASCII logo (box-drawing characters) |
| `TranscriptWidget` | `src/Tui/Transcript/TranscriptWidget.php` | Transcript entries with role prefixes (❯ user, ◇ assistant, ● tool) |
| `PendingMessagesWidget` | `src/Tui/Transcript/PendingMessagesWidget.php` | Queued messages during compaction; empty when nothing pending |
| `WorkingStatusWidget` | `src/Tui/Status/WorkingStatusWidget.php` | `● idle` or `◐ Working: ...`; can be hidden |
| `StatusPanelWidget` | `src/Tui/Status/StatusPanelWidget.php` | Renders keyed status entries from `setStatus()` |
| `PromptEditorWidget` | `src/Tui/Editor/PromptEditorWidget.php` | `❯ Type a message...` |
| `FooterBarWidget` | `src/Tui/Footer/FooterBarWidget.php` | Single-line: `◆ agent-core` with priority-sorted segments and right-aligned status |

### Theme system

Semantic color tokens (`ThemeColor` enum) mapped to ANSI colors via YAML theme files.

- **Interface:** `TuiTheme` (`src/Tui/Theme/TuiTheme.php`) — `accent()`, `muted()`, `error()`, `color()`
- **Tokens:** `ThemeColor` enum (`src/Tui/Theme/ThemeColor.php`) — 50+ semantic colors
- **Palette:** `ThemePalette` (`src/Tui/Theme/ThemePalette.php`) — immutable map with var/alias resolution
- **Implementation:** `DefaultTheme` (`src/Tui/Theme/DefaultTheme.php`) — Symfony TUI `Style`-backed
- **Registry:** `ThemeRegistry` (`src/Tui/Theme/ThemeRegistry.php`) — built-in themes, default `cyberpunk`
- **Loader:** `ThemeLoader` (`src/Tui/Theme/ThemeLoader.php`) — YAML theme file loading
- **Config:** Hatfield settings (`~/.hatfield/settings.yaml` / `<project>/.hatfield/settings.yaml`) — see `docs/settings.md`
- **Themes:** `config/themes/*.yaml` — 6 built-in themes (cyberpunk, catppuccin-mocha, nord, gruvbox-dark, oh-p-dark, tokyo-night)

See `docs/tui-architecture.md` for full TUI documentation.

## Runtime architecture

The app follows a strict layered boundary for runtime/TUI communication:

- `src/Tui/` depends on `CodingAgent/Runtime/Contract`, `CodingAgent/Runtime/Protocol`, and `Symfony Tui`.
- `src/CodingAgent/Runtime/Contract/` and `Protocol/` define the canonical runtime event/command DTOs and the `AgentSessionClient` interface.
- `src/CodingAgent/Runtime/InProcess/` and `Process/` implement `AgentSessionClient` using agent-core services or a subprocess.
- `src/CodingAgent/CLI/` wires everything together via the single `agent` command, delegating to `Ineersa\Tui\Application\InteractiveMode` for TUI mode.

The TUI must **never** import `Ineersa\AgentCore\Application`, `Ineersa\AgentCore\Infrastructure`, or `Symfony\Component\Messenger` directly.

Boundary enforcement: `castor deptrac` (runs `vendor/bin/deptrac analyze --config-file=depfile.yaml --no-progress`).

## AGENTS.md map

Architecture documentation within the source tree:

| File | Scope |
|------|-------|
| `src/AgentCore/Domain/AGENTS.md` | Domain model index, message and event sub-documents |
| `src/AgentCore/Domain/Message/AGENTS.md` | Bus message taxonomy: command, execution, and publisher payloads |
| `src/AgentCore/Domain/Event/AGENTS.md` | Event lifecycle taxonomy, ordering constraints, projection sinks |
| `src/AgentCore/Application/AGENTS.md` | Command→handler topology, message dispatch flow, event projectors, observability wiring |
| `src/AgentCore/Infrastructure/Doctrine/AGENTS.md` | Doctrine persistence schema migration notes |
| `.pi/plans/` | Design and rollout plans for past and current features |
| `docs/tui-architecture.md` | Full TUI architecture: layout, widgets, slots, theme system, built-in themes |
| `docs/tui-testing.md` | TUI tmux testing: `run:agent`, `run:agent-test`, snapshots, keybindings, golden e2e tests |
| `tests/Tui/Snapshots/` | Golden TUI snapshot fixtures for e2e comparison |
| `docs/settings.md` | Hatfield settings: global/project config, YAML format, theme selection, precedence |
| `docs/session-storage.md` | Session storage: directory layout, file purposes, ID rules, resume/fork flows, locking, backward compat |
