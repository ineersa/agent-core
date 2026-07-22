# Agents and Subagents Implementation Plan

## Status

Planning document for adding **native agents/subagents** to Hatfield/agent-core.

This plan is intentionally self-contained. It assumes the reader has no context from prior design discussions.

The design is inspired by Pi's subagents package (`/home/ineersa/claw/my-pi/packages/subagents`) but must be implemented natively in Hatfield using the existing AgentCore run pipeline, runtime protocol, session storage, tool registry, and TUI architecture.

**AGENT-03 POC result:** the current `ChatScreen::insertOverlayBeforeEditor()` / `insertOverlayAfterEditor()` APIs are insertion slots, not real floating/modal overlays. Do not build the first production subagent UX around fake overlays.

**Pi subagents reconnaissance result:** Pi's subagents package (`/home/ineersa/claw/my-pi/packages/subagents`) uses inline chat transcript tool rendering, not overlays, panes, docks, or a dedicated agent view. Live progress is pushed through the normal tool update callback and `Ctrl+O` expands the inline tool widget. This is acceptable for Hatfield v1 and substantially simpler than a separate agent control surface.

**Simplification decision:** first-production subagents are non-interactive foreground workers implemented as a normal `subagent` tool call. They do not support background launch, mid-run steering, live user input, child HITL questions, nested approval flows, or interactive child conversations.

This is **not** a fork implementation plan. Fork is related and should eventually reuse parts of this infrastructure, but it is explicitly out of scope for the initial agents/subagents milestone.

---

## 1. Goal

Add a first-class agent/subagent system so a Hatfield session can delegate focused work to specialized child agents.

The system must support:

- project/user agent definitions with markdown frontmatter under `.agents/` and `.hatfield/agents/`;
- named agent roles such as `scout`, `reviewer`, `researcher`, and `worker` as examples users can define, not bundled builtins;
- non-interactive foreground child runs that receive one task, work independently, and return a result/artifact through the tool result;
- live progress rendered inline in the chat transcript tool widget;
- parallel execution inside the same `subagent` tool;
- per-agent tool policy and future per-agent MCP policy;
- setup skills loaded from start;
- explicit `AGENTS.md` inheritance controls;
- a session-scoped artifacts registry for handoffs/results/history;
- explicit artifact/history retrieval for failed or completed child runs;
- recursion prevention with both environment variables and persisted metadata.

If a child lacks information, it should complete with a clear `needs_clarification`/failed artifact containing questions for the parent, not pause mid-run and ask the user interactively.

Normal agents/subagents are **parent-scoped child runs**. They must not appear as normal user sessions in `/sessions`. The parent session discovers them through the inline tool result and parent-scoped agent/artifact registry.

---

## 2. Non-goals for the first agents milestone

Do not include these in the initial agents implementation:

- Fork implementation.
- One tmux pane per subagent.
- Agent-to-agent recursive delegation by default.
- Global agent session listing in `/sessions`.
- MCP implementation itself.
- `ask_human` implementation itself.
- Mid-run steering or follow-up instructions to a child.
- Live user input routed into a child run.
- Child HITL questions or nested approval flows.
- Interactive child conversations.
- Foreground behavior where a child asks the parent/user questions mid-run.
- Background/async subagent launch in v1.
- Backgrounding an already-running subagent mid-run.
- Dedicated agent dock/view in v1.
- Compact global agent status in v1 unless it falls out naturally from inline tool rendering.
- Full autonomous inter-agent conversation/intercom.
- Floating/modal overlay control plane built on current `insertOverlay*` APIs.
- Multi-machine/distributed agent execution.
- Web/HTTP server endpoints.

Important future hooks should be designed now, but implementation should stay focused.

---

## 3. Required prerequisites

Agents should be implemented after the agent definition/catalog work and after enough runtime/session infrastructure exists for parent-scoped child artifacts. The MCP track remains a prerequisite only for per-agent MCP tool policy.

### 3.1 QH / `ask_human` is not a v1 prerequisite

The first production agent milestone must **not** route live questions, approvals, or human input into child runs. QH/`ask_human` remains important for the parent session, but child-agent HITL is out of scope until there is a clear UX that explains why a child is asking, what context it has, and what answering will do.

If a child run reaches a condition that would require human input or approval, v1 should stop the child and write a clear failed/needs-clarification artifact instead of entering `WaitingHuman`.

Relevant current code remains useful background, but should not become a v1 child-agent dependency:

- `src/AgentCore/Application/Pipeline/ToolCallResultHandler.php`
  - transitions runs to `WaitingHuman` when a tool result contains an interrupt payload.
- `src/AgentCore/Application/Pipeline/ToolCallExtractor.php`
  - extracts interrupt payload details from tool results.
- `src/AgentCore/Application/Pipeline/ApplyCommandHandler.php`
  - processes `HumanResponse` commands and resumes the run.
- `src/AgentCore/Domain/Run/RunStatus.php`
  - already has `WaitingHuman`.
- `src/Tui/Question/QuestionCoordinator.php` and `src/Tui/Question/QuestionController.php`
  - parent-session question infrastructure, not v1 child-agent control UX.
- `docs/hitl-and-approvals.md`
  - parent-session SafeGuard/HITL approval architecture.

### 3.2 MCP prerequisite

Agents need per-agent MCP tool policy. The agent system should not implement MCP itself. Complete the MCP task chain first:

```text
tasks/TODO/mcp-01-config-sdk-boundary.md
tasks/TODO/mcp-02-broker-transport-consumer.md
tasks/TODO/mcp-03-connection-discovery-catalog.md
tasks/TODO/mcp-04-dynamic-tool-registration.md
tasks/TODO/mcp-05-tool-invocation-request-reply.md
tasks/TODO/mcp-06-hardening-docs-validation.md
```

Relevant plan:

- `.pi/plans/mcp-client-implementation-plan.md`

