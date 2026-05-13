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

```bash
castor install      # composer install
castor check        # Full QA: deptrac, phpunit, phpstan, cs-fixer check
castor deptrac      # Deptrac boundary enforcement
castor test         # PHPUnit tests
castor phpstan      # PHPStan static analysis
castor cs-fix       # PHP CS Fixer (fix in place)
castor cs-check     # PHP CS Fixer (dry-run check only)
```

## Symfony setup

- The application targets Symfony 8.1 HTTP-less architecture.
- Boots with `Symfony\Component\DependencyInjection\Kernel\AbstractKernel` + `KernelTrait`.
- `bin/console` uses `Symfony\Component\Console\Application` with the kernel container as the third constructor argument.
- `config/bundles.php` registers `Symfony\Component\Console\ConsoleBundle`.
- `ConsoleBundle` pulls in `ServicesBundle` via Symfony's `#[RequiredBundle]` chain.
- Do not reintroduce `FrameworkBundle`, `HttpKernel`, `public/index.php`, or FrameworkBundle-only config.
- Commands should prefer Symfony 8.1 invokable command style (`__invoke()`) and console argument resolvers over manual `InputInterface` parsing when practical.
- Configuration files in `config/` should prefer YAML over PHP. The only PHP config file kept is `config/bundles.php` (required by Symfony for bundle registration); all other settings use YAML.

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
| `HeaderWidget` | `src/Tui/Header/HeaderWidget.php` | `◆ Agent Core` |
| `TranscriptWidget` | `src/Tui/Transcript/TranscriptWidget.php` | Transcript entries with role prefixes (❯ user, ◇ assistant, ● tool) |
| `PendingMessagesWidget` | `src/Tui/Transcript/PendingMessagesWidget.php` | Queued messages during compaction; empty when nothing pending |
| `WorkingStatusWidget` | `src/Tui/Status/WorkingStatusWidget.php` | `● idle` or `◐ Working: ...`; can be hidden |
| `StatusPanelWidget` | `src/Tui/Status/StatusPanelWidget.php` | Renders keyed status entries from `setStatus()` |
| `PromptEditorWidget` | `src/Tui/Editor/PromptEditorWidget.php` | `❯ Type a message...` |
| `FooterBarWidget` | `src/Tui/Footer/FooterBarWidget.php` | Single-line: `◆ agent-core` with priority-sorted segments and right-aligned status |

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
| `.pi/plans/architecture_rollout_plan.md` | Architecture rollout plan and history |
