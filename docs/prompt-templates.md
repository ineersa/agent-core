---
builtin: true
description: Create reusable Markdown prompts, invoke slash templates, and control discovery and argument expansion.
---

# Prompt templates

Save reusable user prompts as Markdown. `review.md` becomes `/review`; arguments
expand into its body, which is sent to the model and shown in the transcript.

## Create a template

Put this in `.hatfield/prompts/review.md`:

```markdown
---
description: Review staged changes
---
Review `git diff --cached`. Focus on $1. Additional instructions: ${@:2}
```

Invoke `/review "error handling" "check authorization"`. Quotes group argument
whitespace and are removed. Empty quoted arguments are skipped; unclosed quotes
consume the remaining input. Backslash is not an escape character.

## Discovery and precedence

Templates load in this order; **first duplicate name wins**:

1. `~/.hatfield/prompts/*.md`
2. `<cwd>/.hatfield/prompts/*.md`
3. Top-level settings `prompts:` files/directories
4. Repeatable CLI `--prompt-template` files/directories

Directories are nonrecursive. Names are lowercased filename stems. Real slash
commands take precedence over templates, so avoid names such as `help` or `model`.
Unlike agent definitions, later prompt layers do not override earlier duplicates.

```yaml
prompts:
  - .hatfield/team-prompts
  - ~/shared/prompts/review.md
```

Settings lists replace across layers: project `prompts:` replaces user `prompts:`,
not the auto-discovery directories. Missing auto-discovery directories are ignored;
missing explicit paths log a diagnostic without failing startup.

```bash
hatfield agent --prompt-template /path/to/prompts
hatfield agent --no-prompt-templates --prompt-template /path/to/review.md
```

`--no-prompt-templates` suppresses auto-discovery and settings paths, not explicit
CLI paths. It has no short alias.

## Placeholders

| Syntax | Expansion |
|---|---|
| `$1`, `$2`, … | Positional argument; missing or `$0` becomes empty |
| `$@`, `$ARGUMENTS` | All arguments joined by single spaces; case-sensitive |
| `${@:N}` | Arguments from position N, 1-based |
| `${@:N:L}` | L arguments from N; zero length is empty |

Substitution order is positional, slices, `$ARGUMENTS`, then `$@`. Avoid passing
placeholder-like text as argument values: a later pass can reinterpret it.
`$ARGUMENTS_EXTRA` replaces the recognized prefix and retains `_EXTRA`.
An expanded `/other` is not recursively invoked as another template.

## Frontmatter and limits

Only `description` affects help/completion; otherwise the first nonempty body
line supplies a short description. Unknown keys, including `argument-hint`, do
nothing. Malformed YAML logs a diagnostic and leaves the original content as body.

Templates are flat text substitution: no conditionals, model/reasoning override,
recursive expansion or extension resource-manifest loader. No templates ship by
default. For specialized reusable workflows use [skills](skills.md); for a child
role use [agents](agents.md).
