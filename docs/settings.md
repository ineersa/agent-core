# Hatfield Settings

Hatfield is the application name for the local coding agent configuration system.
It follows the same pattern as `~/.gitconfig` or `.editorconfig`: global
settings in the home directory, overridden by project-local settings.

## Directory layout

```text
~/.hatfield/settings.yaml       # Global user settings
<project>/.hatfield/settings.yaml  # Project-local overrides
.hatfield/sessions/    # Session/run storage (session_id === run_id)
<project>/.hatfield/themes/      # Custom project themes
```

## Precedence

```
built-in defaults  <  ~/.hatfield/settings.yaml  <  <project>/.hatfield/settings.yaml
```

Project settings win over home settings, which win over built-in defaults.

## File format

All settings files use YAML. Example:

```yaml
tui:
    theme: cyberpunk
    theme_paths:
        - '%kernel.project_dir%/config/themes'
        - '.hatfield/themes'
        - '~/.hatfield/themes'

sessions:
    path: '.hatfield/sessions'
```

## Merge rules

- **Associative maps** (with string keys): merge recursively. Higher-priority
  settings override matching keys in lower-priority layers. Unmatched keys are
  preserved.
- **List arrays** (with numeric keys): replace entirely. A list in a
  higher-priority layer completely replaces the list from a lower-priority
  layer.

## Path resolution

Path values support the following placeholders:

| Placeholder | Resolves to |
|---|---|
| `%kernel.project_dir%` | App installation directory |
| `~` | Home directory |
| Relative paths in defaults | App project directory |
| Relative paths in home settings | Home directory |
| Relative paths in project settings | Project working directory |

## Settings keys

### `tui.theme`

The active theme name. Must match a theme registered in one of the
theme search paths.

**Default:** `cyberpunk`

**Built-in themes:** `cyberpunk`, `catppuccin-mocha`, `nord`,
`gruvbox-dark`, `oh-p-dark`, `tokyo-night`

### `tui.theme_paths`

Directories to search for YAML theme files. Ordered by priority:
first match wins. Built-in paths include the app's bundled themes
directory and `.hatfield/themes` for user themes.

**Default:**
```yaml
tui:
    theme_paths:
        - '%kernel.project_dir%/config/themes'
        - '.hatfield/themes'
        - '~/.hatfield/themes'
```

### `sessions.path`

Directory where session/run data is stored. Each session equals
one agent run (session_id === run_id). The directory layout:

```text
.hatfield/sessions/<id>/
  metadata.yaml        # session_id, run_id, parent_id, root_id, etc.
  state.json           # AgentCore RunState hot state cache
  events.jsonl         # AgentCore RunEvent canonical stream
  transcript.jsonl     # TUI transcript projection
  runtime-events.jsonl # Runtime protocol event log
```

**Default:** `.hatfield/sessions`

## Adding a custom theme

1. Create your theme YAML file:

   ```yaml
   # .hatfield/themes/my-theme.yaml
   name: my-theme
   vars:
       accent: "#8abeb7"
   colors:
       accent: accent
       muted: "#718096"
       header: accent
       # ... other tokens from ThemeColor enum
   ```

2. Select it in project settings:

   ```yaml
   # .hatfield/settings.yaml
   tui:
       theme: my-theme
   ```

## `.hatfield/` policy

The `.hatfield/` directory is **tracked** at the top level so that
project settings (`.hatfield/settings.yaml`) and team themes
(`.hatfield/*.yaml`) can be committed and shared.

Only runtime/generated subdirectories are ignored via
`.hatfield/.gitignore`:

```
sessions/
tmp/
cache/
logs/
```

This lets you commit your `.hatfield/settings.yaml` to share
project-specific settings while keeping transient data out of
version control.

The committed `.hatfield/settings.yaml` in this project serves as both
the project settings file and the example. Customize its values for
your workflow.
