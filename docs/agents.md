---
builtin: true
description: Agent definitions, discovery, subagent execution, retrieval, and live view.
---

# Agent Definitions

Named child-agent roles live in Markdown files with YAML frontmatter. The
model-visible `subagent` tool launches one foreground child per call. Submit independent
calls together for concurrent execution through the normal tool scheduler.
Launches start in the foreground. Use Ctrl+B to background a running child invocation; there is no model-selected background launch flag. The separate model-visible `fork` tool is shipped (see below).

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

MCP entries inside `tools` use `mcp:` selectors — see [mcp.md](mcp.md).

Body after frontmatter is the child system prompt content.

Remove the obsolete `parallelAllowed` field from custom or previously installed agent
definitions. Unknown frontmatter fields are rejected. Independent tool calls use
normal scheduling and file/worktree ownership rules instead of a per-agent batch mode.

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

- Required arguments: `agent` and `task`. Each call waits for one child and returns its full handoff inline unless the response exceeds 50,000 characters. There is no `tasks` argument.
- Submit independent `subagent`, `fork`, Bash, or other tool calls together for concurrent execution, subject to available worker capacity and configured tool parallelism. Wait for prerequisites before submitting dependent work.
- Concurrent writers must respect file and worktree ownership. Never assign concurrent writers to the same worktree.
- Responses over 50,000 characters omit inline handoffs and return a notice with artifact references. The full handoffs remain available through `agent_retrieve`.
- Default child denylist includes `hatfield_docs` (`agents.subagent_excluded_tools`). Empty list disables the denylist.
- **Always stripped on every child:** `subagent`, `fork`, and `agent_resume` (no nested child launches/resumes), plus parent-only `agent_steer`.
- Durable timeout: `agents.subagent_tool_timeout_seconds` (default `86400`, min `60`) schedules deferred-batch interruption. This is not a generic ToolExecutor cap.
- Child extensions: `agents.extensions.always_on` ∪ per-agent frontmatter `extensions` only (does **not** use `forks.extensions.*` or parent `extensions.enabled`).

## Launch versus continue

Use `subagent` when no existing child run matches the task and you need a new named-role investigation or review.

Use `agent_resume` when an existing child run in the same parent session already contains useful progress or an incomplete handoff. Resume the existing run instead of launching a duplicate child. Identify the run with the artifact/run identifier returned by the earlier launch; across parent sessions, launch a new child with the prior handoff as context.

Do not use `subagent` to continue an existing child, and do not use `agent_resume` as a fresh-task launcher. A resumed child keeps its existing identity and session history; provide a focused continuation instruction.

Child runs cannot resume or launch other children. Resume is same-parent-session scoped. Eligible forks may be resumed with `agent_resume`.

## Active-child communication

| Tool | Arguments | Recipient |
|---|---|---|
| `agent_steer` | `text` and exactly one of `artifact_id` or `agent_run_id` | One active direct child in the current parent session |
| `notify_parent` | `text` | The child's runtime-recorded parent; no destination argument |

Both tools accept nonblank UTF-8 text up to 8,192 bytes. They return a persisted
source-submission receipt, not confirmation that the recipient received, read, or
followed the text. There are no subjects, attachments, urgency flags, inbox views,
or tracked question/reply operations.

Use `agent_steer` to amend a running subagent or fork's task or provide new facts.
It also targets the current active `agent_resume` activity. It cannot resume a
terminal child, and delayed guidance cannot become instructions for a later resume.
Use `agent_resume` explicitly for terminal children instead.

Children may use `notify_parent` for intermediate findings or a change that matters
to the parent. This does not finish the child or release the parent's foreground
tool wait. The child's final handoff still arrives through the existing completion
path. A current running background child's notice may continue a normally completed
main, but never a cancelled or failed main.

Messages enter model context as attributed agent-authored text at a safe boundary.
They do not interrupt provider requests, tools, human approvals, or compaction.
They cannot approve a request, change permissions, cancel work, or act as human
instructions. Human escalation still uses `ask_human`.

`agent_steer` is parent-only. `notify_parent` is available to children only when
their existing tool policy allows it. An explicit child `tools` list must include
`notify_parent`; omitted tools inherit it when otherwise allowed. Exclusions and
approval policy still apply. Neither tool works inside `code_mode`.

Communication uses ordinary tool workers. If all eligible workers are occupied,
submission waits; messaging has no reserved worker or preemption. Backgrounding a
child wait does not create more execution capacity.

## `agent_resume`

Parent-scoped continuation of an existing terminal child run via `follow_up` on the same child `run_id`.

| Argument | Meaning |
|---|---|
| `artifact_id` | Child artifact id (preferred) |
| `agent_run_id` | Child run UUID (optional alternative / cross-check) |
| `task` | Required continuation instruction |

- Eligible statuses: `completed`, `failed`, `cancelled` when the child run/session is still usable.
- Rejects in-flight artifacts (`running`, `needs_clarification`). Same-parent-current-lifetime forks are resumable; foreign-session, previous-lifetime, and otherwise ineligible targets still fail with actionable errors.
- Refuses oversized children when latest input tokens are near context limit (`min(75% contextWindow, 200k)`; 200k when window unknown).
- Each call resumes one child and returns its full handoff inline. Submit independent calls together for concurrent execution. Responses over 50,000 characters return a notice and artifact references instead.
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

Provide at least one identifier. Cross-parent retrieval is rejected. Use when a handoff was omitted, a child failed/cancelled/timed out, or you need extra detail — not to re-fetch a successful handoff already returned inline.

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
single-child launch). Distinct from `subagent`. Transport note: `subagent` and
`agent_resume` route to the `agent` Messenger transport; `fork` stays on the
default `tool` transport (see unmarked repository `docs/tool-execution.md`).

| | `subagent` | `fork` |
|---|---|---|
| Purpose | Named roles, exploration/review, parallel tasks | Isolated implementation handoff |
| Context | Child system prompt from agent Markdown | Inherits compacted parent messages |
| Nested children | Nested `subagent`/`fork` **stripped** on every child | Nested `fork` **rejected**; children also lack `subagent` |
| Extensions | `agents.extensions.always_on` ∪ definition `extensions` | `forks.extensions.always_on` ∪ `forks.extensions.enabled` |
| Model/thinking | Definition `model`/`thinking` when set | explicit args → `forks.model` / `forks.thinking_level` → parent |
| Concurrency | Independent single-child tool calls use normal parallel scheduling | Prefer ≤3 concurrent forks; never same worktree |

Arguments: required `task`; optional `model`, `thinking`. Blocks until the fork completes
and returns a dense handoff through deferred tool completion.

To continue an eligible terminal fork in the same parent session, use `agent_resume` with the fork artifact/run id and a focused follow-up. Do not launch a duplicate `fork` when the existing child context still applies.

Fork settings details: [settings-agents.md](settings-agents.md).

## Related

- Human questions in children: [human-input.md](human-input.md)
- Approvals: [approvals.md](approvals.md)
- MCP tool availability: [mcp.md](mcp.md)