Agents should later consume the MCP catalog/tool registration infrastructure and filter it per child run. Do not create a parallel MCP system for agents.

---

## 4. Key existing architecture to reuse

### 4.1 AgentCore run pipeline

The existing agent loop is a command-bus state machine.

Key files:

- `src/AgentCore/Application/Pipeline/AgentRunner.php`
  - entry point for `start()`, `continue()`, `steer()`, `followUp()`, `cancel()`, `answerHuman()`.
- `src/AgentCore/Application/Pipeline/RunOrchestrator.php`
  - Messenger message handlers.
- `src/AgentCore/Application/Pipeline/RunMessageProcessor.php`
  - run locking, idempotency, CAS retry, event persistence.
- `src/AgentCore/Application/Pipeline/StartRunHandler.php`
  - initial run setup.
- `src/AgentCore/Application/Pipeline/AdvanceRunHandler.php`
  - turn advancement and LLM step dispatch.
- `src/AgentCore/Application/Pipeline/LlmStepResultHandler.php`
  - assistant message and tool call extraction.
- `src/AgentCore/Application/Pipeline/ToolCallResultHandler.php`
  - tool result handling and HITL waiting.
- `src/AgentCore/Application/Pipeline/ApplyCommandHandler.php`
  - steer/follow-up/cancel/human-response commands.

The agents system should create parent-scoped child runs that use this existing pipeline. Do not invent a separate agent loop.

### 4.2 Runtime boundary

The TUI talks to runtime through `AgentSessionClient` and runtime protocol DTOs.

Key files:

- `src/CodingAgent/Runtime/Contract/AgentSessionClient.php`
- `src/CodingAgent/Runtime/Contract/StartRunRequest.php`
- `src/CodingAgent/Runtime/Contract/UserCommand.php`
- `src/CodingAgent/Runtime/InProcess/InProcessAgentSessionClient.php`
- `src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php`
- `src/CodingAgent/Runtime/Protocol/RuntimeEvent.php`
- `src/CodingAgent/Runtime/Protocol/RuntimeEventTypeEnum.php`
- `src/CodingAgent/Runtime/Protocol/RuntimeEventTranslator.php`

Agents should be launched and controlled through app-layer services that ultimately use the existing run pipeline. TUI support should consume runtime protocol events and app-layer registry state, not AgentCore internals.

### 4.3 Session and event storage

Canonical session data is stored in `.hatfield/sessions/<run_id>/events.jsonl`.

Key files:

- `src/CodingAgent/Session/HatfieldSessionStore.php`
- `src/CodingAgent/Session/SessionRunStore.php`
- `src/CodingAgent/Session/SessionRunEventStore.php`
- `src/CodingAgent/Entity/HatfieldSession.php`
- `docs/session-storage.md`

Child agent runs must use normal event storage internally so they can be replayed/debugged by infrastructure. However, they must be marked hidden so they do not appear as normal sessions in `/sessions`.

### 4.4 Tool registry and execution

Agents will be exposed to the LLM through one or more Hatfield tools.

Key files:

- `src/CodingAgent/Tool/HatfieldToolProviderInterface.php`
- `src/CodingAgent/Tool/ToolRegistry.php`
- `src/CodingAgent/Tool/RegistryBackedToolbox.php`
- `src/AgentCore/Application/Handler/ToolExecutor.php`

Agent tools must participate in the same tool execution pipeline as all other tools so SafeGuard, output capping, runtime projection, and tool result handling remain consistent.

### 4.5 HITL and SafeGuard approval flow

SafeGuard is the reference architecture for approvals:

```text
tool call hook returns RequireApproval
  → ToolExecutor returns interrupt result
  → ToolCallResultHandler transitions run to WaitingHuman
  → RuntimeEventTranslator emits human_input.requested
  → TUI QuestionCoordinator/QuestionController asks the user
  → answer_human command returns response
  → ApplyCommandHandler resumes the run
  → extension approval hook receives the answer
```

Key files:

- `src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardToolCallHook.php`
- `src/CodingAgent/Extension/ExtensionToolHookEventSubscriber.php`
- `src/CodingAgent/Extension/ExtensionApprovalAnswerSubscriber.php`
- `src/CodingAgent/Extension/ExtensionHookRegistry.php`
- `src/CodingAgent/ExtensionApi/ToolCallHookInterface.php`
- `src/CodingAgent/ExtensionApi/ApprovalAnswerHookInterface.php`

Child agent tool calls must still pass through normal tool execution and extension hooks, but v1 child agents must not pause for approvals or ask the human. Tool policy should avoid tools likely to require approval. If SafeGuard or a future hook returns `RequireApproval` for a child run, v1 should treat that as an unsupported child-interaction condition: mark the child failed/needs-attention in the registry, write an artifact explaining the blocked action, and let the parent decide whether to continue manually or launch a new child with different constraints.

### 4.6 Background bash analogy

The background bash work is a useful reference for completion notifications and runtime projection, but agents should not copy every bash detail.

Relevant tasks:

- `tasks/DONE/tools-09-bash-tool-background.md`
- `tasks/DONE/tools-09b-runtime-tool-question-bridge.md`

Relevant code areas:

- `src/CodingAgent/Tool/BackgroundProcess/`
- `src/CodingAgent/Tool/ToolQuestion/`
- `src/CodingAgent/Runtime/Protocol/RuntimeEventTypeEnum.php`
- `src/Tui/Listener/TickPollListener.php`

For the production agents implementation:

- the `subagent` tool call remains foreground/blocking from the parent LLM perspective;
- live child progress updates render through the normal tool update path;
- child completion is the normal tool result;
- no user question asking whether to background;
- no mid-run background/foreground handoff in the first production version;
- no shell process question bridge reuse unless needed later.

### 4.7 TUI layout and overlay architecture

The TUI is currently a single-column chat screen with widgets and insertion slots. AGENT-03 proved that the current `insertOverlayBeforeEditor()` and `insertOverlayAfterEditor()` APIs insert widgets into the normal layout; they do not provide a real floating/modal overlay surface.

