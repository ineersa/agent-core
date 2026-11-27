---
builtin: true
description: Skill discovery, model catalog, /skill commands, and on-demand-only skills.
---

# Skills

The **Skills** tab in `hatfield setup` lists definitions with their source path
and shadowing information. Use **F2** to switch to the owning scope before
editing frontmatter or deleting a definition. Custom-path and managed definitions
are read-only. Setup preserves the Markdown body and asks for confirmation before
deletion, showing the exact target and any definition that would become active.
Deleting a skill removes its entire directory including assets. Setup does not
materialize built-in skills on a fresh home; they appear here after normal skill
discovery. You can edit them in their owning scope, but normal discovery can overwrite edits. You cannot delete them in
setup.

Skills are Markdown packages (`SKILL.md` + optional references) that teach the
agent specialized workflows. Hatfield discovers them from bundled, project, and
user locations, injects a model-visible catalog of discoverable skills, and
lets users or agent definitions load full skill bodies on demand.

Settings and discovery paths: [settings-agents.md](settings-agents.md).
Agent attachment of skills: [agents.md](agents.md).

## How skills work

1. At startup Hatfield scans skill locations and extracts `name` / `description`.
2. Discoverable skills appear in a `<skills_instructions>` / `<available_skills>`
   block in the initial user-context message.
3. When a task matches, the model can `read` the skill file — or you can force
   load with `/skill:<name>`.
4. Specialist agents can attach skills via frontmatter `skills: [name]` and
   receive the full body even when the skill is hidden from the general catalog.

## Discoverable versus on-demand-only

| Kind | Frontmatter | Model catalog | Autonomous selection | Explicit load |
|---|---|---|---|---|
| Discoverable (default) | omit `disable-model-invocation` | listed when description is non-empty | yes | `/skill:<name>`, CLI `--skills`, agent `skills` |
| On-demand-only | `disable-model-invocation: true` | omitted entirely | no | `/skill:<name>`, CLI `--skills`, agent `skills` |

Use **discoverable** skills for broadly useful workflows the parent model should
pick itself (testing, Castor, packaging).

Use **on-demand-only** for large, narrow, or agent-specific skills that would
spam the general catalog — for example a Datadog logs specialist skill that
only a `datadog-logs` agent should always load.

```markdown
---
name: datadog-logs
description: Query and interpret Datadog logs for this product.
disable-model-invocation: true
---

# Datadog logs

Specialist instructions for Datadog log investigation…
```

```markdown
---
name: datadog-logs
description: Investigate production issues via Datadog logs
skills:
  - datadog-logs
tools:
  - read
  - bash
---

# Datadog logs agent

You investigate production log issues…
```

The agent definition still receives the full skill body through `skills:`.
Users can also type `/skill:datadog-logs` in any session. The parent model does
not see the skill in `<available_skills>` and will not autonomously select it.

Semantics are identical for bundled, project, user, and extension-registered
skills. Existing precedence and name-collision rules are unchanged (first
discovered wins; names compare case-insensitively, so `Foo` and `foo` collide).

## Skill commands

The bundled [code-review](code-review.md) skill also has a `/code-review [request]`
adapter that forwards a free-form request to main with the skill body loaded.

Every discovered skill (including on-demand-only) registers as `/skill:<name>`:

```text
/skill:castor
/skill:datadog-logs
```

The TUI command handler replaces the command with a
`<skill name="…" location="…">` block before runtime dispatch. Command matching
is case-insensitive, and skill commands do not accept arguments. Unknown skill
commands are rejected before dispatch.

## SKILL.md frontmatter

| Field | Required | Description |
|---|---|---|
| `name` | No | Skill name; defaults to the parent directory name |
| `description` | Recommended | Catalog description; empty description excludes the skill from the model-visible catalog |
| `disable-model-invocation` | No | When true, omit from the model catalog; keep `/skill:` and agent `skills` loading |

`name` and `description` must be strings, and `name` must not be blank.
`disable-model-invocation` accepts a boolean, the integers `1` / `0`, or the
strings `true` / `false`, `yes` / `no`, `on` / `off`, `1` / `0` (any case). A
missing key or blank value means false. A SKILL.md with any other value, or an
invalid `name` / `description`, is skipped with a warning log; other skills
still load. Malformed YAML frontmatter falls
back to parser defaults (directory name, empty description, model invocation
enabled).

## Discovery order (high → low)

1. CLI `--skills-path` entries (always scanned)
2. Auto-discovery when not `--no-skills`:
   - `<cwd>/.hatfield/skills`
   - `~/.hatfield/skills` (includes materialized built-ins)
   - `<cwd>/.agents/skills`
   - `~/.agents/skills`
   - Extension `registerSkill()` directories (lowest)

Built-in skills under `src/CodingAgent/Resources/skills` are mirrored into
`~/.hatfield/skills/` even when `--no-skills` suppresses the current-run scan.
