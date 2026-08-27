# TUI module

## Architecture

Single-column layout: header → transcript/history → pending → working/status → extension widgets → editor → footer.

Key types: `TuiSlotRegistry`, `TuiExtensionContext` / `SlotBasedTuiExtensionContext`, `FooterDataProvider` / `FooterSegmentProvider` / `FooterBarWidget`. Chrome (header, status, pending, loaded resources, compact header, footer) renders via native Symfony TUI `AbstractWidget`s mounted directly by `ChatScreen`.

Themes: `ThemeColorEnum`, `ThemePalette`, `DefaultTheme`, `ThemeRegistry`, YAML under `config/themes/` (no separate `ThemeLoader` class). Extensions register status/working/footer state and terminal input through `TuiExtensionContext`; they must not mutate widgets directly. Hotkeys: `/hotkeys` catalog in `src/Tui/Command/Hotkey/` (display metadata, not input routing). Full design: `docs/tui-architecture.md`.

`StatusPanelWidget` selects status styling by key; keep `setStatus` text plain.
