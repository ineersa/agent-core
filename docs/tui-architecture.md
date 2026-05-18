# TUI Architecture

This document describes the terminal UI architecture for the `ineersa/agent-core` coding agent.

## Call flow: startup to interactive loop

```
AgentCommand::runTui()
    │
    ├─ ClientResolver::resolve()      → AgentSessionClient
    │
    ▼
InteractiveMode::run(client, request, theme, sessionId)
    │
    ├─ 1. ThemeFactory::create()      → TuiTheme
    │
    ├─ 2. SessionInitializer::initialize(sessionId, request)
    │        └─ new or resume → TuiSessionState
    │
    ├─ 3. $screen = new ChatScreen(theme, sessionId)
    │        └─ $screen->mount($tui)
    │             └─ Creates live Symfony widgets in layout order
    │
    ├─ 4. $ticks = new TuiTickDispatcher()
    │
    ├─ 5. $context = new TuiRuntimeContext(tui, client, state, screen, sessionStore, ticks)
    │
    ├─ 6. foreach($listenerRegistrars as $registrar)
    │        └─ $registrar->register($context)
    │             ├─ $context->tui->addListener(fn(Event $e) => ...)
    │             └─ $context->ticks->add(fn(TickEvent $e) => ...)
    │
    ├─ 7. $tui->onTick(fn(TickEvent $e) => $ticks->dispatch($e))
    │        └─ single Symfony TUI tick callback; dispatcher multiplexes handlers
    │
    └─ 8. $tui->run()                    ← blocks via Revolt suspension
              │
              ├─ TickEvent → TuiTickDispatcher
              │    ├─ TickPollListener → RuntimeEventPoller::poll()
              │    │    └─ maps RuntimeEvent → TranscriptEntry → ChatScreen
              │    └─ FooterStateListener → ChatScreen::refresh()
              │         └─ keeps elapsed time / throughput footer live
              │
              ├─ SubmitEvent → SubmitListener
              │    └─ start run / send follow-up → AgentSessionClient
              │
              ├─ CancelEvent → CancelListener
              │    └─ ChatScreen::clearEditor()
              │
              ├─ QuitEvent → QuitListener
              │    └─ $tui->stop() → unblock run()
              │
              └─ InputEvent → CtrlCInputInterceptor (priority 100)
                   ├─ Ctrl+D → $tui->stop()
                   └─ Ctrl+C → cancel / double-press quit
```

## Event loop

The TUI runs an interactive event loop powered by **Symfony TUI**
(`Symfony\Component\Tui\Tui`) with **Revolt** as the event loop backend.

Entry point: `Ineersa\Tui\Application\InteractiveMode::run()` is a thin
orchestrator that creates the theme, session state, and `ChatScreen`,
builds `TuiRuntimeContext`, iterates over DI-tagged `TuiListenerRegistrar`
services, installs one Symfony TUI `onTick()` callback backed by
`TuiTickDispatcher`, and calls `$tui->run()` which blocks until a listener
calls `$tui->stop()`.

`Symfony\Component\Tui\Tui::onTick()` is a single-slot setter, not an
additive listener API. TUI code must register tick work through
`TuiTickDispatcher` (`$context->ticks->add(...)`) so runtime polling,
footer refresh, and future tick handlers do not overwrite one another.

### Keybindings

| Key | Action |
|-----|--------|
| Enter | Submit current prompt to agent |
| Ctrl+D | Exit TUI cleanly |
| Ctrl+C | Cancel / clear editor (press twice within 1.5s to exit) |
| Shift+Enter | Insert newline in editor |
| Escape | Cancel / clear editor |
| Arrow keys | Navigate cursor |
| Ctrl+A / Home | Move to line start |
| Ctrl+E / End | Move to line end |
| Ctrl+W | Delete word backward |
| Ctrl+K | Delete to end of line |
| Ctrl+U | Delete to start of line |

### Event flow

