---
builtin: true
description: Agent definitions, discovery, subagent execution, retrieval, and live view.
---

# Agent Definitions

Named child-agent roles live in Markdown files with YAML frontmatter. Discovery and the
model-visible `subagent` tool are implemented for **foreground** single and parallel runs.
Background `subagent` launch is not implemented. The separate model-visible `fork` tool is shipped (see below).

Settings keys: [settings-agents.md](settings-agents.md). Sessions/artifacts: [session-storage.md](session-storage.md).

## File format

```markdown
---
name: scout
description: Fast read-only codebase reconnaissance
tools:
  - read
  - bash
# Optional fields (see table):
# model: provider/model
# thinking: medium
# skills: [my-skill]
# inheritProjectContext: true
# systemPromptMode: replace   # or append
# parallelAllowed: true
# extensions:
#   - Ineersa\HatfieldExt\SomeOptional\SomeOptionalExtension
---

# Scout

System prompt body for the child agent…
```

| Frontmatter field | Meaning | Default |
|---|---|---|
| `name` | Launch key (`^[a-z][a-z0-9-]{0,47}$`) | required |
| `description` | Catalog description | required |
| `tools` | Built-in/MCP allowlist; omit to inherit parent-available tools | omit = inherit |
| `model` | Optional child model override | parent/default |
| `thinking` | `off\|minimal\|low\|medium\|high\|xhigh\|max` | parent/default |
| `skills` | Skill name list (loads full bodies; works for on-demand-only skills too — see [skills.md](skills.md)) | `[]` |
| `extensions` | Optional child extension classes (**not** global `extensions.enabled`) | omit = none optional |
| `inheritProjectContext` | Include project context for the child | `true` |
| `systemPromptMode` | `replace` or `append` body vs parent system prompt | `replace` |
| `parallelAllowed` | Whether the agent may appear in parallel `subagent` tasks | `true` |

MCP entries inside `tools` use `mcp:` selectors — see [mcp.md](mcp.md).

Body after frontmatter is the child system prompt content.

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
- **Always stripped on every child:** `subagent`, `fork`, and `agent_resume` (no nested child launches/resumes).
- Durable timeout: `agents.subagent_tool_timeout_seconds` (default `86400`, min `60`) schedules deferred-batch interruption. This is not a generic ToolExecutor cap.
- Child extensions: `agents.extensions.always_on` ∪ per-agent frontmatter `extensions` only (does **not** use `forks.extensions.*` or parent `extensions.enabled`).

## Launch versus continue

Use `subagent` when no existing child run matches the task and you need a new named-role investigation or review.

Use `agent_resume` when an existing child run in the same parent session already contains useful progress or an incomplete handoff. Resume the existing run instead of launching a duplicate child. Identify the run with the artifact/run identifier returned by the earlier launch; across parent sessions, launch a new child with the prior handoff as context.

For tracked work, record each launched or returned reviewer/subagent identity in the task work log with its role, artifact/run ID, target revision, and scope. `forkRun` metadata is reserved for the implementation fork ID. Keep each role's own handoff format authoritative; task metadata retains only identity/revision/scope plus outcome, validation, and unresolved blockers.

Do not use `subagent` to continue an existing child, and do not use `agent_resume` as a fresh-task launcher. A resumed child keeps its existing identity and session history; provide a focused continuation instruction.

Child runs cannot resume or launch other children. Resume is same-parent-session scoped and rejects fork children; each new fork requires an explicit ownership handoff.

## `agent_resume`

Parent-scoped continuation of an existing terminal child run via `follow_up` on the same child `run_id`.

| Argument | Meaning |
|---|---|
| `artifact_id` | Child artifact id (preferred) |
| `agent_run_id` | Child run UUID (optional alternative / cross-check) |
| `task` | Continuation instruction |
| `tasks` | Parallel list of `{artifact_id,task}` (cap `agents.max_agents`) |

- Eligible statuses: `completed`, `failed`, `cancelled` when the child run/session is still usable.
- Rejects in-flight artifacts (`running`, `needs_clarification`) and fork children.
- Refuses oversized children when latest input tokens are near context limit (`max(75% contextWindow, 200k)`; absolute 200k when window unknown).
- Parent result mirrors `subagent`: single = full latest handoff inline; parallel = bounded summaries.
- Same artifact id is preserved; each finalize appends an immutable handoff under `handoffs/<uuid>.md`.

## `agent_retrieve`

Parent-scoped retrieval for child artifacts:

| Argument | Meaning |
|---|---|
| `artifact_id` | Child artifact id (for example `agent_abc123`) |
| `agent_run_id` | Child run UUID |
| `mode` | `handoff` (default), `metadata`, `events`, `history`, `handoff_history`, `debug` |
| `handoff_id` | Optional handoff uuid for `mode=handoff_history` |
| `limit` | Max rows for events/history (default 20, max 100) |

Provide at least one identifier. Cross-parent retrieval is rejected. Use when parallel summaries were truncated, a child failed/cancelled/timed out, or you need extra detail — not to re-fetch a successful single-mode handoff already returned inline.

`mode=handoff` returns the latest handoff. `mode=handoff_history` lists prior handoffs by default; pass `handoff_id=<uuid>` to fetch one body.

Pre-upgrade sessions that only have mutable `handoff.md` or numeric `handoffs/<n>.md` archives are not migrated; `mode=handoff` / `handoff_history` may be empty for those children (files remain on disk).

## Subagent live view (parent TUI)

- `/agents-live` — picker of known child runs; Enter opens interactive live view for steering / child HITL.
- `/agents-main` (and `Ctrl+\` toggle) — return to the main agent.
- Transcript cards surface live child progress and remind about `/agents-live`.
- Live view is a dedicated navigation mode: most other slash commands require returning to main first.

## `fork` tool

Shipped model tool for **implementation delegation** to an isolated child with
inherited parent conversation context (snapshot → sanitize → compact → deferred
single-child launch). Distinct from `subagent`:

| | `subagent` | `fork` |
|---|---|---|
| Purpose | Named roles, exploration/review, parallel tasks | Isolated implementation handoff |
| Context | Child system prompt from agent Markdown | Inherits compacted parent messages |
| Nested children | Nested `subagent`/`fork` **stripped** on every child | Nested `fork` **rejected**; children also lack `subagent` |
| Extensions | `agents.extensions.always_on` ∪ definition `extensions` | `forks.extensions.always_on` ∪ `forks.extensions.enabled` |
| Model/thinking | Definition `model`/`thinking` when set | explicit args → `forks.model` / `forks.thinking_level` → parent |
| Concurrency | Parallel mode up to `agents.max_agents` | Prefer ≤3 concurrent forks; never same worktree |

Arguments: required `task`; optional `model`, `thinking`. Blocks until the fork completes
and returns a dense handoff through deferred tool completion.

Fork settings details: [settings-agents.md](settings-agents.md).

## Related

- Human questions in children: [human-input.md](human-input.md)
- Approvals: [approvals.md](approvals.md)
- MCP tool availability: [mcp.md](mcp.md)