Key files:

- `src/Tui/Screen/ChatScreen.php`
- `src/Tui/Layout/ChatLayout.php`
- `src/Tui/Layout/TuiSlotRegistry.php`
- `src/Tui/Widget/TuiWidget.php`
- `src/Tui/Widget/WidgetPlacementEnum.php`
- `src/Tui/Picker/PickerOverlay.php`
- `src/Tui/Picker/SessionPickerController.php`
- `src/Tui/Runtime/TuiSessionState.php`
- `src/Tui/Runtime/RuntimeEventPoller.php`
- `src/Tui/Listener/TickPollListener.php`

The initial TUI should follow Pi's simpler subagent UX: render live progress and final results inline inside the normal chat transcript tool widget. Do not implement a dedicated agent view/dock for v1.

---

## 5. Core design decisions

### 5.1 Parent-scoped child runs

Each subagent execution is a normal AgentCore-style run internally, but it is scoped under the parent session instead of becoming a normal top-level Hatfield session.

Recommended v1 storage layout:

```text
.hatfield/sessions/<parent_run_id>/artifacts/agents/
  registry.json
  <child_artifact_id>/
    events.jsonl
    state.json
    handoff.md
    metadata.json
```

A child run has:

- its own child run id;
- parent run id metadata;
- root run id metadata;
- agent name metadata;
- depth metadata;
- artifact id metadata;
- resolved tool/MCP policy metadata;
- child events stored under the parent session's `artifacts/agents/<child_artifact_id>/events.jsonl` path.

Do **not** create top-level `.hatfield/sessions/<child_run_id>/` directories for v1 subagents. That pollutes normal session storage/listing and forces hidden-session DB complexity before the UX is proven.

The child run must not appear in `/sessions` or ordinary session pickers. It should appear only in:

- the inline `subagent` tool result/progress widget;
- session-scoped agent registry;
- session-scoped artifact registry/history retrieval;
- logs/debug tooling when explicitly requested.

### 5.2 Execution mode: foreground inline tool result

The first production implementation should be foreground/blocking from the parent LLM perspective, matching Pi subagents.

```text
parent LLM calls subagent tool
  → SubagentTool creates parent-scoped child run/artifact entry
  → child works independently while the tool call remains active
  → child events/progress update the inline tool widget
  → child completes/fails/cancels
  → registry and handoff/history artifacts are finalized
  → tool returns final result/handoff to the parent LLM
```

There is no v1 background mode, completion notification, mid-run steering, child question flow, nested approval flow, or child conversation. If the child lacks context, it writes a failed/needs-clarification handoff and exits; that handoff is returned by the tool and remains retrievable from the registry.

Cancellation should use the normal in-flight tool/run cancellation path (for example Esc in the TUI), not a separate agent control plane.

### 5.3 Registry and explicit retrieval

The inline tool result is the primary user/LLM delivery mechanism, but v1 should still keep a file-backed parent-scoped registry and retrieval API for failed runs, long handoffs, event/history inspection, resume, and debugging.

The source of truth for completion/progress is:

1. the active tool result/progress snapshots while the tool is running;
2. the child run's own parent-scoped event stream/history; and
3. the parent session's file-backed agent/artifact registry for retrieval.

Do **not** duplicate every child event into the parent transcript. The running tool widget can show compact live progress snapshots. `agent_retrieve` can later read the registry/artifact directory and return handoff, metadata, and optionally a formatted event/history summary.

### 5.4 No dedicated agent view/dock in v1

A dedicated agent view/dock is explicitly out of scope for the first production implementation.

V1 TUI should render subagent progress/results inline in the normal chat transcript, similar to Pi's `renderSubagentResult()` approach:

```text
subagent scout running | 3 tools, 4.2k tok, 00:18
Task: inspect TUI architecture
> read: render.ts | 00:03
active now
Artifacts: agent_01HX
```

Expanding/collapsing inline details through the normal tool widget is acceptable. Do not implement `/agents`, a dock, a fake overlay, selected-child panels, or a custom control surface for v1.

Tmux monitor mode and dedicated views may be useful later, especially for fork, but they are not part of v1 subagents.

### 5.6 Fork is out of scope

Fork should eventually reuse:

- parent-scoped child run infrastructure where appropriate;
- artifact registry/retrieval where appropriate;
- parent-scoped child run and artifact mechanics;
- inline progress/result rendering where appropriate.

But fork is not simply an agent definition. It has special semantics:

- copies/snapshots parent session context;
- sanitizes recent fork tool calls;
- may inject a fork-specific child system prompt;
- may run in worktrees;
- produces implementation handoffs;
- may deserve tmux-pane visibility more than normal subagents.

Do not include fork in the initial agent catalog/examples. Track it as a separate design/implementation later.

---

## 6. Agent definitions

### 6.1 File format

Use markdown files with YAML-like frontmatter, inspired by Pi subagents.

Example:

```md
---
name: scout
description: Fast read-only codebase reconnaissance
model: deepseek/deepseek-v4-flash
thinking: low
tools:
  - read
  - ide_find_file
  - ide_search_text
  - ide_file_structure
  - semantic-search
mcp:
  mode: none
skills:
  - testing
inheritProjectContext: true
inheritAgentsMd: true
systemPromptMode: replace
maxDepth: 1
parallelAllowed: true
---

You are a scout. Explore read-only and return dense concrete findings with file paths,
classes, methods, risks, and recommendations. Do not edit files.
```

### 6.2 Discovery locations

Recommended discovery load order (lowest to highest; later layers override earlier):

```text
user agents under ~/.agents/
user agents under ~/.hatfield/agents/
project agents under .agents/
project agents under .hatfield/agents/
configured explicit agents.paths entries
```

