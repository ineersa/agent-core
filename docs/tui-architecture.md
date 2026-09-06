# TUI Architecture

Terminal UI for Hatfield interactive sessions (`src/Tui`). Visual overview: [`../architecture/index.html`](../architecture/index.html) when present.

## Layout (single column)

1. Header
2. Transcript / history
3. Pending / human-input affordances
4. Working / status lines
5. Extension widgets
6. Editor
7. Footer

`ChatScreen` is the composition root. It mounts chrome widgets, wires listeners, and owns focus among editor, overlays, and extension widgets.

## Startup

`AgentCommand` resolves an `AgentSessionClient`, then `InteractiveMode::run(...)` mounts `ChatScreen` with theme, session state, and listener registrars (session commands, compaction, hotkeys, extensions).

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

## Runtime boundary

TUI sends commands and consumes events through `AgentSessionClient` + runtime protocol DTOs.

- Live committed events arrive on controller stdout.
- Transient stream deltas use sequence `0` and are not durable replay.
- `RuntimeEventTranslator` maps AgentCore `RunEvent` values to protocol DTOs. It does not consume `RunState`.

Do not reach into AgentCore stores from widgets.

Dependency direction follows `depfile.yaml`: TUI may depend on CodingAgent services and models when semantics match. CodingAgent must not depend on TUI. Direct TUI → AgentCore edges are allowed only where Deptrac lists them; prefer the owning CodingAgent service. `Runtime/Contract` and `Runtime/Protocol` remain for session/runtime protocol surfaces, not as a workaround boundary for ordinary CodingAgent ownership.

## Extensions

Generic TUI extension contracts live in `Ineersa\Hatfield\ExtensionApi\Tui\*` and may depend on **Symfony TUI** public widgets only. Feature UX belongs in extension packages.

Public `TuiExtensionContextInterface` exposes status entries (`setStatus`), tick hooks (`onTick`), and native `AbstractWidget` overlays after the editor (`insertOverlayAfterEditor` / `removeOverlay` / `setFocus`); it does not expose internal widget replacement. Host bridge: `BridgeTuiExtensionContext`.

## Related

- Sessions: [session-storage.md](session-storage.md)
- Compaction: [compaction.md](compaction.md)
- Approvals / questions: [human-input.md](human-input.md), [approvals.md](approvals.md)
- Testing: [tui-testing.md](tui-testing.md)
- Runtime topology: [async-runtime-architecture.md](async-runtime-architecture.md)
