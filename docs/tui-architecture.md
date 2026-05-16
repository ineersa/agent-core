# TUI Architecture

This document describes the terminal UI architecture for the `ineersa/agent-core` coding agent.

## Call flow: startup to interactive loop

```
AgentCommand::runTui()
    │
    ├─ ClientResolver::resolve()      → AgentSessionClient
    ├─ ThemeFactory::create()          → TuiTheme
    │
    ▼
InteractiveMode::run(client, theme, sessionId, cwd)
    │
    ├─ 1. $state = SessionInitializer::initialize(sessionId, cwd, prompt)
    │        └─ new or resume → TuiSessionState
    │
    ├─ 2. $screen = new ChatScreen()
    │        └─ $screen->mount($tui, $theme)
    │             └─ Creates 13 Symfony widgets in layout order
    │
    ├─ 3. $context = new TuiRuntimeContext(tui, client, state, screen, sessionStore)
    │
    ├─ 4. foreach($listenerRegistrars as $registrar)
    │        └─ $registrar->register($context)
    │             └─ $context->tui->addListener(fn(Event $e) => ...)
    │
    └─ 5. $tui->run()                    ← blocks via Revolt suspension
              │
              ├─ TickEvent → TickPollListener → RuntimeEventPoller::poll()
              │    └─ maps RuntimeEvent → TranscriptEntry → ChatScreen
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
services, and calls `$tui->run()` which blocks until a listener calls
`$tui->stop()`.

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
| `TickEvent` | `TickPollListener` | `src/Tui/Listener/TickPollListener.php` | Delegate to `RuntimeEventPoller`, refresh transcript via `ChatScreen` |

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

Segments are sorted by priority and rendered in the footer bar.

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
| `ThemeRegistry` | `src/Tui/Theme/ThemeRegistry.php` | Lookup by name, default fallback |
| `ThemeLoader` | `src/Tui/Theme/ThemeLoader.php` | Load palettes from YAML files |

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
│  runTui() → InteractiveMode::run(client, theme, sessionId, cwd)    │
└─────────────────────────┬───────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│                     InteractiveMode::run()                           │
│                                                                      │
│  1. ThemeFactory::create()           → TuiTheme                     │
│  2. SessionInitializer::initialize() → TuiSessionState              │
│  3. ChatScreen::mount(tui, theme)    → live widget tree             │
│  4. TuiRuntimeContext(tui, client, state, screen, sessionStore)     │
│  5. foreach(listenerRegistrars) $r->register($context)               │
│  6. tui->run()                                                      │
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
ChatScreen (14 widgets)
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
| `mount(Tui): void` | Create and attach all 14 widgets to TUI |
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
relevant `LiveTextWidget` to force re-render on the next tick.  Structural
widgets (separators, header, footer, top margin) never need manual invalidation
because their producers always read the correct dimensions from the live
`RenderContext`.

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

5 services autowired:                      │
  SubmitListener                           ▼
  CancelListener                  foreach($listenerRegistrars as $r)
  QuitListener                       $r->register($context)
  CtrlCInputInterceptor                   │
  TickPollListener                        ▼
                                  $context->tui->addListener(
                                      fn(SubmitEvent $e) => ...
                                  )
```

Each registrar receives a `TuiRuntimeContext` value object carrying:

| Property | Type | Purpose |
|----------|------|---------|
| `$tui` | `Symfony\Component\Tui\Tui` | TUI instance for `addListener()` |
| `$client` | `AgentSessionClient` | Runtime client for start/userMessage/cancel |
| `$state` | `TuiSessionState` | Mutable per-run state (session ID, handle, transcript, poll state) |
| `$screen` | `ChatScreen` | Widget tree for visual updates |
| `$sessionStore` | `HatfieldSessionStore` | Session persistence |

## Runtime namespaces

Classes that carry per-run state were moved to `src/Tui/Runtime/` to keep
`Tui\Application` free of runtime coupling:

| Class | File | Responsibility |
|-------|------|----------------|
| `TuiSessionState` | `src/Tui/Runtime/TuiSessionState.php` | Mutable state bag (session ID, handle, transcript, poll state) |
| `TuiRuntimeContext` | `src/Tui/Runtime/TuiRuntimeContext.php` | Per-run context value object passed to listener registrars |
| `RuntimeEventPoller` | `src/Tui/Runtime/RuntimeEventPoller.php` | Throttled polling, sequence deduplication, event → plain TranscriptEntry mapping |

### TuiSessionState

Mutable class holding per-run state, passed through `TuiRuntimeContext`:

```php
final class TuiSessionState
{
    public string $sessionId;
    public string $cwd;
    public bool $resuming;
    public ?RunHandle $handle;
    public ?StartRunRequest $request;
    /** @var list<TranscriptEntry> */
    public array $transcript = [];
    public int $lastSeq = 0;
    public float $lastPoll = 0.0;
}
```

### Runtime event polling sequence

```
TickEvent fires (every ~16ms)
    │
    ▼
TickPollListener closure
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
| `TuiListener` | Runtime Contract, AppSession, TuiRuntime, TuiScreen, TuiTranscript, Symfony TUI |
| `TuiRuntime` | TuiScreen, Runtime Contract, AppSession, Symfony TUI |
| `TuiScreen` | TuiLayout, TuiExtension, TuiHeader, TuiTranscript, TuiStatus, TuiEditor, TuiFooter, TuiWidget, TuiTheme, Symfony TUI |
| `TuiTheme` | Symfony TUI, Symfony Console |
| `TuiWidget` | TuiTheme |
| `TuiLayout` | TuiWidget, TuiTheme, individual widget layers |
| Individual widgets | TuiWidget, TuiTheme |

TUI must not import AgentCore internals, HttpKernel, or FrameworkBundle.

Key differences from the pre-cleanup boundary model:
- `TuiRuntime` and `TuiScreen` are new layers (separated from Application/Layout).
- `TuiListener` no longer depends on `TuiApplication` or `TuiWidget` — it depends
  on `TuiRuntime`/`TuiScreen`/`TuiTranscript` instead.
- `TuiApplication` depends on `TuiRuntime`/`TuiScreen` (not the reverse).