`.agents/` support is first-class, not a legacy fallback. Project definitions should override user definitions by name. Hatfield-native `.hatfield/agents/` and cross-tool `.agents/` definitions should both be supported with deterministic precedence documented in `docs/agents.md`. Do **not** bundle built-in agent definition files; users/projects define names such as `scout`, `reviewer`, `researcher`, and `worker` themselves.

Do not recreate `.hatfield.example/`.

### 6.3 Frontmatter fields

Recommended typed fields:

| Field | Type | Required | Purpose |
|---|---:|---:|---|
| `name` | string | yes | Unique agent name. |
| `description` | string | yes | Human-readable description and catalog text. |
| `model` | string|null | no | Optional model override. |
| `thinking` | string|null | no | Optional reasoning/thinking override. |
| `tools` | list<string> | yes | Explicit tool allowlist. |
| `mcp.mode` | enum | no | `none`, `all`, or `specific`. |
| `mcp.tools` | list<string> | no | Allowed MCP tools when mode is `specific`. |
| `skills` | list<string> | no | Setup skills loaded from start. |
| `inheritProjectContext` | bool | no | Whether to include project context. |
| `inheritAgentsMd` | bool | no | Whether to include `AGENTS.md`. |
| `systemPromptMode` | enum | no | `replace` or `append`. |
| `maxDepth` | int | no | Per-agent recursion cap. |
| `parallelAllowed` | bool | no | Whether the agent can be launched as part of parallel execution. |
| `disabled` | bool | no | Disable definition without deleting it. |
| `handoffFormat` | string|null | no | Optional named handoff template. |

Unknown fields should be rejected or warned on during catalog validation. Prefer strict validation to avoid silent misconfiguration.

### 6.4 Example agent roles, not bundled builtins

Do not ship bundled agent definition files in `config/agents/` or another built-in directory. The following are example names users/projects may define under `.agents/` or `.hatfield/agents/`.

#### `scout`

Purpose:

- codebase reconnaissance;
- architecture discovery;
- impact analysis;
- read-only findings.

Typical policy:

- read-only tools;
- low/medium thinking;
- parallel allowed;
- foreground `subagent` tool execution;
- max depth 1.

#### `reviewer`

Purpose:

- code review;
- correctness/security/design risk analysis;
- validation of implementation diffs.

Typical policy:

- read-only tools;
- higher thinking;
- foreground `subagent` tool execution;
- max depth 1.

#### `researcher`

Purpose:

- web/docs/MCP research;
- changelog/library investigation;
- external API lookup.

Typical policy:

- web/MCP/documentation tools when enabled;
- no file editing;
- foreground `subagent` tool execution;
- max depth 1.

#### `worker`

Purpose:

- general execution where implementation-capable tools are intentionally allowed.

Typical policy:

- more restricted than parent unless explicitly configured;
- not recursively allowed by default;
- use carefully with task workflow.

#### Excluded: `fork`

Do not implement `fork` as an agent definition in this milestone.

---

## 7. Tool and MCP policy

### 7.1 Per-agent tool allowlist

Each agent run must have an explicit tool access policy derived from its definition plus launch overrides.

Policy resolution order:

```text
hard safety rules
  → agent definition defaults
  → launch request overrides
  → runtime recursion/depth restrictions
  → SafeGuard/tool hooks at execution time
```

The resolved policy must be persisted in child run metadata so replay/debugging can explain which tools were available.

### 7.2 MCP policy

After the MCP task chain is complete, agent definitions should support:

```yaml
mcp:
  mode: none # none|all|specific
  tools:
    - context7__query-docs
    - websearch__search
```

Semantics:

- `none`: no MCP tools and no MCP discovery tools.
- `specific`: only listed MCP tools available.
- `all`: all MCP tools visible to the current run's MCP catalog.

Agents may have MCP tools that are not enabled for the global/parent agent, as long as the session/project configuration permits those MCP servers. This requires the MCP tool resolver to accept a per-run/per-agent policy.

### 7.3 SafeGuard compatibility as a guardrail, not child HITL

Agent tool calls must pass through normal tool execution and extension hook paths. SafeGuard should not need a special subagent-only implementation.

However, v1 child agents must not enter a nested approval/question flow. If a child agent invokes a risky tool and SafeGuard requires approval:

```text
child tool call returns RequireApproval
  → child run is stopped/failed with needs_attention or approval_required
  → parent agent registry records the blocked action summary
  → inline tool result shows the failed/blocked handoff
  → parent/user decides what to do manually or launches a new child
```

Do not route the approval question into the child in v1. Child-agent HITL may be redesigned later only if there is a clear UX and event model.

---

## 8. Control surface

Agents do not support steering, live user input, or a separate control plane in v1.

Supported control operations:

- launch agent through the foreground `subagent` tool;
- cancel the in-flight tool/run through the existing cancellation path (for example Esc in the TUI);
- retrieve result artifact/history through `agent_retrieve`;
- optionally retry failed agent later by launching a new run with a revised task.

Explicitly unsupported in v1:

- background launch;
- separate status/list tool;
- dedicated agent dock/view;
- steer agent with additional instructions;
- answer pending human question;
- nested approval flow;
- child conversation/follow-up;
- parent/user input routed into a running child.

App-layer service methods should stay minimal:

```php
interface SubagentExecutionServiceInterface
{
    public function run(SubagentRunRequestDTO $request, ?SubagentProgressCallbackInterface $progress = null): SubagentRunResultDTO;
}

interface AgentArtifactRetrievalServiceInterface
{
    public function retrieve(string $parentRunId, string $artifactId, AgentArtifactRetrieveOptionsDTO $options): AgentArtifactDTO;
}
```

Implementation should delegate to existing `AgentRunner`/runtime commands where possible:

- run → existing start/continue pipeline with child-scoped storage and progress callback;
- cancel → existing in-flight tool/run cancellation;
- retrieve → parent-scoped registry and child artifact/event files.

---

## 9. Artifacts registry

### 9.1 Purpose

