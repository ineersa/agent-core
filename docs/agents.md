# Agent Definitions

Agent definitions configure named child-agent roles for your project or user environment. For example, you can define agents named `scout`, `reviewer`, `researcher`, `worker`, or any custom name. Each definition lives in a Markdown file with YAML frontmatter.

Agent definitions, discovery, and catalog are implemented. The model-visible `subagent` tool supports single foreground child execution with parent-scoped artifact storage. Parallel, background, TUI controls, and interactive child conversations are future work. See [Foreground subagent tool](#foreground-subagent-tool) below.

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
| `tools` | list\<string\> | yes | — | Explicit tool allowlist. |
| `mcp.mode` | enum | no | `none` | MCP tool policy: `none`, `all`, or `specific`. |
| `mcp.tools` | list\<string\> | no | `[]` | Allowed MCP tools when mode is `specific`. |
| `model` | string\|null | no | `null` | Optional model override. |
| `thinking` | string\|null | no | `null` | Reasoning/thinking override (`off`, `minimal`, `low`, `medium`, `high`, `xhigh`). |
| `skills` | list\<string\> | no | `[]` | Setup skills loaded from start. |
| `inheritProjectContext` | bool | no | `true` | Include project context in child system prompt. |
| `inheritAgentsMd` | bool | no | `true` | Include `AGENTS.md` in child system prompt. |
| `systemPromptMode` | enum | no | `replace` | How child system prompt is composed: `replace` or `append`. |
| `maxDepth` | int | no | `1` | Per-agent recursion cap (0–5). |
| `backgroundAllowed` | bool | no | `true` | Whether background launches are allowed. |
| `foregroundAllowed` | bool | no | `true` | Whether foreground launches are allowed. |
| `parallelAllowed` | bool | no | `false` | Whether parallel execution is allowed. |
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

The catalog (`AgentDefinitionCatalog`) provides:

- `get(string $name): ?AgentDefinitionDTO` — lookup by name
- `require(string $name): AgentDefinitionDTO` — lookup, throws if missing
- `requireEnabled(string $name): AgentDefinitionDTO` — lookup, throws if missing or disabled
- `all(): list<AgentDefinitionDTO>` — all definitions including disabled
- `enabled(): list<AgentDefinitionDTO>` — enabled definitions only
- `disabled(): list<AgentDefinitionDTO>` — disabled definitions only
- `diagnostics(): list<AgentDefinitionDiagnosticDTO>` — discovery diagnostics

## Foreground subagent tool

The `subagent` tool is registered as a permanent model-visible tool. It supports
**single foreground mode only** with the following JSON schema:

```json
{
    "type": "object",
    "properties": {
        "agent": { "type": "string", "description": "Name of the agent definition to launch." },
        "task":  { "type": "string", "description": "The task for the subagent." },
        "model":    { "type": "string", "description": "Optional model override." },
        "thinking": { "type": "string", "description": "Optional thinking/reasoning level override." },
        "context":  { "type": "string", "enum": ["fresh", "fork"], "description": "Execution context. 'fork' is not yet implemented." },
        "cwd":      { "type": "string", "description": "Optional working directory override." }
    },
    "required": ["agent", "task"],
    "additionalProperties": false
}
```

Only `agent` and `task` are required. The `tasks` array, `concurrency`, and
`background` fields are explicitly rejected with actionable error messages
indicating they are not yet implemented.

### Execution model

1. **Blocking foreground.** The tool handler blocks the parent LLM until the
   child run reaches a terminal status (Completed, Failed, Cancelled) or
   requires clarification (NeedsClarification). The tool result is a dense
   handoff text returned to the parent LLM.
2. **Parent-scoped storage.** Child runs are stored entirely under the parent
   session directory — no top-level session rows or directories are created.
3. **Inline progress.** While the child runs, compact progress status lines
   (agent name, turn number, tool count, last tool name) appear inline in the
   parent's tool result widget. The full child transcript is not duplicated.
4. **Non-interactive.** Child agents cannot ask the human interactively. If a
   child enters `WaitingHuman` (HITL/approval state), the execution service
   cancels the child, finalizes the artifact as `NeedsClarification`, and
   returns an explanation to the parent LLM.
5. **Cancellation.** If the parent run is cancelled while a child is running,
   the child is cancelled and the artifact is finalized as `Cancelled`.
6. **Timeout.** A configurable timeout (default 120 seconds) prevents
   indefinite child runs. A timed-out child is finalized as `Failed`.

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
- A dedicated retrieval tool (to query, list, or load child artifacts for the
  parent LLM) is **future work** (planned for AGENT-06).

### Depth and recursion guard

Recursive agent launches are prevented through two mechanisms:

1. **Environment variables** (protect subprocess/CLI boundaries):
   - `HATFIELD_AGENTS_DISABLED=1` — globally blocks all subagent launches.
   - `HATFIELD_AGENT_CHILD` — marker that this process is a child agent.
   - `HATFIELD_AGENT_DEPTH=N` — current nesting depth (parent = 0).
   - `HATFIELD_AGENT_MAX_DEPTH=N` — global per-parent maximum.

2. **Per-definition `maxDepth`** — a definition with `maxDepth: 1` allows
   parent → child but blocks child → grandchild. The effective max depth is
   `min(agentMaxDepth, globalMaxDepth)` when the global env var is set.

If a subagent tool call is blocked by depth limits, the tool returns a
non-retryable error explaining the reason.

### Tool and MCP policy for children

Each child run receives a resolved tool/MCP policy derived from the agent
definition plus hard safety rules:

- The child's allowed tool names come from the definition's `tools` field.
- The `subagent` tool is **always excluded** from child tool lists in v1 to
  prevent recursive agent launches by default.
- The MCP policy (`none`, `all`, or `specific` with explicit tool names)
  is read from the definition's `mcp` block.
- The resolved policy is stored in `RunMetadata::toolsScope` and enforced
  per-run via a scoped `ToolSetResolver`. The global `ToolRegistry` is never
  mutated, so concurrent parent runs with different child policies do not
  leak.

### Child prompt construction

The child system prompt is built from:

1. The agent definition's `instructions` (first, unmodified).
2. AGENTS.md project context when `inheritAgentsMd: true` (rendered via
   `AgentsContextDiscovery` + `AgentsContextRenderer`).
3. The parent system prompt when `systemPromptMode: append`.

A synthetic `user-context` message is prepended with the **non-interactive
contract** (artifact ID, allowed tools, and rules: no interactive questions,
return dense handoff, stop with explanation if information is missing). The
task text follows as the `user` message.

## Current limitations

The following features are **not yet implemented**:

| Feature | Status | Planned |
|---------|--------|---------|
| Parallel execution (`tasks` array, `concurrency`) | Not implemented | AGENT-07 |
| Background/async launches (`background: true`) | Not implemented | Future |
| `agent_start`, `agent_status` tools | Not implemented | Future |
| `/agents` TUI command | Not implemented | Future |
| Dedicated dock/overlay for child agent views | Not implemented | Future |
| Interactive child HITL, approvals, or questions | Not implemented (becomes NeedsClarification) | Future |
| Child artifact retrieval tool | Not implemented | AGENT-06 |

## See also

- [Session storage](session-storage.md) — child artifact layout and invariants
- [Settings](settings.md) — `agents.enabled`, `agents.paths`
- [Implementation plan](../.pi/plans/agents-subagents-implementation-plan.md)
