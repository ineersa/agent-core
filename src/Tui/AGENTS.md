# TUI module

## Architecture

Single-column layout: header → transcript/history → pending → working/status → extension widgets → editor → footer.

Key types: `FooterDataProvider` / `FooterSegmentProvider` / `FooterBarWidget`. Chrome (header, status, pending, loaded resources, compact header, footer) renders via native Symfony TUI `AbstractWidget`s mounted directly by `ChatScreen`.

Themes: `ThemeColorEnum`, `ThemePalette`, `DefaultTheme`, `ThemeRegistry`, YAML under `config/themes/` (no separate `ThemeLoader` class). External extensions use published `ExtensionApi` TUI contracts (`TuiExtensionContextInterface`) via `BridgeTuiExtensionContext`. Hotkeys: `/hotkeys` catalog in `src/Tui/Command/Hotkey/` (display metadata, not input routing). Full design: `docs/tui-architecture.md`.

`StatusPanelWidget` selects status styling by key; keep `setStatus` text plain.

Dependency direction: TUI may depend on CodingAgent. CodingAgent must not depend on TUI. Prefer direct CodingAgent services for ordinary ownership (for example `PromptTemplateService`, `SkillDiscovery`) rather than inventing Runtime/Contract catalog wrappers solely for Deptrac. Direct TUI dependencies on AgentCore are allowed only where `depfile.yaml` explicitly lists them and usually indicate misplaced ownership. TUI may consume public ExtensionApi TUI contracts but must not depend on concrete extension implementations.