Agents produce handoffs/results. These must be stored durably and retrievable by the parent session at any time.

The registry is session-scoped to the parent run.

Normal agents are not visible through `/sessions`; artifacts are the main discovery surface.

### 9.2 Storage layout

Suggested layout:

```text
.hatfield/sessions/<parent_run_id>/artifacts/agents/
  registry.json
  <artifact_id>/
    metadata.json
    handoff.md
    events.jsonl
    state.json
```

A DB-backed registry can be added if needed, but file-backed session-local storage is preferred for v1 if it follows locking and replay conventions. The important constraint is parent-scoped storage: do not write child events as top-level `.hatfield/sessions/<child_run_id>/events.jsonl` for normal subagents.

### 9.3 Registry entry shape

```json
{
  "artifact_id": "agent_01HX...",
  "parent_run_id": "parent-run-id",
  "agent_run_id": "child-run-id",
  "agent_name": "scout",
  "status": "completed",
  "created_at": "2026-06-15T12:00:00Z",
  "started_at": "2026-06-15T12:00:01Z",
  "completed_at": "2026-06-15T12:01:30Z",
  "summary": "Short human-readable summary",
  "handoff_path": "artifacts/agents/agent_01HX/handoff.md",
  "metadata_path": "artifacts/agents/agent_01HX/metadata.json",
  "events_path": "artifacts/agents/agent_01HX/events.jsonl",
  "state_path": "artifacts/agents/agent_01HX/state.json",
  "usage": {
    "input_tokens": 0,
    "output_tokens": 0,
    "cost": null
  },
  "error": null
}
```

### 9.4 Handoff content

Handoffs should be deterministic and dense. A good default handoff format:

```md
# Agent handoff: scout

## Task

...

## Result

...

## Concrete findings

- `path/to/file.php`: finding...

## Decisions / recommendations

...

## Risks / blockers

...

## Validation performed

...

## Follow-up suggested

...
```

Agent definitions may override handoff format later, but v1 should standardize it.

### 9.5 Agent tools/API

Expose two model-visible tools for v1:

- `subagent` — foreground/blocking launch of one or more agents with live inline progress and final handoff as the tool result.
- `agent_retrieve` — retrieve a completed/failed handoff, metadata, or formatted event/history summary by `artifact_id` or `agent_run_id`.

Do not add `agent_start`/`agent_status` for v1. The running tool widget is the status surface, and the final tool result is the completion notification.

Interactive controls (`steer`, `answer question`, nested approval response) are intentionally absent from v1. Cancellation should use the existing in-flight tool/run cancellation path.

---

## 10. Runtime/domain event model

### 10.1 AgentCore additions

For v1, avoid adding a parent `WaitingAgent` state. The parent is already waiting on a normal foreground tool call, just like any other long-running tool.

Potential command/event additions should be minimal and app-agnostic. Do **not** overload `WaitingHuman` for child agents and do **not** add AgentCore events for every child progress/update.

The child run already has its own parent-scoped `events.jsonl`, and those events should remain the detailed source of truth. Keep AgentCore payloads generic and app-independent. Do not let AgentCore depend on `CodingAgent\Agent` classes.

### 10.2 App/runtime protocol and child progress

Do not mirror every child event into the parent runtime stream. A child run is still a run with its own parent-scoped events.

During an active `subagent` tool call, the app layer should convert child progress into compact tool-result update snapshots, similar to Pi subagents:

```text
child event/progress
  → update in-memory AgentProgress snapshot
  → tool update callback emits partial AgentToolResult details
  → chat transcript re-renders the inline subagent tool widget
```

After completion, retrieval should read from the registry/artifact directory:

```php
AgentArtifactRetrievalService::retrieve(parentRunId, artifactId, options): AgentArtifactDTO;
```

`agent_retrieve` may support modes such as handoff-only, metadata, recent events, full formatted history, or raw artifact paths. A dedicated runtime event stream/query API for selected-child replay is not required for v1.

### 10.3 Future async/dedicated-view mode

A future mode may add async launch, `agent_status`, completion notifications, compact global status, or a dedicated `/agents` view. That future mode must not add mid-run child questions, steering, or nested approvals without a separate UX decision.

---

## 11. Proposed PHP module layout

Recommended app-layer namespace:

```text
src/CodingAgent/Agent/
  Artifact/
    AgentArtifactDTO.php
    AgentArtifactRegistry.php
    AgentArtifactRepository.php
    AgentArtifactStatusEnum.php

  Definition/
    AgentDefinitionDTO.php
    AgentDefinitionCatalog.php
    AgentDefinitionDiscovery.php
    AgentDefinitionParser.php

  Execution/
    SubagentRunRequestDTO.php
    SubagentRunResultDTO.php
    SubagentExecutionService.php
    SubagentProgressDTO.php
    SubagentProgressCallbackInterface.php
    AgentDepthGuard.php
    AgentToolPolicyResolver.php
    AgentPromptBuilder.php

  Tool/
    AgentToolProvider.php
    SubagentTool.php
    AgentRetrieveTool.php

  Render/
    SubagentResultRenderer.php  # or equivalent TUI/tool-rendering integration
```

No dedicated `src/Tui/Agent/` namespace is required for v1. Inline rendering should live with normal tool-result rendering infrastructure.

Recommended core additions should stay in `src/AgentCore/` only for generic run statuses/events/commands. Avoid app-specific agent DTOs in AgentCore.

---

## 12. Subagent tool execution flow

### 12.1 Subagent run request DTO

```php
final readonly class SubagentRunRequestDTO
{
    /** @param list<SubagentRunTaskDTO> $tasks */
    public function __construct(
        public string $parentRunId,
        public ?string $agentName,
        public ?string $task,
        public array $tasks = [],
        public ?string $cwd = null,
        public ?string $model = null,
        public ?string $thinking = null,
        public ?int $concurrency = null,
        public array $metadata = [],
    ) {}
}
```

