# TUI Architecture

Terminal UI for Hatfield interactive sessions (`src/Tui`). See the [context and projection diagrams](../architecture/context-and-projection.md).

For user-facing editor and command reference, see [terminal usage](terminal-usage.md).

## Layout (single column)

1. Header
2. Transcript / history
3. Pending / human-input affordances
4. Working / status lines
5. Extension widgets
6. Editor
7. Footer

`ChatScreen` mounts the widget tree and owns focus among the editor, overlays, and extension widgets. `InteractiveMode` creates each session's screen and services through `TuiSessionCompositionFactory`, then registers listeners.

## Tool elapsed labels

Live tool headers show whole seconds, starting at `0s`, and update once per second. Completed, failed, and cancelled calls retain millisecond precision below one second. Longer durations use seconds or minutes and seconds.

The ordinary tool timer measures elapsed time from the canonical tool-start event through the terminal event, including admission and queue wait. It does not measure worker execution alone. Terminal labels freeze from recorded timestamps and remain unchanged on replay. Deferred subagent progress keeps its existing child lifecycle timing.

## Startup

`AgentCommand` resolves an `AgentSessionClient`. `InteractiveMode::run(...)` mounts `ChatScreen`, creates the per-session service scope, rebuilds the transcript, and binds session commands, compaction, hotkeys, and extensions.

## Frame and cursor output

Hatfield no longer replaces Symfony's `Renderer` or `ScreenWriter`. Symfony TUI
8.2 caches maximum visible widths on `LineBufferInterface` and skips per-row
width rescans when the cached maximum fits the available columns. The stock
`ScreenWriter` publishes cursor hide/restore inside the synchronized output
window and scrolls overheight growth with line feeds.

## Key types

| Type | Role |
|---|---|
| Chrome widgets (header, status, pending, loaded resources, compact header, footer) | Native Symfony TUI `AbstractWidget`s mounted directly by `ChatScreen` |
| `FooterDataProvider` / `FooterSegmentProvider` / `FooterBarWidget` | Footer composition |
| `ThemeRegistry` / YAML themes under `config/themes/` | Theming |
| `RuntimeEventPoller` | Drains projected runtime events into transcript/status state |

## Commands (built-in examples)

| Command | Role |
|---|---|
| `/new`, `/resume`, `/rename`, `/history` | Session lifecycle / conversation history |
| `/compact` | Context compaction |
| `/agents-live`, `/agents-main` | Subagent live view navigation |
| `/model`, `/settings-show`, `/help`, `/hotkeys` | Session utilities |
| Prompt `/name` | Prompt templates when discovered |

Hotkey catalog: `/hotkeys` (display metadata; input routing is separate).

## Picker rendering

Pickers use Symfony `SelectListWidget` and `TextWidget` inside a `ContainerWidget`. The shared host `PickerOverlay` handles mounting and closing, not rendering or keyboard selection. The session picker applies its accent through Symfony's `::selected` style instead of rebuilding labels on arrow input.

The file-rewind extension mounts the same native widgets through `TuiExtensionContextInterface`. It cannot import the host's internal `PickerOverlay` and does not maintain a copy. Picker changes request differential rendering. Switching the visible transcript to a child run still requests a full frame.

## Runtime boundary

TUI sends commands and consumes events through `AgentSessionClient` + runtime protocol DTOs.

- Live committed events arrive on controller stdout.
- Transient stream deltas use sequence `0` and are not durable replay.
- `RuntimeEventTranslator` maps AgentCore `RunEvent` values to protocol DTOs. It does not consume `RunState`.

On resume, the screen shows a restoring notice and blocks execution input.
The run-control owner rebuilds its current state and bounded transcript together.
The TUI does not read the archive or build a second execution state.

The controller sends the sealed transcript as bounded `bootstrap.frame` records.
The TUI checks frame order, transfer identity, epoch, actual bytes, record count
and checksum before replacing the mounted transcript and hydrating its projector.
Only then does it acknowledge the exact committed cut. The controller deletes
the private spool and sends the canonical suffix from that cut. `session.ready`
enables execution input after catch-up; `runtime.ready` only means the controller
transport is usable. Cancelled, corrupt, timed-out or disconnected transfers
leave the screen visibly unattached. Reload and session navigation remain available.

Do not reach into AgentCore stores from widgets.

Dependency direction follows `depfile.yaml`. TUI may depend on CodingAgent services and models when semantics match. CodingAgent generally must not depend on TUI, with specific approved CLI bridge edges. Direct TUI → AgentCore edges are allowed only where Deptrac lists them; prefer the owning CodingAgent service. `Runtime/Contract` and `Runtime/Protocol` remain for session/runtime protocol contracts, not as a workaround boundary for ordinary CodingAgent ownership.

## Extensions

Generic TUI extension contracts live in `Ineersa\Hatfield\ExtensionApi\Tui\*` and may depend on **Symfony TUI** public widgets only. Feature UX belongs in extension packages.

Public `TuiExtensionContextInterface` exposes status entries (`setStatus`), tick hooks (`onTick`), and native `AbstractWidget` overlays after the editor (`insertOverlayAfterEditor` / `removeOverlay` / `setFocus`); it does not expose internal widget replacement. Host bridge: `BridgeTuiExtensionContext`.

One-shot status-panel notices use `setTransientStatus`. They clear on the next nonempty submit. Persistent `setStatus` rows keep their existing lifetime.

## Related

- Sessions: [session-storage.md](session-storage.md)
- Compaction: [compaction.md](compaction.md)
- Approvals / questions: [human-input.md](human-input.md), [approvals.md](approvals.md)
- Testing: [tui-testing.md](tui-testing.md)
- Runtime topology: [async-runtime-architecture.md](async-runtime-architecture.md)
