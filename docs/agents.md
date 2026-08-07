---
description: Agent definitions, discovery, catalog, and foreground subagent tool behavior.
---

# Agent Definitions

Agent definitions configure named child-agent roles for your project or user environment. For example, you can define agents named `scout`, `reviewer`, `researcher`, `worker`, or any custom name. Each definition lives in a Markdown file with YAML frontmatter.

Agent definitions, discovery, and catalog are implemented. The model-visible `subagent` tool supports single and parallel foreground child execution with parent-scoped artifact storage. Background launch is not implemented. Parent TUI live view (`/agents-live`) and child HITL routing exist; see [Foreground subagent tool](#foreground-subagent-tool) and [Subagent live view](#subagent-live-view-parent-tui).

## File format

Agent definitions use Markdown with YAML frontmatter:

```markdown
---
name: scout
description: Fast read-only codebase reconnaissance
tools:
  - read
  - bash
  # availability: all MCP tools are inherited automatically
# Optional child extensions (always_on still applies from settings):
# extensions:
#   - Ineersa\HatfieldExt\SomeOptional\SomeOptionalExtension
inheritProjectContext: true
systemPromptMode: replace
parallelAllowed: true
---

You are a scout. Explore the codebase read-only and return dense findings...
```

## Fields

| Field | Type | Required | Default | Description |
|---|---|---|---|---|
| `name` | string | yes | — | Unique agent name. Lowercase `[a-z][a-z0-9-]{0,47}`. |
| `description` | string | yes | — | Human-readable description. |
| `tools` | list\<string\> | no | inherit all parent-available tools (+ global MCP) | Non-MCP tool allowlist and MCP selectors in one list. Omitted: inherit parent non-MCP tools and MCP from servers with `availability: all` in `.hatfield/mcp.json`. Child launch always strips `subagent` and `fork`, then applies `agents.subagent_excluded_tools` (default `settings`, `hatfield_docs`) for both inherit-all and explicit lists. Explicit lists also inherit all `availability: all` MCP tools unless `mcp:-` is present. Specific servers require explicit `mcp:` selectors. Raw catalog runtime names without `mcp:` are stripped from the explicit non-MCP allowlist; `availability: all` tools remain available through global inheritance, while `availability: specific` tools are not opted in by raw names. MCP selectors: `mcp:*`, `mcp:-`, `mcp:<exposed_name>`, `mcp:<prefix*>` (exactly one terminal `*` is a prefix wildcard; runtime names `{server}_{tool}`). Top-level `mcp` frontmatter is rejected as unknown. Invalid: `tools: []`, blank entries. |
| `model` | string\|null | no | `null` | Optional model override. Null/omitted inherits the exact parent execution model at launch; launch fails if neither override nor parent model exists. |
| `thinking` | string\|null | no | `null` | Reasoning/thinking override (`off`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max`). |
| `skills` | list\<string\> | no | `[]` | Setup skills preloaded into child `user-context`. Singular `skill` is rejected as unknown. |
| `extensions` | list\<string\> | no | omit = no optional extensions | Optional child extension class names (FQCN). Effective allowlist = `agents.extensions.always_on` ∪ this list (stable first-seen dedup). Omitted means only always-on extensions apply — never inherits optional entries from global `extensions.enabled`. Blank/non-string entries are rejected. |
| `inheritProjectContext` | bool | no | `true` | When true, copies parent `agents_context` (resolved AGENTS.md hierarchy) into child `user-context` (not system prompt). Does not inherit parent skills or agent catalog. |
| `systemPromptMode` | enum | no | `replace` | `replace` = harness only; `append` = also include APPEND_SYSTEM.md (+ contributors) with child placeholders. |
| `parallelAllowed` | bool | no | `true` | Whether parallel execution is allowed. Set `false` to opt out. |

**There is no `type` field.** The `type` field was intentionally removed. It is treated as an unknown field and rejected during parsing. Per-definition `disabled` is also rejected as unknown — remove an agent by deleting or moving its file.

The body after the closing `---` delimiter is stored as the agent's instructions.

## Discovery

Agent definitions are discovered from the following locations in deterministic load order. Later layers override earlier layers by agent `name` (lowest to highest).

### Precedence (load order, lowest to highest)

1. **User agents** — `~/.agents/*.md`
2. **Project agents** — `.agents/*.md`
3. **User agents** — `~/.hatfield/agents/*.md`
4. **Project agents** — `.hatfield/agents/*.md`
5. **Configured paths** — `agents.paths` settings (highest precedence)

Generic scope loads before Hatfield-specific scope: a user-level Hatfield definition (`~/.hatfield/agents`) overrides a project-level generic definition (`.agents`).

Each directory is scanned non-recursively for `*.md` files (sorted lexicographically). Configured paths may be a single `.md` file or a directory of `*.md` files.

`.agents/` is a first-class location, not a legacy fallback. It is supported alongside Hatfield-native `.hatfield/agents/`.

### Override behavior

When two definitions have the same `name`, the higher-precedence one wins. An override diagnostic is recorded with winner and loser paths. The overridden definition is not lost — it is still reachable through diagnostics for debugging, but it does not appear in the catalog.

### Removing definitions

Every valid discovered definition is available and launchable. To remove an agent, delete or move its definition file. Per-definition `disabled` frontmatter is rejected as an unknown field.

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
  - bash
  # availability: all MCP tools are inherited automatically
  - semantic-search
---

Your custom instructions here.
```

### Per-project agents

For project-specific agents, prefer `.hatfield/agents/` (highest auto-discovery). Project `.agents/` overrides user `.agents/` but loses to any same-named `~/.hatfield/agents/` or project `.hatfield/agents/` definition.

### User-level agents

For personal agents available across projects, add `.md` files under `~/.hatfield/agents/` (overrides project and user `.agents/`) or `~/.agents/` (lowest auto-discovery). Project `.hatfield/agents/` still overrides user Hatfield definitions.

### Bundled starter agents

Hatfield ships opinionated starter definitions under `src/CodingAgent/Resources/agents/` (`scout`, `reviewer`, `researcher`, `architect`, `browser`). Install them into the user agents directory with:

```bash
hatfield agents:init
```

- Default: if any same-named target already exists under `~/.hatfield/agents/`, the command fails before writing and lists collisions. Rerun with `--force` to overwrite only those bundled filenames.
- `--force` never deletes unrelated user agent files or subdirectories.
- These starters pin explicit models, MCP selectors, and external skills; they require matching providers/skills/MCP servers in the install environment.

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


On new parent sessions, discovered agent definitions are also injected as a synthetic `user-context` message with `<agents_instructions>` and `<available_agents>` blocks (name and description only — not full agent instructions). The built-in `config/SYSTEM.md` documents this context channel alongside `<available_skills>`.
The catalog (`AgentDefinitionCatalog`) provides:

- `get(string $name): ?AgentDefinitionDTO` — lookup by name
- `require(string $name): AgentDefinitionDTO` — lookup, throws if missing
- `all(): list<AgentDefinitionDTO>` — all registered definitions
- `diagnostics(): list<AgentDefinitionDiagnosticDTO>` — discovery diagnostics

## Foreground subagent tool

Parent `subagent` tool calls are routed to a dedicated `agent` Messenger transport
(`messenger:consume agent`), separate from generic `tool` workers. Foreground
subagent orchestration may block its worker while polling child runs; isolating it
prevents starving child agents' `read`/`write`/`shell` calls on the `tool` queue.

The `subagent` tool is registered as a permanent model-visible tool. It supports
**single or parallel foreground mode** with the following JSON schema.

**Decision rule:** batch independent scouts/reviewers in **one** parallel
`tasks` call whenever within `agents.max_agents`. Use single mode only for
exactly one child or work that must be serialized (a later investigation depends
on an earlier result, a follow-up cannot be formed until prior output, or a
deliberate re-review/implementation step after a fix). Separate outer `subagent`
tool calls are serialized by the tool executor; children inside one `tasks`
array run concurrently. Multiple separate single-mode calls for independent work
are valid syntax but an orchestration anti-pattern (they run one after another).

Single mode (one child, or serialized/dependent work):

```json
{ "agent": "scout", "task": "Inspect routing config" }
```

Parallel mode for independent work (up to `agents.max_agents`, default **4**
per tool call) — prefer this shape when launching multiple independent
scouts/reviewers:

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
  before creating artifacts — split across multiple `subagent` calls only for
  **cap overflow** or **true dependencies**, not for routine independent work.
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
3. **Progress and live view.** Compact progress status lines (agent name, turn
   number, tool count, last tool name) appear inline in the parent's tool result
   widget. The parent can also open `/agents-live` for interactive child live
   view (steer/follow-up, ESC cancel). The full child transcript is not
   duplicated into the parent tool result.
4. **Child HITL.** Children are interactive foreground runs: they may enter
   `WaitingHuman` for `ask_human`/SafeGuard. Parent live view answers those
   questions on the child run id; outside live view, unanswered child HITL still
   fails closed. Do not treat children as permanently non-interactive.
5. **Cancellation.** If the parent run is cancelled while a child is running,
   the child is cancelled and the artifact is finalized as `Cancelled`. The
   parent-visible subagent tool error includes the artifact ID, status, and a
   retrieval hint. Cancelled `handoff.md` includes safe partial context (turn
   counts, last activity, bounded assistant text). Use `agent_retrieve` with
   modes `metadata`, `events`, or `history` to recover more detail; cancellation
   remains an error/cancelled tool result, not success.
6. **Timeout.** Foreground `subagent` execution uses a durable deferred-batch
   deadline from `agents.subagent_tool_timeout_seconds` (default **1800**
   seconds; minimum **60**, invalid lower values fail config load). The batch
   schedules a timeout interruption (`DelayStamp` +
   `InterruptDeferredSubagentBatchMessage`). This is not the generic
   ToolExecutor timeout (the subagent tool has no ToolExecutor cap). A timed-out
   child is finalized as `Failed`. See [Settings](settings.md).

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
- `limit` accepted range **1–100**, default **20**: bounds `events` and `history` rows.

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

### Tool and MCP policy for children

Each child run receives a resolved tool/MCP policy derived from the agent
definition `tools` list (including `mcp:` selectors) plus hard safety rules:

- Omitted `tools`: inherit parent/default non-MCP tools plus MCP tools from
  servers marked `availability: all` in `.hatfield/mcp.json` (exclude
  `availability: specific`).
- Explicit `tools` lists also inherit all MCP tools from `availability: all`
  servers unless `mcp:-` is present. An explicit list without selectors therefore
  has mode `inherited_global`; selected `availability: specific` tools are merged
  with those globals when `mcp:` selectors are used.
- Raw catalog runtime names without the `mcp:` prefix are stripped from the
  non-MCP allowlist. Tools from `availability: all` servers remain available
  through global MCP inheritance, while tools from `availability: specific`
  servers are not opted in by raw names. Unrelated non-MCP names remain unchanged.
- `mcp:-` wins over every other selector and suppresses all MCP tools. `mcp:*`
  selects all globally available MCP tools when deny is absent; specific tools
  require an exact or terminal-star selector. When combined with those selectors,
  only their specific matches are added to the globals. Selectors resolve to
  runtime names `{server}_{tool}` (e.g. `mcp:websearch_search`, `mcp:websearch_*`).
  Exactly one terminal `*` is
  a prefix wildcard; a selector with no `*` is always exact, even if it ends with
  `_`. Embedded or multiple `*` characters are not globs.
- `subagent` and `fork` are **always excluded** from child tool lists in v1, then `agents.subagent_excluded_tools` (default `settings`, `hatfield_docs`) is stripped for every child.
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
3. When `systemPromptMode: append`, rendered `APPEND_SYSTEM.md` (home + project)
   and extension prompt contributors using **child-safe** placeholders — not
   the parent system prompt.

`systemPromptMode: replace` (default) omits step 3.

Child `user-context` messages (in order):

1. Parent `agents_context` when `inheritProjectContext: true` (not system prompt).
2. **Preloaded skills** when the agent definition lists `skills`.
3. **Interactive foreground contract** (artifact ID; may use `ask_human` /
   approval flows when necessary).

The task text follows as the `user` message.

### Known limitations

- **Stale child run detection:** `ChildAwareRunStore::findRunningStaleBefore()` only
  scans parent session store runs, not child agent runs.  Child run liveness
  is managed by the subagent tool's own timeout mechanism.  A future task
  should add child scanning when background/async child modes are introduced.

## Built-in skill

Hatfield ships a built-in `subagents` skill under
`src/CodingAgent/Resources/skills/subagents/`. On discovery, each direct
bundled skill directory is mirrored into `~/.hatfield/skills/<name>/` and
then discovered with normal skill precedence (CLI `--skills-path`, then
project/user Hatfield skills, then project/user `.agents/skills`, then
extension-registered skills; first-discovered name wins).
See also `FRONTMATTER.md` next to `SKILL.md` in that skill directory.

## Current limitations

The following features are **not yet implemented**:

| Feature | Status | Planned |
|---------|--------|---------|
| Background/async launches (`background: true`) | Not implemented | Future |
| `agent_start`, `agent_status` tools | Not implemented | Future |
| `/agents` TUI command | Not implemented | Future |
| Dedicated dock/overlay beyond current transcript cards / live view | Partial (cards + `/agents-live`) | Future |
| Interactive child HITL, approvals, or questions | Live view supports child HITL/SafeGuard; non-live WaitingHuman still fails closed | Future |
| Parallel execution (`tasks` array) | Implemented (cap: `agents.max_agents`) | — |
| Child artifact retrieval (`agent_retrieve`) | Implemented | — |

## See also

- [Session storage](session-storage.md) — child artifact layout and invariants
- [Settings](settings.md) — `agents.enabled`, `agents.paths`
- [Implementation plan](../.pi/plans/agents-subagents-implementation-plan.md)


## Subagent live view (parent TUI)

- `/agents-live` opens a picker of known child runs; Enter enters interactive live view for the selected child.
- `/agents-main` and **Ctrl+\** return to the parent transcript. Live view routes plain text to the selected child as steer/follow_up.
- Child HITL (`ask_human`, SafeGuard) questions are labeled and answered on the child run id. ESC cancels the selected child while in live view.
- Picker **d** dismisses finished catalog rows only; active/pending/running/waiting children stay listed until they complete or you cancel them.
- Steering applies at turn boundaries (not interruptive). Auto-delete of completed children is future settings work.