Use one `subagent` tool with mutually exclusive single (`agent` + `task`) and parallel (`tasks`) modes, following Pi subagents' parameter shape.

### 12.2 Foreground execution algorithm

```text
SubagentTool invoked
  → parse/validate parameters
  → AgentDefinitionCatalog resolves agent definition(s)
  → AgentDepthGuard checks recursion limits
  → AgentToolPolicyResolver resolves allowed tools/MCP
  → AgentArtifactRegistry creates pending parent-scoped artifact directory/entry
  → parent-scoped child run metadata is created
  → AgentPromptBuilder builds child system/task prompt
  → AgentRunner starts child run against child-scoped event/state stores
  → child progress updates produce inline tool-result update snapshots
  → child completes/fails/cancels
  → registry, metadata, events, and handoff artifacts are finalized
  → tool returns final handoff/result to parent LLM
```

### 12.3 Parent-scoped child run metadata

Child run metadata must include enough context for registry and debugging:

```json
{
  "kind": "agent_child",
  "parent_run_id": "...",
  "root_run_id": "...",
  "agent_run_id": "...",
  "agent_name": "scout",
  "agent_depth": 1,
  "artifact_id": "...",
  "events_path": "artifacts/agents/agent_01HX/events.jsonl",
  "state_path": "artifacts/agents/agent_01HX/state.json",
  "tool_policy": {
    "tools": ["read", "ide_find_file"],
    "mcp": {"mode": "none", "tools": []}
  }
}
```

Do not create a normal `HatfieldSession` row for v1 child agents unless a later task explicitly chooses a DB-backed design. Regular session catalog queries should not need hidden-row filtering for these parent-scoped child runs.

---

## 13. Recursion prevention

Use both environment variables and persisted metadata.

Environment variables:

```text
HATFIELD_AGENT_CHILD=1
HATFIELD_AGENT_DEPTH=1
HATFIELD_AGENT_MAX_DEPTH=1
HATFIELD_AGENTS_DISABLED=1
```

Persisted metadata:

```json
{
  "agent_depth": 1,
  "agent_max_depth": 1,
  "agents_disabled": true
}
```

Rules:

- Default max depth should be conservative: 1 for scout/reviewer/researcher, 0 or 1 for worker depending on launch policy.
- Child agents should not be able to launch more agents unless explicitly allowed.
- Environment variables protect subprocess/CLI boundaries.
- Persisted metadata protects in-process execution and replay/resume.
- If recursion is blocked, return a clear tool error and record it in the artifact/registry.

---

## 14. Parallel execution

Support parallel agent launches.

Initial shape can mirror Pi subagents, with no foreground/background mode selection:

```json
{
  "tasks": [
    {"agent": "scout", "task": "Inspect runtime events"},
    {"agent": "scout", "task": "Inspect TUI view options"},
    {"agent": "reviewer", "task": "Review proposed API"}
  ],
  "concurrency": 3
}
```

Rules:

- Enforce global and per-parent concurrency caps.
- Enforce per-agent `parallelAllowed`.
- Each child gets its own parent-scoped child run and artifact id.
- The foreground `subagent` tool returns when all children have completed/failed/cancelled.
- Live inline progress should show per-child status while parallel children run.
- Partial failures should use per-child artifacts with clear success/failure states and an aggregate tool result.

Suggested settings:

```yaml
agents:
  max_concurrent_per_parent: 4
  max_concurrent_global: 8
  default_parallel_concurrency: 4
```

---

## 15. TUI design

### 15.1 Inline tool rendering

V1 subagent TUI should be an inline chat transcript tool widget, not a dock, dedicated view, or overlay. Pi subagents prove this is acceptable and simpler.

The running widget should show compact live progress, for example:

```text
subagent scout running | 3 tools, 4.2k tok, 00:18
Task: inspect runtime events
> read: RuntimeEventTranslator.php | 00:03
active now
Artifacts: agent_01HX
```

For parallel runs, show an aggregate header plus per-child rows:

```text
subagent parallel running 2/3 | 7 tools, 00:42
running Step 2: scout | 3 tools
  task: Inspect TUI rendering
  > read: ChatScreen.php
completed Step 1: reviewer | artifact agent_a
failed Step 3: worker | artifact agent_b
```

Expanded inline detail may show recent tool calls, recent output lines, artifact id/path, usage, and final Markdown handoff. Collapsed detail should stay small enough not to bury the conversation.

### 15.2 Retrieval UX

`agent_retrieve` is the v1 way to inspect completed/failed child artifacts after the tool result, especially when the original inline output was truncated or the agent failed.

Retrieval can return:

- handoff markdown;
- metadata/status/usage;
- recent events;
- formatted event/history summary;
- raw artifact paths for debugging.

No `/agents` command, hotkey, dock, selected-child panel, or custom control surface is required for v1.

### 15.3 Needs-clarification / blocked flow

When a child agent lacks information, hits an unsupported approval requirement, or otherwise cannot continue independently:

- the child stops and writes a failed/needs-clarification artifact;
- the inline tool result shows the blocked/needs-clarification summary;
- the artifact remains retrievable through `agent_retrieve`;
- the parent/user decides whether to answer in the parent conversation, manually continue, or launch a new child with a revised task.

Do not route answers into the running child in v1.

### 15.4 TUI validation requirement

Any custom inline subagent tool rendering should include a real TUI E2E test using the project `TmuxHarness` and real test LLM endpoint, following `tests/AGENTS.md` and the `testing` skill.

Do not accept service-only tests as proof for user-visible subagent rendering behavior.

---

## 16. Settings and docs

Add an `agents` section to Hatfield settings.

Example:

```yaml
agents:
  enabled: true
  definitions:
    user_dir: '~/.hatfield/agents'
    project_dir: '.hatfield/agents'
  max_depth: 1
  max_concurrent_per_parent: 4
  max_concurrent_global: 8
  artifacts:
    retention_days: 14
  ui:
    inline_progress: true
```

