---
builtin: true
description: Skill discovery, model catalog, /skill commands, and on-demand-only skills.
---

# Skills

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
discovered wins).

## Skill commands

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
| `disable-model-invocation` | No | When truthy, omit from the model catalog; keep `/skill:` and agent `skills` loading |

The parsed value is coerced to boolean. Missing and false values keep the skill
discoverable. Malformed YAML frontmatter falls back to parser defaults
(directory name, empty description, model invocation enabled).

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
