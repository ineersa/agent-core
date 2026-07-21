# Agent definition frontmatter

Markdown file with YAML frontmatter + instruction body. Unknown keys are rejected.

## Required

| Field | Notes |
| --- | --- |
| `name` | `[a-z][a-z0-9-]{0,47}` |
| `description` | Shown in `<available_agents>` for enabled foreground agents |

## Tools (optional in YAML; omitted means inherit all)

| Field | Default | Notes |
| --- | --- | --- |
| `tools` | inherit all parent-available tools when omitted | Child launch resolves the current tool registry snapshot minus `subagent`. Explicit non-empty allowlist is recommended for restricted agents. YAML list preferred; comma-separated string (`read, grep, find`) is normalized to a list. |

Invalid explicit values are rejected: empty list `tools: []`, blank entries, or whitespace-only comma strings.

## Tools and MCP

```yaml
tools:
  - read
  - mcp:context7_resolve   # one exposed MCP tool (runtime name)
  - mcp:websearch_*        # all tools whose exposed names start with websearch_
  - mcp:*                  # all MCP tools including specific servers
  - mcp:-                  # no MCP tools
```

Omitted `tools`: inherit parent non-MCP tools + MCP from servers marked `availability: all` in `.hatfield/mcp.json`.
Parent/main runs only see MCP tools from `availability: all` servers in the active toolset; `availability: specific` servers are hidden until a child agent opts in via `mcp:` selectors in its `tools` list.
Explicit `tools` without any `mcp:` entry: non-MCP allowlist only (no MCP).

- **`subagent`** is never available inside child runs.
- **Child MCP policy** is declared in `tools` using `mcp:` selectors (for example `mcp:websearch_search`, `mcp:websearch_*`, `mcp:*`, `mcp:-`).
- Exactly one terminal `*` is a prefix wildcard (`mcp:websearch_*`). A selector with no `*` is always exact, even if it ends with `_`. Embedded or multiple `*` characters are not globs.
- The legacy top-level `mcp:` frontmatter block (`mcp.mode` / `mcp.tools`) is not used for child tool exposure; declare MCP in `tools` with `mcp:` selectors instead.

## Model and context

| Field | Default | Purpose |
| --- | --- | --- |
| `model` | null | Optional override |
| `thinking` | null | `off`, `minimal`, `low`, `medium`, `high`, `xhigh` |
| `skills` | `[]` | Preload full skill bodies into child `user-context` (`skills_context`) |
| `skill` | — | Alias merged into `skills` (comma-separated OK) |
| `inheritProjectContext` | true | Include parent `agents_context` (`<project_context>`) in child system prompt |
| `inheritAgentsMd` | true | Same as project context today: parent `agents_context` in child system prompt when true |
| `systemPromptMode` | `replace` | `replace` = child harness only; `append` = also APPEND_SYSTEM.md with child tool placeholders |

## Launch policy

| Field | Default | Purpose |
| --- | --- | --- |
| `maxDepth` | 1 | Catalog field; v1 launcher does not nest subagents |
| `foregroundAllowed` | true | Must be true to appear in `<available_agents>` and foreground launch |
| `backgroundAllowed` | true | Background launch not implemented yet |
| `parallelAllowed` | **true** | Set `false` to disallow parallel `tasks` |
| `disabled` | false | Still in catalog; `requireEnabled` fails |
| `handoffFormat` | null | Optional template name |

## Example (project scout)

```yaml
---
name: scout
description: Read-only codebase reconnaissance
tools:
  - read
  - ide_find_file
  - ide_search_text
parallelAllowed: true
inheritAgentsMd: true
systemPromptMode: replace
---

Explore read-only. Return dense bullets and file paths.
```

## `agent_retrieve` (parent tool)

```json
{
  "artifact_id": "agent_abc123",
  "mode": "handoff",
  "limit": 20
}
```

- Parent scope only (`artifact_id` from `subagent` result).
- Modes: `handoff` (default), `metadata`, `events`, `history`, `debug`.
- `history` skips system, user-context, and tool roles; bounded text only.

See [docs/agents.md](../../../docs/agents.md) for storage layout, timeouts, and privacy rules.