Update:

- `.hatfield/settings.yaml` if new project-local settings are introduced;
- `docs/settings.md`;
- a new `docs/agents.md`;
- tool prompt/docs integration as needed.

Keep settings precedence consistent:

```text
built-in defaults < ~/.hatfield/settings.yaml < project .hatfield/settings.yaml
```

---

## 17. Security and privacy

Agents can amplify tool usage. Be strict.

Requirements:

- Explicit tool allowlists for every agent.
- SafeGuard hooks still apply to child agents.
- MCP tools are disabled unless configured by policy.
- Child metadata must not leak raw prompts/tool outputs into logs.
- Runtime logs must use structured event-style fields and avoid raw prompts, tool output, environment values, API keys, and full session content by default.
- Agent artifacts may contain sensitive content; store under session directory and do not expose globally.
- Inline tool results and retrieval outputs should contain summaries/ids by default, not full raw outputs unless explicitly requested.

Relevant logging guidance:

- `docs/datadog.md`

---

## 18. Testing and validation

Before writing or running tests, agents must load the `testing` skill and read `tests/AGENTS.md`.

### 18.1 Test levels

Expected coverage:

- unit tests for frontmatter parser and definition validation;
- unit/integration tests for policy resolution;
- integration tests for artifact registry locking/update behavior;
- runtime/app tests for foreground execution, completion, failure, cancellation, and retrieval;
- runtime protocol/tool-update tests for live progress snapshots;
- TUI E2E tests for inline subagent progress/result rendering;
- end-to-end validation for foreground subagent runs, parallel runs, and retrieval.

### 18.2 Required Castor commands

All QA commands must go through Castor.

Use relevant focused commands during implementation, then full validation before code review:

```bash
castor test
castor deptrac
castor phpstan
castor cs-check
castor test:tui      # required for TUI changes
LLM_MODE=true castor check
```

Do not run raw `vendor/bin/*` except for diagnosing a Castor failure.

### 18.3 TUI E2E proof

Inline subagent rendering is a TUI feature. It is not complete until there is an automated TUI E2E test using:

- real `TmuxHarness`;
- real `llama_cpp_test/test` endpoint on port 9052;
- isolated `var/tmp/test-{uuid}` directories;
- snapshot/assertion proving live progress and final result render through interactive TUI behavior.

A service test, mocked runtime, or manual smoke script is not enough.

---

## 19. Staged implementation roadmap

### Stage -1 — Throwaway POC/spike

Before production implementation, run a deliberately throwaway POC to test the risky shape of the system. This is **not** an MVP and should not be merged as the foundation for production work.

Purpose:

- prove that a parent-scoped child run can be started and supervised;
- prove that a parent-scoped file registry can track the child;
- test whether the current TUI overlay APIs can support the planned agent control surface;
- compare that result with Pi subagents' inline rendering model;
- discover state-machine and TUI projection problems before committing to production structure.

AGENT-03 result: parent-scoped nested storage works and focusable inserted controls are possible, but current `insertOverlay*` APIs do **not** create a real overlay/control plane. Follow-up reconnaissance of Pi subagents showed an even simpler acceptable UX: inline chat transcript tool rendering with live updates and expansion. Production v1 should use inline `subagent` tool rendering, not a dock/dedicated view.

POC constraints:

- hardcode one agent definition, likely `scout`;
- skip custom discovery, settings polish, MCP policy, and full catalog;
- skip compatibility/fallback layers;
- accept ugly internal seams if they help answer the architectural question;
- do not add production APIs solely to support the spike;
- do not preserve the spike implementation after the architecture is understood;
- write down findings and delete/rewrite the code for production.

The POC should validate the architecture, not become a fallback path. The production implementation should then change structure cleanly based on what the spike proves.

### Stage 0 — Finish prerequisites

Complete/verify any prerequisite runtime/session work needed for parent-scoped child event stores and artifact registry.

MCP remains a prerequisite only for per-agent MCP policy:

```text
MCP track: mcp-01 → mcp-06
```

The QH/`ask_human` track is not a blocker for v1 agents because child HITL/questions are explicitly out of scope.

### Stage 1 — Production RFC/task breakdown

Use the POC findings to create concrete production task files for the agents project. Do not copy spike shortcuts into production. Suggested tasks:

```text
AGENTS-01 Agent definitions, catalog, settings, and docs
AGENTS-02 Parent-scoped artifact registry and child run metadata/event stores
AGENTS-03 Foreground subagent tool, policy, recursion guard, and inline progress
AGENTS-04 Agent retrieval tool for handoff/history/events
AGENTS-05 Parallel subagent execution and concurrency caps
AGENTS-06 Prompt/tool docs and final E2E validation
```

Fork should be a later separate track, for example:

```text
FORK-01 Native fork design using parent-scoped child runs and artifacts
```

### Stage 2 — Agent definitions/catalog

Deliverables:

- `AgentDefinitionDTO`
- `AgentDefinitionLoader`
- `AgentFrontmatterParser`
- `AgentDefinitionCatalog`
- validation errors with actionable messages
- docs/examples for user-defined `scout`, `reviewer`, `researcher`, and `worker` roles
- settings/docs updates

Validation:

- unit tests;
- `castor phpstan`;
- `castor test` focused where applicable.

### Stage 3 — Parent-scoped artifact registry and child run metadata

Deliverables:

- parent-scoped artifact storage under `.hatfield/sessions/<parent>/artifacts/agents/`;
- registry read/write/update with locking;
- child run metadata and parent-scoped child event/state stores;
- proof that normal session listing is not polluted by child runs;
- explicit retrieval APIs.

Validation:

- integration tests for registry;
- storage tests proving child runs do not create top-level session entries;
- `castor test`.

### Stage 4 — Foreground subagent tool, policy, and recursion guard

Deliverables:

- `SubagentTool` with single and parallel parameter shapes;
- `SubagentExecutionService`;
- `AgentDepthGuard`;
- tool/MCP policy resolver;
- parent-scoped child run start;
- persisted metadata and env guard setup;
- live inline progress snapshots for the tool result;
- normal cancellation through existing in-flight tool/run cancellation.

Validation:

- unit/integration tests for depth and policy;
- foreground run tests with stubbed/minimal runtime where appropriate;
- TUI E2E for inline progress/final result if custom rendering is introduced;
- `castor deptrac` to verify boundaries.

### Stage 5 — Retrieval tool for handoff/history/events

Deliverables:

- `agent_retrieve` tool/API;
- retrieval by `artifact_id` or `agent_run_id`;
- handoff, metadata/status/usage, recent events, formatted history, and debug path modes;
- no separate `agent_status` tool in v1.

Validation:

- retrieval tests;
- resume/replay retrieval tests;
- truncation/failed-run retrieval tests.

### Stage 6 — Parallel execution

Deliverables:

- parallel `subagent` tool shape;
- concurrency caps;
- aggregate foreground result behavior;
- per-child artifacts;
- clear partial-failure behavior.

Validation:

- concurrency tests;
- partial failure behavior tests;
- registry consistency tests;
- TUI inline rendering test for parallel progress if user-visible.

### Stage 7 — Docs and final validation

Deliverables:

- `docs/agents.md`;
- `docs/settings.md` updates;
- prompt/tool docs updates;
- examples for user-defined custom agents;
- final full validation.

Validation:

```bash
LLM_MODE=true castor check
```

---

## 20. Future fork plan boundary

Fork should be designed after normal agents are complete.

Expected fork relationship to this plan:

- reuse parent-scoped child run infrastructure where appropriate;
- reuse artifact registry/retrieval where appropriate;
- reuse inline progress/result rendering where appropriate.

Expected fork-specific additions:

- parent session snapshot/copy;
- sanitized event branch;
- fork child system prompt;
- optional worktree support;
- implementation handoff format;
- stronger visibility, possibly tmux monitor/pane;
- separate recursion guard such as `HATFIELD_FORK=1`.

Fork is not a custom agent definition and should not be implemented as `type: fork` in the initial catalog.

---

## 21. Resolved decisions and remaining design discussion

These decisions should be treated as input to the Stage 1 RFC/task breakdown.

1. V1 child runs are parent-scoped artifacts, not normal top-level Hatfield sessions.
   - Store child events/state under `.hatfield/sessions/<parent>/artifacts/agents/<artifact>/`.
   - Do not create `.hatfield/sessions/<child_run_id>/` for normal subagents in v1.

2. V1 agents are non-interactive foreground workers.
   - One `subagent` tool call launches and waits for completion.
   - Live progress renders inline in the tool widget.
   - No background/async launch in v1.
   - No mid-run steering.
   - No live user input routed to children.
   - No child HITL questions.
   - No nested approval flows.
   - No interactive child conversations.

3. `WaitingAgent`, completion notifications, global compact status, and dedicated agent dock/view are deferred.
   - The parent is already waiting on the normal foreground tool call.
   - Do not overload `WaitingHuman` or generic interrupt details for agent waits.

4. The artifact registry is file-backed for v1.
   - Store it under the parent session directory with locking and a clear future DB migration path.
   - Keep it because failed/long child runs may need retrievable handoff/history/events.

5. Use two model-visible tools for v1:
   - `subagent`;
   - `agent_retrieve`.

6. Child events should stay in the child run's own parent-scoped event stream.
   - Do not mirror every child event into the parent session; that would duplicate data and increase event volume unnecessarily.
   - The active `subagent` tool can render compact live progress snapshots.
   - `agent_retrieve` can read handoff, metadata, and formatted event/history summaries from the registry/artifact directory.
   - A selected-child replay/dedicated-view event API is not required for v1.

7. Parallel partial failures should use per-child artifacts with clear success/failure states.
   - Aggregate summaries must not silently collapse failures into success.

8. `.agents/` support is first-class.
   - Support project `.agents/` and user `~/.agents/` alongside Hatfield-native `.hatfield/agents/` locations with deterministic documented precedence.

9. Do not bundle built-in agent definitions.
   - `scout`, `reviewer`, `researcher`, and `worker` are example names users/projects may define.

---

## 22. Implementation rules and constraints

Follow project rules:

- Use Castor for all QA/tooling commands.
- Load the `testing` skill and read `tests/AGENTS.md` before writing/running tests or touching TUI/runtime/Messenger code.
- Maintain deptrac boundaries:
  - `AgentCore` must not depend on `CodingAgent` or `Tui`.
  - `Tui` must talk to runtime only through `Runtime/Contract`, `Runtime/Protocol`, and allowed projection DTOs.
- Do not add HTTP controllers/routes/session/profiler features.
- Do not add backward-compatibility shims unless explicitly requested.
- Do not add production APIs solely for tests.
- Preserve meaningful comments that explain lifecycle/concurrency/rationale.
- Every caught exception must be propagated or logged/documented as intentional local degradation.
- Runtime logs must be structured and privacy-safe.

---

## 23. Success criteria

The agents system is ready when:

- custom agent definitions can be loaded and validated from `.agents/` / `.hatfield/agents/`;
- a scout-style user-defined agent can run as a parent-scoped child and produce a handoff artifact;
- child agents do not appear in normal `/sessions` and do not create top-level child session directories;
- artifacts are session-scoped and retrievable after resume;
- the foreground `subagent` tool returns final handoffs and updates the registry/artifacts;
- `agent_retrieve` can retrieve failed/completed handoffs and useful history/events;
- recursion guard prevents accidental nested agent explosions;
- unsupported child approval/input conditions stop with a clear blocked/needs-clarification artifact;
- per-agent tool/MCP policy is enforced;
- parallel launches respect concurrency limits;
- inline subagent progress/result rendering works through real TUI E2E;
- `LLM_MODE=true castor check` passes.
