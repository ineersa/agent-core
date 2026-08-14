# TUI Architecture

Terminal UI for Hatfield interactive sessions (`src/Tui`).

## Layout (single column)

1. Header
2. Transcript / history
3. Pending / human-input affordances
4. Working / status lines
5. Extension widgets
6. Editor
7. Footer

## Startup

`AgentCommand` resolves an `AgentSessionClient`, then `InteractiveMode::run(...)` mounts `ChatScreen` with theme, session state, and listener registrars (session commands, compaction, hotkeys, extensions).

## Key types

| Type | Role |
|---|---|
| `TuiWidget` | Renderable UI piece |
| `TuiSlotRegistry` / `TuiExtensionContext` | Extension-owned slots without mutating core widgets directly |
| `FooterDataProvider` / `FooterSegmentProvider` / `FooterBarWidget` | Footer composition |
| `ThemeRegistry` / YAML themes under `config/themes/` | Theming |

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

TUI sends commands and consumes events through `AgentSessionClient` + runtime protocol DTOs. Do not reach into AgentCore stores from widgets.

## Extensions

Generic TUI extension contracts live in `Ineersa\Hatfield\ExtensionApi\Tui\*` and may depend on **Symfony TUI** public widgets only. Feature UX belongs in extension packages.

## Related

- Sessions: [session-storage.md](session-storage.md)
- Compaction: [compaction.md](compaction.md)
- Approvals / questions: [human-input.md](human-input.md), [approvals.md](approvals.md)
- Testing: [tui-testing.md](tui-testing.md)
