# Agent definition frontmatter

Markdown file with YAML frontmatter + instruction body. Unknown keys are rejected.

## Required

| Field | Notes |
| --- | --- |
| `name` | `[a-z][a-z0-9-]{0,47}` |
| `description` | Shown in `<available_agents>` for enabled agents |

## Tools (optional in YAML; omitted means inherit)

| Field | Default | Notes |
| --- | --- | --- |
| `tools` | inherit parent non-MCP + global MCP when omitted | Child launch resolves the active tool registry, then always strips `subagent` and `fork`, then applies `agents.subagent_excluded_tools` (default `settings`, `hatfield_docs`). Explicit non-empty allowlist recommended for restricted agents. YAML list preferred; comma-separated string is normalized. |

Invalid explicit values: empty list `tools: []`, blank entries, or whitespace-only comma strings.

## Tools and MCP

```yaml
tools:
  - read
  - mcp:context7_resolve   # exact runtime name
  - mcp:websearch_*        # prefix (exactly one terminal *)
  - mcp:*                  # all globally available MCP tools
  - mcp:-                  # suppress every MCP tool (wins over other mcp: selectors)
```

- Omitted `tools`: inherit parent non-MCP tools + MCP from servers marked `availability: all` in `.hatfield/mcp.json`.
- Explicit `tools` lists also inherit `availability: all` MCP tools unless `mcp:-` is present. Specific servers require explicit `mcp:` selectors.
- Raw catalog runtime names without the `mcp:` prefix are stripped from the explicit non-MCP allowlist; globals remain only via inheritance, not raw names.
- Selector grammar is terminal-star-only: no `*` = exact; exactly one trailing `*` = prefix; embedded/multiple `*` are not globs.
- Top-level `mcp` frontmatter is rejected as unknown. Use `tools: [mcp:...]` selectors only.

## Model, skills, extensions, context

| Field | Default | Purpose |
| --- | --- | --- |
| `model` | null | Null/omitted inherits the exact parent execution model at launch; launch fails if neither override nor parent model exists |
| `thinking` | null | `off`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max` |
| `skills` | `[]` | Preload full skill bodies into child `user-context` (singular `skill` rejected) |
| `extensions` | omit = no optional | Optional child extension FQCNs. Effective = `agents.extensions.always_on` ∪ this list. Does **not** inherit optional global `extensions.enabled`. |
| `inheritProjectContext` | true | When true, copies parent `agents_context` (AGENTS.md hierarchy) into child `user-context` (not system prompt). Does not inherit parent skills or agent catalog. |
| `systemPromptMode` | `replace` | `replace` = child harness only (`config/SUBAGENT_SYSTEM.md`); `append` = also rendered `APPEND_SYSTEM.md` + contributors with child tool placeholders |

## Launch policy

| Field | Default | Purpose |
| --- | --- | --- |
| `parallelAllowed` | **true** | Set `false` to disallow parallel `tasks` |

Every valid discovered definition is foreground-launchable. Background launch is not implemented. Frontmatter `disabled` is rejected as unknown — remove an agent by deleting/moving its file.

## Launch versus continue

`subagent` starts a new named child. `agent_resume` continues an existing child using its artifact/run identifier and must not be used to create fresh work. When relevant child context exists, resume it instead of launching a duplicate. Resume is parent-scoped; child runs cannot resume or launch children. Fork children are not resumable.

## Example (project scout)

```yaml
---
name: scout
description: Read-only codebase reconnaissance
tools:
  - read
  - bash
  # availability: all MCP tools are inherited automatically
parallelAllowed: true
inheritProjectContext: true
systemPromptMode: replace
---

Explore read-only. Return dense bullets and file paths.
```

## `agent_retrieve` (parent tool)

```json
{
  "artifact_id": "agent_abc123",
  "agent_run_id": "<child-run-uuid>",
  "mode": "handoff",
  "limit": 20
}
```

- Parent session only. Provide `artifact_id` and/or `agent_run_id` (both must refer to the same artifact when set).
- Modes: `handoff` (default), `metadata`, `events`, `history`, `debug`.
- Additional mode: `handoff_history` lists prior handoffs; pass `handoff_id=<uuid>` to fetch one body. `mode=handoff` remains latest only.
- `limit` accepted range **1–100**, default **20** (events/history row bounds; fixed constants, not settings).
- `history` skips system, user-context, and tool roles; bounded text only (summary chars **240**).
- `debug` returns **relative** artifact paths under the parent session, not absolute filesystem paths.
- Default modes omit raw prompts, full tool output, streaming deltas, API keys, and environment values.
- Path traversal in `artifact_id` is rejected. Cross-parent access is rejected.

## Timeouts and failure

- Timeout: durable deferred-batch `deadlineAt` from `agents.subagent_tool_timeout_seconds` (default 86400, min 60); schedules timeout interruption (`DelayStamp` + `InterruptDeferredSubagentBatchMessage`).
- Timeout/failure/cancel tool results still include `Artifact: …` when available so you can retrieve partial context.
- Cancelled handoffs include bounded partial context only (no raw tool output).
