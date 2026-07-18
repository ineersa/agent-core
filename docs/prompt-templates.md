---
description: Prompt templates, slash invocation, expansion, and discovery.
---

# Prompt Templates

Prompt templates are Markdown files that expand into full user prompts. A
user invokes a template by typing `/name args`, where `name` is the
template filename without `.md` (e.g. `review.md` → `/review`).

## Overview

Templates let you capture recurring prompt patterns — code review
checklists, issue-report templates, architectural questions — as
files you can commit and share. When invoked, placeholders like
`$ARGUMENTS` are replaced with the text after `/name`, and the
expanded prompt is sent to the model exactly as if the user typed it.

## Locations and load order

Templates are discovered from four sources in this order:

1. **Global auto-discovery:** `~/.hatfield/prompts/*.md`
2. **Project auto-discovery:** `<cwd>/.hatfield/prompts/*.md`
3. **Settings paths:** top-level `prompts: []` list in Hatfield settings YAML
4. **CLI paths:** repeatable `--prompt-template <path>`

Auto-discovery directories are scanned **non-recursively** — only `.md`
files directly inside the directory are loaded. Subdirectories are
ignored unless a subdirectory path is listed explicitly in `prompts: []`
or passed via `--prompt-template`.

Missing auto-discovery directories produce no diagnostic — they are
silent. Missing explicit paths (settings `prompts:` entries or CLI
`--prompt-template` values) produce a diagnostic log entry but do not
crash startup.

## Settings

Templates use the top-level `prompts:` key in Hatfield settings:

```yaml
prompts:
  - .hatfield/team-prompts
  - ~/shared/prompts/review.md
```

Because Hatfield list settings **replace entirely** across setting layers,
a project `.hatfield/settings.yaml` `prompts:` list replaces the home
`prompts:` list. Auto-discovery directories (`~/.hatfield/prompts/` and
`<cwd>/.hatfield/prompts/`) are always scanned regardless of the
`prompts:` list value.

The default is an empty list — only auto-discovery directories are
scanned.

## CLI flags

| Flag | Type | Behavior |
|---|---|---|
| `--prompt-template <path>` | repeatable | Add an explicit template file or directory for this invocation. Appended last in load order. |
| `--no-prompt-templates` | boolean (long only) | Disable auto-discovery and settings `prompts: []` paths. CLI `--prompt-template` paths still load. |

There is no `-np` shortcut. Only the long flag `--no-prompt-templates` exists.

## File format

A prompt template is a Markdown file with optional YAML frontmatter:

```markdown
---
description: Review staged git changes
---
Review the staged changes (`git diff --cached`). $ARGUMENTS

Focus on:
- Bugs and logic errors
- Security issues
- Error handling gaps
```

| Field | Required | Behavior |
|---|---|---|
| `description` | No | Used in autocomplete and `/help`. If absent or empty, the first non-empty body line truncated to 60 characters with an ellipsis is used as the description. Unknown frontmatter keys (including `argument-hint`) are **ignored** in the current version. |
| Body | Effectively yes | Text after the closing `---` delimiter. It is the template body that is expanded and sent to the model. An empty body is valid but rarely useful. |

Frontmatter YAML parsing failures produce a diagnostic and the
template loads as empty frontmatter with full original content as body.

## Name canonicalization and collisions

Template names are derived from the filename as:

```
lowercase( basename(path, '.md') )
```

- `review.md` → `/review`
- `my-template.md` → `/my-template`
- `Review.md` and `review.md` both produce the name `review` and collide.

Use **lowercase filenames** for prompt templates to match the TUI
slash-command routing.

### First-wins deduplication

When two templates produce the same lowercase name, the **first loaded**
template wins. Later duplicates are silently skipped with an internal
diagnostic. This is not a fatal error.

### Real slash commands win

Virtual prompt-template commands registered in the TUI are intentionally
lower-priority than real slash commands (like `/help`, `/model`,
`/copy`). If a prompt template filename matches a real command name,
the real command is used and the template is not registered.

## Placeholder syntax

