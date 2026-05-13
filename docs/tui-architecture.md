# TUI Architecture

This document describes the terminal UI architecture for the `ineersa/agent-core` coding agent.

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

## Dependency boundaries

The TUI layers are enforced by Deptrac (`depfile.yaml`):

| Layer | May depend on |
|-------|--------------|
| `TuiApplication` | Runtime Contract, AppConfig, TUI internals, Symfony TUI/Console |
| `TuiTheme` | Symfony TUI, Symfony Console |
| `TuiWidget` | TuiTheme |
| `TuiLayout` | TuiWidget, TuiTheme, individual widget layers |
| Individual widgets | TuiWidget, TuiTheme |

TUI must not import AgentCore internals, HttpKernel, or FrameworkBundle.
