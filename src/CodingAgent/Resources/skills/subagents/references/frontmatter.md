# Agent definition frontmatter

Use a Markdown file with YAML frontmatter and an instruction body. Unknown fields are rejected.

| Field | Default | Contract |
|---|---|---|
| `name` | required | `[a-z][a-z0-9-]{0,47}` |
| `description` | required | Description in the available-agents catalog |
| `model` | inherited | Exact parent execution model unless overridden; launch requires a model |
| `thinking` | inherited | `off`, `minimal`, `low`, `medium`, `high`, `xhigh`, or `max` |
| `tools` | inherited | Parent non-MCP tools plus global MCP; an explicit list must be non-empty |
| `skills` | `[]` | Skill names whose bodies preload into child context |
| `extensions` | `[]` | Optional extension FQCNs; effective list adds `agents.extensions.always_on`, not the parent's optional extensions |
| `inheritProjectContext` | `true` | Inherits AGENTS.md context, not parent skills or agent catalog |
| `systemPromptMode` | `replace` | `append` also includes rendered APPEND_SYSTEM.md and contributors |
| `parallelAllowed` | `true` | `false` forbids parallel task batches |

Use `skills`, not `skill`. There is no `disabled` or top-level `mcp` field. Remove a definition to remove the agent.

## Tools and MCP

- `tools` accepts a YAML list or comma-separated string. Empty lists and blank entries are invalid.
- Child tools always exclude nested delegation tools and `agents.subagent_excluded_tools`, default `hatfield_docs`.
- Omitted or explicit tools inherit MCP servers marked `availability: all` unless `mcp:-` is present.
- Specific MCP servers require `mcp:` selectors. Raw MCP runtime names in the non-MCP list are stripped.
- Selectors are exact `mcp:context7_resolve`, terminal-star prefix `mcp:websearch_*`, all global `mcp:*`, or deny-all `mcp:-`.
- Deny-all wins. Embedded or multiple stars are not globs.

## Settings

- `agents.max_agents` defaults to 4.
- `agents.subagent_tool_timeout_seconds` defaults to 86400 and must be >=60.
- `agents.extensions.always_on` defaults to SafeGuard. Omitted optional extensions do not disable always-on extensions.