| Placeholder | Replacement |
|---|---|
| `$1`, `$2`, … | Positional args, 1-indexed. Out of range → empty string. `$0` → empty string. |
| `$@` | All args joined by a single space. |
| `$ARGUMENTS` | Same as `$@` (case-sensitive). |
| `${@:N}` | Args from position N onward (1-indexed). `${@:1}` = all args. Start below 1 clamps to 1. |
| `${@:N:L}` | `L` args starting from position N. Length 0 returns empty string. Length past end clamps. |

### Substitution order

Placeholder substitution happens in a fixed order to prevent
interference:

1. Positional placeholders (`$1`, `$2`, …)
2. Slices (`${@:N}`, `${@:N:L}`)
3. `$ARGUMENTS`
4. `$@`

Substitution is **single-pass** — argument values containing
`$1` remain literal after insertion (positional substitution is
already complete). Avoid passing `$@`, `$ARGUMENTS`, or
`${@:N}` text as argument values: they can be re-interpreted by
subsequent `$ARGUMENTS` / `$@` / slice substitution passes.
Templates that expand to text starting with `/other` are **not**
expanded again.

### Edge behavior

- `$100` maps to args[99] or empty string if fewer than 100 args.
- `$1.5` replaces `$1` and leaves `.5` literal.
- `$ARGUMENTS_EXTRA` replaces the `$ARGUMENTS` prefix and keeps `_EXTRA` literal.
- Backslash (`\`) is not an escape character. `\$1` is literal backslash + `$1` replacement.
- Empty quoted arguments (`""`, `''`) are skipped and produce no argument.
- Unclosed quotes consume the rest of the input into the current argument.

### Quote parsing

Both single quotes (`'`) and double quotes (`"`) group whitespace. The
quote character is consumed and not included in the argument value.
Quotes do not nest — the current active quote ends only on the same
quote character.

```
/review "file name" simple   → args: ["file name", "simple"]
/review "it's broken"        → args: ["it's broken"]
```

### Examples

**Template (`review.md`):**
```markdown
---
description: Review staged git changes
---
Review the staged changes (`git diff --cached`). $ARGUMENTS

Focus on $1 and check for security issues in $2.
```

**Invocation:**
```
/review "error handling" "auth.js"
```

**Expanded prompt the model sees:**
```
Review the staged changes (`git diff --cached`). error handling auth.js

Focus on error handling and check for security issues in auth.js.
```

## Transcript behavior

The runtime/model receives the **expanded** prompt. The transcript
follows normal runtime event projection, so it also shows the
expanded prompt. There is no special "show raw `/template args` but
send expanded prompt" mode — what the model sees is what appears
in the transcript.

This is consistent with the runtime event flow: expansion happens at
the in-process runtime boundary before the prompt reaches the
AgentRunner, and the transcript projector records whatever text the
model receives.

## Complete examples

### Global home template

```bash
mkdir -p ~/.hatfield/prompts
```

**`~/.hatfield/prompts/review.md`:**
```markdown
---
description: Review staged git changes
---
Review the staged changes (`git diff --cached`). $ARGUMENTS

Focus on:
- Bugs and logic errors
- Security issues
- Error handling gaps
```

### Project template

```bash
mkdir -p .hatfield/prompts
```

**`.hatfield/prompts/report.md`:**
```markdown
---
description: File a bug report
---

I encountered the following issue: $ARGUMENTS

Please investigate and create a detailed GitHub issue.
```

### Settings paths

**`~/.hatfield/settings.yaml`:**
```yaml
prompts:
  - ~/shared/prompts/review.md
  - ~/shared/prompts/summarize.md
```

**`.hatfield/settings.yaml`:**
```yaml
prompts:
  - .hatfield/team-prompts/sprint-review.md
```

### CLI invocation

```bash
bin/console agent --prompt-template /path/to/custom.md
bin/console agent --prompt-template /path/to/dir --no-prompt-templates
```

## Current limitations

The following features are not implemented in the current version:

- **No built-in templates.** The app does not ship with template files.
- **No package manifest support.** Templates cannot be provided by Composer
  packages or extensions via manifests.
- **No extension-provided templates.** The Extension API does not have a
  template resource loader.
- **No template-specific model/reasoning selection.** Templates cannot
  override the model or reasoning level.
- **No recursive or conditional template language.** Templates are
  flat Markdown with simple placeholder substitution only.
- **No active `argument-hint` display.** The frontmatter `argument-hint`
  key is parsed but ignored in autocomplete and help.