```
┌────────────┐     ┌───────────────────┐     ┌────────────────┐
│  Terminal   │────▶│ CtrlCInputInter-  │────▶│  EditorWidget  │
│ (keyboard)  │     │ ceptor (prio 100) │     │  (Symfony TUI) │
└────────────┘     └───────────────────┘     └───────┬────────┘
                                                     │
                                    SubmitEvent / CancelEvent / QuitEvent
                                                     │
                                                     ▼
┌──────────────────────────────────────────────────────────────────┐
│                    TuiListenerRegistrars                          │
│                                                                   │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────┐│
│  │SubmitListener│ │CancelListener│ │QuitListener  │ │TickPoll  ││
│  │              │ │              │ │              │ │Listener  ││
│  ├──────────────┤ ├──────────────┤ ├──────────────┤ ├──────────┤│
│  │• append user │ │• clear       │ │• $tui->stop()│ │• poll    ││
│  │  message     │ │  editor text │ │              │ │  events  ││
│  │• start run   │ │              │ │              │ │• update  ││
│  │• persist     │ │              │ │              │ │  screen  ││
│  └──────┬───────┘ └──────┬───────┘ └──────┬───────┘ └────┬─────┘│
│         │                │                │              │       │
│   AgentSessionClient     │                │       RuntimeEvent  │
│   HatfieldSessionStore   │                │       Poller        │
└──────────────────────────┴────────────────┴──────────────────────┘
                              │
                              ▼
                        ChatScreen
                   (updates live widgets)
```

Each event has a dedicated listener class in `src/Tui/Listener/`: each implements
`TuiListenerRegistrar` and receives `TuiRuntimeContext` via `register()`.

| Event | Listener | File | Action |
|-------|----------|------|--------|
| `InputEvent` (priority 100) | `CtrlCInputInterceptor` | `src/Tui/Listener/CtrlCInputInterceptor.php` | Ctrl+D → stop TUI; Ctrl+C → cancel/double-press quit |
| `SubmitEvent` | `SubmitListener` | `src/Tui/Listener/SubmitListener.php` | Append user message, start run or send follow-up, show processing indicator |
| `CancelEvent` | `CancelListener` | `src/Tui/Listener/CancelListener.php` | Clear editor text |
| `QuitEvent` | `QuitListener` | `src/Tui/Listener/QuitListener.php` | Call `$tui->stop()` |
| `TickEvent` | `TuiTickDispatcher` | `src/Tui/Runtime/TuiTickDispatcher.php` | Multiplex the single Symfony TUI `onTick()` callback to registered handlers |
| `TickEvent` | `TickPollListener` | `src/Tui/Listener/TickPollListener.php` | Delegate to `RuntimeEventPoller`, refresh transcript via `ChatScreen` |
| `TickEvent` | `FooterStateListener` | `src/Tui/Listener/FooterStateListener.php` | Refresh the screen so elapsed time / throughput footer segments stay live |

### Ctrl+C double-press mechanism

Implemented in `CtrlCInputInterceptor` (`src/Tui/Listener/CtrlCInputInterceptor.php`).
Single Ctrl+C clears the editor if text is present, or shows
"Press Ctrl+C again to exit" in the status panel if empty.
A second Ctrl+C within 1.5 seconds exits the TUI.
Any other key resets the double-press timer.

## Layout

Single-column vertical layout with extensible slots:

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

## Core widget contracts

### `TuiWidget`

Lightweight renderable interface, deliberately independent of Symfony TUI's `AbstractWidget`:

```php
interface TuiWidget
{
    /** @return list<string> */
    public function render(TuiRenderContext $context): array;
}
```

### `TuiRenderContext`

Carries terminal dimensions and the active theme:

```php
final readonly class TuiRenderContext
{
    public function __construct(
        public int $terminalWidth = 80,
        public int $terminalHeight = 24,
        public TuiTheme $theme,
    );
}
```

## Slot / extensibility model

Extensions interact with the TUI through explicit slots, not direct widget mutation. Implemented via `TuiExtensionContext`:

| Method | Effect |
|--------|--------|
| `setHeader(?TuiWidget)` | Replace header widget |
| `setFooter(?TuiWidget)` | Replace footer bar |
| `setEditorComponent(?TuiWidget)` | Replace prompt editor |
| `setWidget(key, ?TuiWidget, placement)` | Add/remove above/below editor |
| `setStatus(key, ?string)` | Set/remove status panel entry |
| `setWorkingMessage(?string)` | Override working indicator text |
| `setWorkingVisible(bool)` | Show/hide working row |
| `setFooterProvider(key, ?FooterSegmentProvider)` | Add/remove a keyed provider for the default footer bar |
| `onTerminalInput(callable)` | Raw terminal input interceptor |

