---
builtin: true
description: Agent definitions, discovery, subagent execution, retrieval, and live view.
---

# Agent Definitions

Named child-agent roles live in Markdown files with YAML frontmatter. Discovery and the
model-visible `subagent` tool are implemented for **foreground** single and parallel runs.
Background launch is not implemented.

Settings keys: [settings-agents.md](settings-agents.md). Sessions/artifacts: [session-storage.md](session-storage.md).

## File format

```markdown
---
name: scout
description: Fast read-only codebase reconnaissance
tools:
  - read
  - bash
# Optional child extensions (always_on still applies):
# extensions:
#   - Ineersa\HatfieldExt\SomeOptional\SomeOptionalExtension
---

# Scout

System prompt body for the child agent…
```

- `name` is the launch key.
- `tools` may list built-in tool names; MCP tools are inherited according to runtime policy.
- Body after frontmatter is the child system prompt content.

## Discovery

Load order (low → high precedence), non-recursive `*.md`:

1. `~/.agents/`
2. `<cwd>/.agents/`
3. `~/.hatfield/agents/`
4. `<cwd>/.hatfield/agents/`
5. `agents.paths`

Bundled starters (`scout`, `reviewer`, `researcher`, `architect`, `browser`) install with:

```bash
hatfield agents:init
```

## Foreground `subagent` tool

- **Single mode:** `agent` + `task` — blocks until the child finishes; success returns full handoff inline.
- **Parallel mode:** `tasks` list — up to `agents.max_agents` children; results are bounded summaries.
- Default child denylist includes `settings` and `hatfield_docs` (`agents.subagent_excluded_tools`). Empty list disables the denylist.
- Durable timeout: `agents.subagent_tool_timeout_seconds` (default `86400`, min `60`) schedules deferred-batch interruption. This is not a generic ToolExecutor cap.
- Child extensions: `always_on` ∪ per-agent frontmatter `extensions` only.

## `agent_retrieve`

Parent-scoped retrieval for child artifacts:

| Argument | Meaning |
|---|---|
| `artifact_id` | Child artifact id (for example `agent_abc123`) |
| `agent_run_id` | Child run UUID |
| `mode` | `handoff` (default), `metadata`, `events`, `history`, `debug` |
| `limit` | Max rows for events/history (default 20, max 100) |

Provide at least one identifier. Cross-parent retrieval is rejected. Use when parallel summaries were truncated, a child failed/cancelled/timed out, or you need extra detail — not to re-fetch a successful single-mode handoff already returned inline.

## Subagent live view (parent TUI)

- `/agents-live` — picker of known child runs; Enter opens interactive live view for steering / child HITL.
- `/agents-main` (and `Ctrl+\` toggle) — return to the main agent.
- Transcript cards surface live child progress and remind about `/agents-live`.
- Live view is a dedicated navigation mode: most other slash commands require returning to main first.

## Related

- Human questions in children: [human-input.md](human-input.md)
- Approvals: [approvals.md](approvals.md)
- MCP tool availability: [mcp.md](mcp.md)
