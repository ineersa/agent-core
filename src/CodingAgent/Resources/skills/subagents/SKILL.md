---
name: subagents
description: Foreground Hatfield subagent delegation via the subagent and agent_retrieve tools, agent definition frontmatter, child tool/MCP/extension policy, and parent-scoped artifacts. Use when calling subagent or agent_retrieve, choosing single vs parallel tasks, authoring or debugging agent definitions under .hatfield/agents or .agents, or configuring child tools, MCP selectors, extensions, SafeGuard, skills, context inheritance, or timeouts.
---

# Subagents (Hatfield)

Model-visible tools: **`subagent`** (launch) and **`agent_retrieve`** (read artifacts). Child runs are scoped to the **current parent session**. Nested subagents and background launches are not supported.

## Quick start

**Decision rule:** batch independent scouts/reviewers in **one** `tasks` call within `agents.max_agents` (default **4**). Use single mode for exactly one child or work that must be serialized. Separate outer `subagent` calls run sequentially; tasks inside one `tasks` array run concurrently.

**Single child** (or dependent/serialized work):

```json
{ "agent": "scout", "task": "Map how skills are discovered and injected." }
```

**Independent parallel children** (preferred; max `agents.max_agents` — use either single or `tasks`, not both):

```json
{
  "tasks": [
    { "agent": "scout", "task": "Inspect routing." },
    { "agent": "reviewer", "task": "Review the diff." }
  ]
}
```

Exceeding `max_agents` fails fast — split only for cap overflow or true dependencies, not routine independent work.

After a run, copy **`Artifact: agent_<hex>`** from the tool result. Single-mode success includes the full handoff inline; parallel results are bounded summaries — use **`agent_retrieve`** for complete handoffs. Cancelled/failed/timed-out results still include **`Artifact:`** (and **`Status: cancelled`** when cancelled).

## Where agents live

Discovery load order (lowest → highest; later overrides earlier on name collision):

1. `~/.agents/*.md` → `~/.hatfield/agents/*.md`
2. `.agents/*.md` → `.hatfield/agents/*.md`
3. `agents.paths` in settings (highest)

Directories are scanned non-recursively for `*.md`. Parent sessions inject **`<available_agents>`** (name + description) when `agents.enabled` is true for every valid discovered definition.

## Child safety (always enforced)

- **`subagent` and `fork` are never available inside child runs** (hard strip after tool/MCP merge).
- **`agents.subagent_excluded_tools`** (default: `settings`, `hatfield_docs`) is stripped from every child, inherit-all and explicit lists.
- Child extensions: effective allowlist = `agents.extensions.always_on` ∪ frontmatter `extensions`. Default `always_on` is **SafeGuard**. Omitted frontmatter `extensions` means **only always_on** — children do **not** inherit optional entries from global `extensions.enabled`.
- Nested launch is also blocked when parent `session.kind` is `agent_child`.
- Foreground only: the tool blocks until all children finish. Background launch is not implemented. Every valid discovered definition is launchable; remove one by deleting/moving its file (frontmatter `disabled` is rejected as unknown).

## Defaults that bite

| Topic | Behavior |
| --- | --- |
| `tools` | Optional; omitted → inherit parent non-MCP tools + MCP from servers with `availability: all`. Explicit non-empty allowlist recommended for restricted agents. YAML list or comma-separated string; `tools: []` / blank entries fail validation. |
| `parallelAllowed` | Default **true**. Set `false` to block parallel `tasks`. |
| `skills` | Preloads full skill bodies into child `user-context`. Singular `skill` is unknown/rejected. |
| `inheritProjectContext` | Default **true**. When true, copies parent `agents_context` (AGENTS.md hierarchy) into child `user-context`. Does not inherit parent skills or agent catalog. |
| MCP | `availability: all` servers inherit on every child (including explicit `tools`) unless `mcp:-`. `availability: specific` requires exact/prefix `mcp:` selectors. Raw MCP runtime names without `mcp:` are stripped from non-MCP lists. Top-level `mcp` frontmatter is rejected. |
| Parallel cap | `agents.max_agents` default **4**. |
| Wait timeout | `agents.subagent_tool_timeout_seconds` default **86400** (min **60**; below min fails config load) — durable deferred-batch `deadlineAt` with a scheduled timeout interruption (`DelayStamp` + `InterruptDeferredSubagentBatchMessage`), not ToolExecutor generic timeout. Parent cancel ends waiting children. |

## Child MCP policy

- Omitted or explicit `tools` inherit MCP from `availability: all` servers in `.hatfield/mcp.json`.
- `mcp:` selectors: exact (`mcp:context7_resolve`), one terminal star prefix (`mcp:websearch_*`), `mcp:*` (globals only), `mcp:-` (deny all; wins over other selectors).
- Parent/main active toolset only exposes `availability: all` MCP tools; specific servers stay hidden until a child opts in.

## Workflows

1. **Optional starters** — `hatfield agents:init` copies bundled `scout`/`reviewer`/`researcher`/`architect`/`browser` into `~/.hatfield/agents/` (fails on collisions unless `--force`; opinionated model/MCP/skill pins).
2. **Define agent** — `.hatfield/agents/<name>.md` with frontmatter + instructions.
3. **Delegate** — parent calls `subagent` with `agent`+`task` or `tasks`.
3. **Retrieve** — `agent_retrieve` with `artifact_id` and/or `agent_run_id`, optional `mode` / `limit` (1–100, default 20).

## TUI (parent)

- Transcript cards for subagent results; `/agents-live` picker; Enter enters child live view.
- `/agents-main` and **Ctrl+\\** return to parent. Live view steers/follow-ups to the selected child; ESC cancels that child. Child HITL (including SafeGuard) is answered on the child run id.

## Deep reference

- Field-by-field frontmatter and `agent_retrieve` details: [FRONTMATTER.md](FRONTMATTER.md)
- Settings keys: `agents.enabled`, `agents.paths`, `agents.max_agents`, `agents.subagent_tool_timeout_seconds`, `agents.subagent_excluded_tools`, `agents.extensions.always_on`