**Key files:**

- `src/Tui/Extension/TuiExtensionContext.php` — contract
- `src/Tui/Extension/SlotBasedTuiExtensionContext.php` — delegates to TuiSlotRegistry
- `src/Tui/Layout/TuiSlotRegistry.php` — central slot registry
- `src/Tui/Layout/ChatLayout.php` — assembles layout from slots

## Footer & status providers

Extensions contribute footer segments via `FooterSegmentProvider`:

```php
interface FooterSegmentProvider
{
    /** @return list<FooterSegment> */
    public function getSegments(): array;
}
```

Segments are sorted by priority and rendered in the footer bar. Each segment
can carry an optional `ThemeColor`; `FooterBarWidget` applies semantic colors
per segment and uses Symfony TUI `AnsiUtils` for ANSI-aware width calculation
and truncation.

The default footer is intentionally small and extensible:

```text
◆ deepseek-v4-pro  |  0/0 $0.00 0% 0/1000.0k  |  ⏱ 0s  |  ⌂ agent-core  |  ⎇ main
```

Core footer state is supplied by `FooterStateListener`:

- `FooterStateInitializer` seeds model, reasoning, context window, cwd, git
  branch, and session start time from session metadata, request state, and
  `AppConfig`.
- `FooterStateSegmentProvider` renders the Pi-like footer segments. Reasoning
  is not shown as text; it colors the `◆` indicator.
- `RuntimeEventPoller` accumulates token usage and provider-returned cost from
  `llm_step_completed` runtime events into `TuiSessionState`.
- `TuiTickDispatcher` drives regular `ChatScreen::refresh()` calls so elapsed
  time and throughput update while the TUI is idle.

Extensions have two footer integration modes:

| API | Use case |
|-----|----------|
| `setFooter(?TuiWidget)` | Replace the entire footer bar widget |
| `setFooterProvider(string $key, ?FooterSegmentProvider $provider)` | Add/remove keyed segments in the default footer bar |
| `setStatus(string $key, ?string $text)` | Add/remove keyed status text shown by the status panel and footer data provider |

`FooterDataProvider` stores providers by key, so third-party packages can
remove or replace their own provider without mutating the built-in provider
list directly.

## Theme system

### Overview

The TUI uses a semantic theme system. Widgets reference semantic tokens (e.g., `ThemeColor::Accent`, `ThemeColor::Muted`) rather than concrete hex values. Themes map tokens to ANSI colors.

### Key classes

| Class | File | Role |
|-------|------|------|
| `TuiTheme` | `src/Tui/Theme/TuiTheme.php` | Theme interface: `accent()`, `muted()`, `error()`, `color()` |
| `ThemeColor` | `src/Tui/Theme/ThemeColor.php` | Semantic color enum (50+ tokens) |
| `ThemePalette` | `src/Tui/Theme/ThemePalette.php` | Immutable palette: ThemeColor → color spec |
| `DefaultTheme` | `src/Tui/Theme/DefaultTheme.php` | Symfony TUI `Style`-backed implementation |
| `ThemeRegistry` | `src/Tui/Theme/ThemeRegistry.php` | Autowireable registry; loads configured and built-in YAML palettes, lookup by name |

### Theme file format

YAML files in `config/themes/` (or custom paths from Hatfield settings):

```yaml
name: cyberpunk
vars:
    neon: "#ff00ff"
    electric: "#00ffff"
colors:
    accent: "electric"     # resolves var
    muted: "#718096"       # direct hex
    text: ""               # empty = no color / default fg
```

### Theme selection

Theme selection is driven by Hatfield settings (see `docs/settings.md`):

```yaml
# ~/.hatfield/settings.yaml or <project>/.hatfield/settings.yaml
tui:
    theme: cyberpunk
```

If no settings file exists, the built-in default is `cyberpunk`.

Theme search paths are also configurable via Hatfield settings:

```yaml
tui:
    theme_paths:
        - '%kernel.project_dir%/config/themes'
        - '.hatfield/themes'
        - '~/.hatfield/themes'
```

