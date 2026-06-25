# Agent Definitions

Agent definitions configure named child-agent roles for your project or user environment. For example, you can define agents named `scout`, `reviewer`, `researcher`, `worker`, or any custom name. Each definition lives in a Markdown file with YAML frontmatter.

Agent definitions, discovery, and catalog are implemented. The model-visible `subagent` tool supports single and parallel foreground child execution with parent-scoped artifact storage. Background launch, TUI controls, and interactive child conversations are future work. See [Foreground subagent tool](#foreground-subagent-tool) below.

## File format

Agent definitions use Markdown with YAML frontmatter:

```markdown
---
name: scout
description: Fast read-only codebase reconnaissance
tools:
  - read
  - ide_find_file
  - ide_search_text
mcp:
  mode: none
inheritProjectContext: true
inheritAgentsMd: true
systemPromptMode: replace
maxDepth: 1
backgroundAllowed: true
foregroundAllowed: true
parallelAllowed: true
---

You are a scout. Explore the codebase read-only and return dense findings...
```

## Fields

| Field | Type | Required | Default | Description |
|---|---|---|---|---|
| `name` | string | yes | — | Unique agent name. Lowercase `[a-z][a-z0-9-]{0,47}`. |
| `description` | string | yes | — | Human-readable description. |
| `tools` | list\<string\> | no | inherit all parent-available tools | Tool allowlist. When omitted, the child inherits all parent-available model-visible tools at launch (pi subagents parity), except `subagent` is always excluded. Explicit non-empty allowlist still restricts tools. YAML list preferred; comma-separated strings are normalized. Invalid: `tools: []`, blank entries, or whitespace-only comma strings. |
| `tools` MCP selectors | `mcp:` entries in `tools` | no | omitted `tools`: inherit global MCP only; explicit `tools` without `mcp:`: no MCP | Use `mcp:*`, `mcp:-`, `mcp:<exposed_name>`, `mcp:<prefix_>` (e.g. `mcp:websearch_search`, `mcp:websearch_`). Runtime tool names stay `{server}_{tool}` with no `mcp` prefix. Legacy `mcp.mode` / `mcp.tools` frontmatter is ignored for child policy when `tools` uses `mcp:` selectors. |
| `mcp.tools` | list\<string\> | no | `[]` | Allowed MCP tools when mode is `specific`. |
| `model` | string\|null | no | `null` | Optional model override. |
| `thinking` | string\|null | no | `null` | Reasoning/thinking override (`off`, `minimal`, `low`, `medium`, `high`, `xhigh`). |
| `skills` | list\<string\> | no | `[]` | Setup skills loaded from start. |
| `inheritProjectContext` | bool | no | `true` | Include project context in child system prompt. |
| `inheritAgentsMd` | bool | no | `true` | Include `AGENTS.md` in child system prompt. |
| `systemPromptMode` | enum | no | `replace` | `replace` = harness only; `append` = also include APPEND_SYSTEM.md (+ contributors) with child placeholders. |
| `maxDepth` | int | no | `1` | Per-agent recursion cap (0–5). |
| `backgroundAllowed` | bool | no | `true` | Whether background launches are allowed. |
| `foregroundAllowed` | bool | no | `true` | Whether foreground launches are allowed. |
| `parallelAllowed` | bool | no | `true` | Whether parallel execution is allowed. Set `false` to opt out. |
| `disabled` | bool | no | `false` | Disable definition without deleting it. |
| `handoffFormat` | string\|null | no | `null` | Optional named handoff template. |

**There is no `type` field.** The `type` field was intentionally removed. It is treated as an unknown field and rejected during parsing.

The body after the closing `---` delimiter is stored as the agent's instructions.

## Discovery

Agent definitions are discovered from the following locations in deterministic order. Higher-numbered layers override lower-numbered layers by agent `name`.

### Precedence (highest wins)

1. **User agents** — `~/.hatfield/agents/*.md`
2. **User agents** — `~/.agents/*.md`
3. **Project agents** — `.hatfield/agents/*.md`
4. **Project agents** — `.agents/*.md`
5. **Configured paths** — `agents.paths` settings (highest precedence)

Each directory is scanned non-recursively for `*.md` files (sorted lexicographically). Configured paths may be a single `.md` file or a directory of `*.md` files.

`.agents/` is a first-class location, not a legacy fallback. It is supported alongside Hatfield-native `.hatfield/agents/`.

### Override behavior

When two definitions have the same `name`, the higher-precedence one wins. An override diagnostic is recorded with winner and loser paths. The overridden definition is not lost — it is still reachable through diagnostics for debugging, but it does not appear in the catalog.

### Disabled definitions

Definitions with `disabled: true` are still loaded into the catalog and appear in `all()` and `disabled()` lookups. They are excluded from `enabled()` and `requireEnabled()` queries. Future launch/execution infrastructure must reject disabled agents.

### Missing paths

Auto-discovery directories that do not exist are silently skipped. Explicit configured paths that do not exist produce an actionable diagnostic with the full path.

### Invalid definitions

Definitions that fail to parse or validate produce a diagnostic and do not appear in the catalog. One invalid file does not abort all discovery.

### Adding custom agents

Create a Markdown file in any discovery location. Example:

```markdown
---
name: my-custom-agent
description: Custom agent for specialized analysis
tools:
  - read
  - ide_search_text
  - semantic-search
mcp:
  mode: none
maxDepth: 1
---

Your custom instructions here.
```

### Per-project agents

For project-specific agents, add `.md` files under `.hatfield/agents/` or `.agents/`. These override user definitions with the same name.

### User-level agents

For personal agents available across projects, add `.md` files under `~/.hatfield/agents/` or `~/.agents/`. These can be overridden by project definitions with the same name.

## Settings

```yaml
agents:
  enabled: true
  paths: []
```

- `agents.enabled` (bool, default `true`): Whether agent discovery is enabled. When `false`, discovery returns an empty catalog.
- `agents.paths` (list of strings, default `[]`): Additional explicit file or directory paths. These have the highest precedence (override all auto-discovery locations).

Paths support standard Hatfield resolution: `~` (home), `%kernel.project_dir%`, and relative paths (resolved against the project CWD).

Example:

```yaml
agents:
  paths:
    - ~/shared/agents/custom-reviewer.md
    - .hatfield/team-agents
```

## Catalog API


On new parent sessions, enabled foreground agent definitions are also injected as a synthetic `user-context` message with `<agents_instructions>` and `<available_agents>` blocks (name and description only — not full agent instructions). The built-in `config/SYSTEM.md` documents this context channel alongside `<available_skills>`.
The catalog (`AgentDefinitionCatalog`) provides:

- `get(string $name): ?AgentDefinitionDTO` — lookup by name
- `require(string $name): AgentDefinitionDTO` — lookup, throws if missing
- `requireEnabled(string $name): AgentDefinitionDTO` — lookup, throws if missing or disabled
- `all(): list<AgentDefinitionDTO>` — all definitions including disabled
- `enabled(): list<AgentDefinitionDTO>` — enabled definitions only
- `disabled(): list<AgentDefinitionDTO>` — disabled definitions only
- `diagnostics(): list<AgentDefinitionDiagnosticDTO>` — discovery diagnostics

## Foreground subagent tool

Parent `subagent` tool calls are routed to a dedicated `agent` Messenger transport
(`messenger:consume agent`), separate from generic `tool` workers. Foreground
subagent orchestration may block its worker while polling child runs; isolating it
prevents starving child agents' `read`/`write`/`shell` calls on the `tool` queue.

The `subagent` tool is registered as a permanent model-visible tool. It supports
**single or parallel foreground mode** with the following JSON schema:

Single mode:

```json
{ "agent": "scout", "task": "Inspect routing config" }
```

Parallel mode (up to `agents.max_agents`, default **8** per tool call):

```json
{
  "tasks": [
    { "agent": "scout", "task": "..." },
    { "agent": "reviewer", "task": "..." }
  ]
}
```

- Use **either** single mode or parallel `tasks`, never both.
- All tasks in one call run concurrently (no `concurrency` argument).
- If more than `agents.max_agents` tasks are requested, the tool fails fast
  before creating artifacts — split work across multiple `subagent` calls.
- Each child agent definition used in parallel mode must set
  `parallelAllowed: true`.
- `background` remains unsupported.

### Execution model

1. **Blocking foreground.** The tool handler blocks the parent LLM until all
   child runs reach a terminal status (Completed, Failed, Cancelled) or the
   tool times out. On **success**, the tool result includes per-child
   `Artifact: <artifact_id>` lines (and bounded handoff summaries) so the
   parent model or user can call `agent_retrieve`. If any child fails, the
   overall tool call fails with a report that still lists every child artifact.
2. **Parent-scoped storage.** Child runs are stored entirely under the parent
   session directory — no top-level session rows or directories are created.
3. **Inline progress.** While the child runs, compact progress status lines
   (agent name, turn number, tool count, last tool name) appear inline in the
   parent's tool result widget. The full child transcript is not duplicated.
4. **Non-interactive.** Child agents cannot ask the human interactively. If a
   child enters `WaitingHuman` (should not happen for non-interactive runs), the execution service
   cancels the child, finalizes the artifact as `Failed`, and
   returns an explanation to the parent LLM.
5. **Cancellation.** If the parent run is cancelled while a child is running,
   the child is cancelled and the artifact is finalized as `Cancelled`.
6. **Timeout.** Foreground `subagent` execution uses an internal poll timeout
   (`agents.subagent_tool_timeout_seconds`, default **900** seconds). This is
   not the generic ToolExecutor timeout (the subagent tool has no ToolExecutor
   cap). A timed-out child is finalized as `Failed`. See [Settings](settings.md).

### Artifact storage layout

Child runs are stored under the parent session directory:

```text
.hatfield/sessions/<parentRunId>/
  artifacts/agents/
    registry.json          ── canonical artifact list (AgentArtifactRegistry)
    <artifactId>/
      metadata.json        ── inspectable sidecar (not read by production paths)
      handoff.md           ── human-readable final handoff
      events.jsonl         ── child RunEvent stream (AgentChildRunEventStore)
      state.json           ── child RunState cache (AgentChildRunStore)
```

- `registry.json` is the canonical source for artifact discovery within a
  parent scope. `metadata.json` is an inspectable sidecar and is never read
  by production load paths.
- Child events and state use the same Canonical JSONL and CAS patterns as
  parent runs, stored under the parent directory via `AgentChildRunEventStore`
  and `AgentChildRunStore`.
- Use the `agent_retrieve` tool (AGENT-06) to load handoffs, metadata, or
  bounded event/history summaries for artifacts in the **current parent session**.

### `agent_retrieve` tool

The model-visible `agent_retrieve` tool reads parent-scoped subagent artifacts
after `subagent` completes (or fails). Copy the `artifact_id` from the
`Artifact: …` line in the `subagent` tool result (success, failure, or cancel).
It does not launch runs and does not replace inline subagent handoffs — use it
when a handoff was truncated, you need status/metadata, or you want a bounded
debug summary.

**Schema (v1):**

```json
{
  "artifact_id": "agent_abc123",
  "agent_run_id": "<child-run-uuid>",
  "mode": "handoff",
  "limit": 20
}
```

- Provide at least one of `artifact_id` or `agent_run_id` (both must refer to the
  same artifact when both are set).
- `mode` (default `handoff`): `handoff`, `metadata`, `events`, `history`, `debug`.
- `limit` (default 20, max 100): bounds `events` and `history` rows.

**Privacy and bounds:**

- Default modes do not expose raw prompts, full message arrays, tool output,
  streaming deltas, API keys, environment values, or full event payloads.
- `history` skips `system`, `user-context`, and `tool` messages; other visible text is truncated.
- `events` lists recent child events with sanitized one-line summaries only.
- `debug` returns **relative** artifact paths under the parent session, not absolute
  filesystem paths.

**Access rules:**

- Retrieval is limited to the **current parent run** (`ToolContext.runId`).
- Unknown identifiers are rejected with actionable errors. Cross-parent `agent_run_id` access is rejected; artifact ids are parent-scoped and random per child run.
- Path traversal in `artifact_id` is rejected.

### Depth and recursion guard (v1)

Nested subagents are not supported:

1. **Parent metadata** — `SubagentExecutionService` reads the parent run's
   `RunStarted` metadata. If `session.kind` is `agent_child`, launch is blocked
   with a non-retryable error.
2. **Tool policy** — the `subagent` tool is excluded from child toolsets via
   `AgentToolPolicyResolver` / `SubagentToolSetResolver` (primary enforcement).
3. **Global disable** — `HATFIELD_AGENTS_DISABLED=1` blocks all subagent launches
   (subprocess/CLI boundary).

Agent definition `maxDepth` remains in the catalog format for forward compatibility
but is not used by the v1 foreground subagent launcher.

### Tool and MCP policy for children

Each child run receives a resolved tool/MCP policy derived from the agent
definition `tools` list (including `mcp:` selectors) plus hard safety rules:

- Omitted `tools`: inherit parent/default non-MCP tools plus MCP tools from
  servers marked `availability: all` in `.hatfield/mcp.json` (exclude
  `availability: specific`).
- Explicit `tools` without any `mcp:` selector: non-MCP allowlist only (no MCP).
- `mcp:` selectors in `tools` resolve to runtime tool names `{server}_{tool}`
  (e.g. `mcp:websearch_search`, `mcp:websearch_`, `mcp:*`, `mcp:-`).
- The `subagent` tool is **always excluded** from child tool lists in v1.
- Parent/main runs only expose MCP tools from `availability: all` servers in
  the active toolset; `availability: specific` tools stay hidden until a child
  opts in via `mcp:` selectors.
- The resolved policy is stored in `RunMetadata::toolsScope` and enforced
  per-run via `SubagentToolSetResolver` intersection. MCP dynamic tools are
  registered per run from the parent session catalog (child runs reuse the
  parent catalog when they have no own `mcp-tools.json`).

### Child prompt construction

The child system prompt is built from:

1. The agent definition's `instructions` (first).
2. A **child-safe harness** from `config/SUBAGENT_SYSTEM.md`: `<available_tools>`
   and `<guidelines>` rendered only for the child's resolved `allowed_tools`,
   plus current date and cwd. This does **not** include parent `<available_agents>`,
   subagent tool guidance, or the full parent `SYSTEM.md`.
3. Parent AGENTS.md / project context when `inheritAgentsMd: true` **or**
   `inheritProjectContext: true`, copied from the parent run's `user-context`
   message with metadata source `agents_context`.
4. When `systemPromptMode: append`, rendered `APPEND_SYSTEM.md` (home + project)
   and extension prompt contributors using **child-safe** placeholders — not
   the parent system prompt.

`systemPromptMode: replace` (default) omits step 4.

Child `user-context` messages (in order):

1. **Preloaded skills** when the agent definition lists `skills` / `skill`.
2. **Non-interactive contract** (artifact ID, allowed tools, foreground worker rules).

The task text follows as the `user` message.

### Known limitations

- **Stale child run detection:** `ChildAwareRunStore::findRunningStaleBefore()` only
  scans parent session store runs, not child agent runs.  Child run liveness
  is managed by the subagent tool's own timeout mechanism.  A future task
  should add child scanning when background/async child modes are introduced.

## Project skill

Tracked quick reference for models and authors: `.hatfield/skills/subagents/SKILL.md` (discovered from `{cwd}/.hatfield/skills` like other Hatfield skills). See also `FRONTMATTER.md` in that directory.

## Current limitations

The following features are **not yet implemented**:

| Feature | Status | Planned |
|---------|--------|---------|
| Background/async launches (`background: true`) | Not implemented | Future |
| `agent_start`, `agent_status` tools | Not implemented | Future |
| `/agents` TUI command | Not implemented | Future |
| Dedicated dock/overlay or structured subagent transcript widget | Not implemented | Future |
| Interactive child HITL, approvals, or questions | Not supported (WaitingHuman → Failed) | Future |
| Parallel execution (`tasks` array) | Implemented (cap: `agents.max_agents`) | — |
| Child artifact retrieval (`agent_retrieve`) | Implemented | — |

## See also

- [Session storage](session-storage.md) — child artifact layout and invariants
- [Settings](settings.md) — `agents.enabled`, `agents.paths`
- [Implementation plan](../.pi/plans/agents-subagents-implementation-plan.md)
