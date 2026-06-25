---
name: subagents
description: Foreground Hatfield subagent delegation, agent definition frontmatter, subagent and agent_retrieve tools, and parent-scoped artifacts. Use when defining agents under .hatfield/agents or .agents, calling subagent or agent_retrieve, running parallel scouts/reviewers, or debugging missing available_agents or SafeGuard on child tools.
---

# Subagents (Hatfield)

Hatfield exposes **`subagent`** (launch) and **`agent_retrieve`** (read artifacts) as model-visible tools. Child runs live under the **current parent session** only.

## Quick start

**Single child:**

```json
{ "agent": "scout", "task": "Map how skills are discovered and injected." }
```

**Parallel children** (up to `agents.max_agents`, default 8 — use either single or `tasks`, not both):

```json
{
  "tasks": [
    { "agent": "scout", "task": "Inspect routing." },
    { "agent": "reviewer", "task": "Review the diff." }
  ]
}
```

After a run, copy **`Artifact: agent_<hex>`** from the tool result and call **`agent_retrieve`** when you need the full handoff, metadata, or bounded events/history.

## Where agents live

Discovery precedence (higher wins on name collision):

1. `~/.hatfield/agents/*.md` → `~/.agents/*.md`
2. `.hatfield/agents/*.md` → `.agents/*.md`
3. `agents.paths` in settings

Parent sessions also get **`<available_agents>`** (name + description) in context when `agents.enabled` is true.

## Safety and extensions

- Child runs use the **same project extensions and tool hooks** as the parent (including **SafeGuard** on `read` / `write` / `edit` / `bash` when enabled).
- If project `.hatfield/settings.yaml` sets `extensions.enabled`, that list **replaces** defaults — include **SafeGuard** and any other extensions you still need (see `docs/settings.md`).
- Nested subagents are **not** supported in v1 (`subagent` is stripped from child toolsets).
- Foreground progress in the TUI is **inline status text** today (agent, turn, artifact id), not a dedicated transcript widget.

## Defaults that bite

| Topic | Behavior |
| --- | --- |
| `tools` | Optional in frontmatter; if omitted, child inherits all parent-available tools (except `subagent`). Explicit non-empty allowlist recommended for restricted agents. YAML lists **or** comma-separated strings; `tools: []` or empty entries fail validation. |
| `parallelAllowed` | Defaults to **`true`**. Set `parallelAllowed: false` to block use in parallel `tasks`. |
| `skills` / `skill` | `skill:` merges into `skills`; comma-separated strings are split. |
| MCP `mode: none` | Default. Child MCP sessions are parent-scoped; `all` does not add MCP tools to the child allowlist the way `specific` does. |
| Parallel cap | More than `max_agents` tasks → fail fast; split across multiple `subagent` calls. |

## Workflows

1. **Define agent** — create `.hatfield/agents/<name>.md` with frontmatter + instructions body.
2. **Delegate** — parent calls `subagent` with `agent` + `task` or `tasks`.
3. **Retrieve** — `agent_retrieve` with `artifact_id` and optional `mode` (`handoff`, `metadata`, `events`, `history`, `debug`).

## Deep reference

- Field-by-field frontmatter: [FRONTMATTER.md](FRONTMATTER.md)
- Canonical product docs: [docs/agents.md](../../../docs/agents.md) (repo root)
- Settings: `agents.enabled`, `agents.paths`, `agents.max_agents` in [docs/settings.md](../../../docs/settings.md)