### Creating a custom theme

1. Create a YAML file in any configured theme path (e.g., `.hatfield/themes/`).
2. Add the theme file following the format above.
3. Select it in Hatfield settings:

```yaml
tui:
    theme: my-custom-theme
```

Theme color tokens should use the semantic names defined in `ThemeColor` (lowercased, e.g., `accent`, `muted`, `error`, `header`, `footer`, `separator`).

### Naming convention

- Intentional explicit naming. No `Chrome` naming.
- Directory structure is flat by domain: `src/Tui/Theme/`, `src/Tui/Status/`, `src/Tui/Footer/`.

## Header

The header widget displays the **Hatfield ASCII logo** using Unicode
box-drawing characters.

File: `src/Tui/Header/HeaderWidget.php`

The logo is styled with the `ThemeColor::Header` semantic color.

## Architecture overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                         AgentCommand                                 │
│  runTui() → InteractiveMode::run(client, request, theme, sessionId)│
└─────────────────────────┬───────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│                     InteractiveMode::run()                           │
│                                                                      │
│  1. ThemeFactory::create()           → TuiTheme                     │
│  2. SessionInitializer::initialize() → TuiSessionState              │
│  3. ChatScreen::mount(tui)           → live widget tree             │
│  4. TuiTickDispatcher              → composable tick handlers       │
│  5. TuiRuntimeContext(tui, client, state, screen, store, ticks)      │
│  6. foreach(listenerRegistrars) $r->register($context)               │
│  7. tui->onTick(fn($event) => ticks->dispatch($event))               │
│  8. tui->run()                                                      │
└─────────────────────────┬───────────────────────────────────────────┘
                          │
          ┌───────────────┼───────────────┐
          ▼               ▼               ▼
┌─────────────────┐ ┌───────────┐ ┌──────────────────────┐
│ TuiRuntime      │ │  Screen   │ │ Listeners            │
│                 │ │           │ │                      │
│ • TuiSession-   │ │ ChatScreen│ │ TuiListenerRegistrar  │
│   State         │ │           │ │ register(context)     │
│ • RuntimeEvent- │ │ owns 13   │ │ → addListener()      │
│   Poller        │ │ Symfony   │ │   on tui instance    │
│                 │ │ widgets   │ │                      │
└─────────────────┘ └───────────┘ └──────────────────────┘
```

## ChatScreen: live render bridge

`ChatScreen` (`src/Tui/Screen/ChatScreen.php`) bridges the lightweight `TuiWidget`
model and layout system to live Symfony TUI widgets. It owns the entire widget
tree and provides a clean API surface that listeners use instead of directly
mutating individual widget refs.

### Widget tree owned by ChatScreen

```
ChatScreen (13 widgets)
  ├── topMarginWidget    (LiveTextWidget)  4 blank lines (configurable)
  ├── headerWidget       (LiveTextWidget)  ← HeaderWidget.render()
  ├── headerSeparator    (LiveTextWidget)  ─── at live terminal width
  ├── transcriptWidget   (LiveTextWidget)  ← TranscriptWidget + entries
  ├── pendingWidget      (LiveTextWidget)  PendingMessagesWidget
  ├── workingWidget      (LiveTextWidget)  WorkingStatusWidget (via registry)
  ├── statusPanelWidget  (LiveTextWidget)  StatusPanelWidget (via registry)
  ├── aboveEditorWidget  (LiveTextWidget)  extension widgets (combined)
  ├── editorSeparator    (LiveTextWidget)  ─── at live terminal width
  ├── editorWidget       (EditorWidget)    ← real Symfony TUI editor
  ├── belowEditorWidget  (LiveTextWidget)  extension widgets (combined)
  ├── footerSeparator    (LiveTextWidget)  ─── at live terminal width
  └── footerWidget       (LiveTextWidget)  FooterBarWidget
```

All `LiveTextWidget` instances carry a producer closure that reads current
state (renderable / registry / extension slots) and re-computes content
at the **live terminal width** on every render.  The Symfony TUI render
cache (keyed on revision × columns × rows) ensures we only re-compute
when dimensions change or `invalidate()` is called.  This is how
separators and other static sections respond to terminal resize:
unlike `TextWidget` which stores a fixed pre-computed string, the
producer receives the current `RenderContext` and sizes output accordingly.

### ChatScreen public API

| Method | Purpose |
|--------|---------|
| `mount(Tui): void` | Create and attach all 13 widgets to TUI |
| `setTranscriptEntries(TranscriptEntry[]): void` | Replace transcript content |
| `appendTranscript(TranscriptEntry): void` | Add one entry to transcript |
| `clearEditor(): void` | Reset editor to empty |
| `editorText(): string` | Read editor content |
| `setWorkingMessage(?string): void` | Override working indicator |
| `setWorkingVisible(bool): void` | Show/hide working row |
| `setStatus(string, ?string): void` | Set/remove status entry |
| `refresh(): void` | Force full re-render of static sections |
| `registry(): TuiSlotRegistry` | Get slot registry (for extensions) |
| `extensionContext(): TuiExtensionContext` | Get extension context facade |

### State updates via invalidate()

Listeners mutate renderable / registry state and call `invalidate()` on the
relevant `LiveTextWidget` to force re-render on the next tick. Structural
widgets also read current dimensions from the live `RenderContext`. The footer
is invalidated on tick via `ChatScreen::refresh()` so live values (elapsed time
and throughput) update even when no runtime events arrive.

```
setTranscriptEntries()  → transcriptRenderable + transcriptWidget.invalidate()
setWorkingMessage()     → registry + workingRenderable + workingWidget.invalidate()
setStatus()             → registry + statusPanelRenderable + footerDataProvider
                          + statusPanelWidget.invalidate() + footerWidget.invalidate()
refresh()               → invalidates all mutable widgets (safety net)
```

## Listener registration flow

Listeners are stateless services implementing `TuiListenerRegistrar` and tagged
with `app.tui_listener` for DI-driven registration:

```
services.yaml                         InteractiveMode
─────────────                         ───────────────

_instanceof:                          __construct(
  TuiListenerRegistrar:                   $listenerRegistrars:
    tags: [app.tui_listener]              !tagged_iterator
                                        )  app.tui_listener

6 services autowired:                      │
  SubmitListener                           ▼
  CancelListener                  foreach($listenerRegistrars as $r)
  QuitListener                       $r->register($context)
  CtrlCInputInterceptor                   │
  TickPollListener                        ▼
  FooterStateListener            event listeners: $context->tui->addListener(...)
                                 tick handlers:  $context->ticks->add(...)
```

Each registrar receives a `TuiRuntimeContext` value object carrying:

| Property | Type | Purpose |
|----------|------|---------|
| `$tui` | `Symfony\Component\Tui\Tui` | TUI instance for `addListener()` |
| `$client` | `AgentSessionClient` | Runtime client for start/userMessage/cancel |
| `$state` | `TuiSessionState` | Mutable per-run state (session ID, handle, transcript, poll state) |
| `$screen` | `ChatScreen` | Widget tree for visual updates |
| `$sessionStore` | `HatfieldSessionStore` | Session persistence |
| `$ticks` | `TuiTickDispatcher` | Per-run tick handler multiplexer |

## Runtime namespaces

Classes that carry per-run state were moved to `src/Tui/Runtime/` to keep
`Tui\Application` free of runtime coupling:

| Class | File | Responsibility |
|-------|------|----------------|
| `TuiSessionState` | `src/Tui/Runtime/TuiSessionState.php` | Mutable state bag (session ID, handle, transcript, poll state) |
| `TuiRuntimeContext` | `src/Tui/Runtime/TuiRuntimeContext.php` | Per-run context value object passed to listener registrars |
| `RuntimeEventPoller` | `src/Tui/Runtime/RuntimeEventPoller.php` | Throttled polling, sequence deduplication, event → plain TranscriptEntry mapping, footer usage accumulation |
| `TuiTickDispatcher` | `src/Tui/Runtime/TuiTickDispatcher.php` | Multiplexes Symfony TUI's single `onTick()` callback to multiple handlers |

### TuiSessionState

Mutable class holding per-run state, passed through `TuiRuntimeContext`:

```php
final class TuiSessionState
{
    public string $sessionId;
    public bool $resuming;
    public ?RunHandle $handle;
    public ?StartRunRequest $request;
    /** @var list<TranscriptEntry> */
    public array $transcript = [];
    public int $lastSeq = 0;
    public float $lastPoll = 0.0;

    // Footer/runtime projection state
    public string $footerModel = '';
    public string $footerReasoning = '';
    public int $inputTokens = 0;
    public int $outputTokens = 0;
    public float $totalCost = 0.0;
    public int $contextWindow = 0;
    public float $sessionStartTime = 0.0;
    public string $cwd = '';
    public string $branch = '';
}
```

### Runtime event polling sequence

```
TickEvent fires (every ~16ms)
    │
    ▼
TuiTickDispatcher::dispatch(event)
    │
    ├─ TickPollListener handler
    │
    ├─ throttle: skip if < POLL_INTERVAL (50ms) since lastPoll
    │
    ▼
RuntimeEventPoller::poll(state, client)
    │
    ├─ client->events(runId)        → iterable<RuntimeEvent>
    ├─ skip events with seq ≤ lastSeq (dedup)
    ├─ persist to runtime-events.jsonl
    ├─ formatEventToEntry(event) → TranscriptEntry (plain model, NO theme)
    ├─ append to state.transcript[]
    ├─ persist to transcript.jsonl
    │
    ▼
ChatScreen::appendTranscript(entry)
    │
    ├─ Updates TranscriptWidget with new entries
    └─ TranscriptWidget sets TextWidget text (with role prefixes + theme)
    │
    └─ FooterStateListener handler
         └─ ChatScreen::refresh() so live footer values re-render
```

### Event → transcript entry mapping

`RuntimeEventPoller::formatEventToEntry()` maps runtime events to plain
`TranscriptEntry` objects. Theming and role prefixes (❯ ◇ ●) are applied at
render time by `TranscriptEntry::render()`:

| Event type | Entry role | Example text | Display prefix (applied by render()) |
|------------|------------|-------------|--------------------------------------|
| `run_started` | `system` | `Run started: <prompt>` | (none, accent-colored) |
| `message_update` | `assistant` | `<truncated content>` | `◇ ` |
| `message_end` | `assistant` | `(end of message)` | `◇ ` (muted) |
| `tool_execution_start` | `tool` | `<tool> <input>` | `● ` |
| `tool_execution_end` | `tool` | `<tool> <summary>` | `● ` |
| `turn_start/end`, `agent_start/end` | — | (null: not displayed) | — |
| Unknown types | `system` | `· <type>` | (muted) |

## Dependency boundaries

The TUI layers are enforced by Deptrac (`depfile.yaml`):

| Layer | May depend on |
|-------|--------------|
| `TuiApplication` | Runtime Contract, AppSession, AppConfig, TuiRuntime, TuiScreen, TuiListener, TUI internals, Symfony TUI/Console |
| `TuiListener` | Runtime Contract, AppSession, AppConfig, TuiRuntime, TuiScreen, TuiTranscript, TuiFooter, TuiTheme, Symfony TUI |
| `TuiRuntime` | TuiScreen, Runtime Contract, AppSession, TuiTranscript, TuiTheme, Symfony TUI |
| `TuiScreen` | TuiLayout, TuiExtension, TuiHeader, TuiTranscript, TuiStatus, TuiEditor, TuiFooter, TuiWidget, TuiTheme, Symfony TUI |
| `TuiExtension` | TuiLayout, TuiWidget, TuiFooter, TuiTheme |
| `TuiTheme` | AppConfig, Symfony TUI, Symfony Console, Symfony YAML |
| `TuiWidget` | TuiTheme, Symfony TUI |
| `TuiLayout` | TuiWidget, TuiTheme, individual widget layers |
| Individual widgets | TuiWidget, TuiTheme |

TUI must not import AgentCore internals, HttpKernel, or FrameworkBundle.

Key differences from the pre-cleanup boundary model:
- `TuiRuntime` and `TuiScreen` are new layers (separated from Application/Layout).
- `TuiListener` no longer depends on `TuiApplication` or `TuiWidget` — it depends
  on `TuiRuntime`/`TuiScreen`/`TuiTranscript` instead.
- `TuiApplication` depends on `TuiRuntime`/`TuiScreen` (not the reverse).
