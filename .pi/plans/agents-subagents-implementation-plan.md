# Agents and Subagents Implementation Plan

## Status

Planning document for adding **native agents/subagents** to Hatfield/agent-core.

This plan is intentionally self-contained. It assumes the reader has no context from prior design discussions.

The design is inspired by Pi's subagents package (`/home/ineersa/claw/my-pi/packages/subagents`) but must be implemented natively in Hatfield using the existing AgentCore run pipeline, runtime protocol, session storage, tool registry, HITL/SafeGuard flow, and TUI architecture.

This is **not** a fork implementation plan. Fork is related and should eventually reuse parts of this infrastructure, but it is explicitly out of scope for the initial agents/subagents milestone.

---

## 1. Goal

Add a first-class agent/subagent system so a Hatfield session can delegate focused work to specialized child agents.

The system must support:

- builtin agents such as `scout`, `reviewer`, `researcher`, and `worker`;
- project/user agent definitions with markdown frontmatter;
- per-agent steering/instructions;
- foreground and background execution modes;
- parallel execution;
- per-agent tool policy and future per-agent MCP policy;
- setup skills loaded from start;
- explicit `AGENTS.md` inheritance controls;
- SafeGuard permission approval compatibility;
- future `ask_human` compatibility;
- completion notification for background agents;
- a session-scoped artifacts registry for handoffs/results;
- a dedicated agent view in the first production implementation;
- recursion prevention with both environment variables and persisted metadata.

Normal agents/subagents are **hidden child runs**. They must not appear as normal user sessions in `/sessions`. The parent session discovers them only through the session-scoped agent/artifact registry and TUI agent view.

---

## 2. Non-goals for the first agents milestone

Do not include these in the initial agents implementation:

- Fork implementation.
- One tmux pane per subagent.
- Agent-to-agent recursive delegation by default.
- Global agent session listing in `/sessions`.
- MCP implementation itself.
- `ask_human` implementation itself.
- Backgrounding an already-foreground subagent mid-run.
- Full autonomous inter-agent conversation/intercom.
- Multi-machine/distributed agent execution.
- Web/HTTP server endpoints.

Important future hooks should be designed now, but implementation should stay focused.

---

## 3. Required prerequisites

Agents should be implemented **after** the QH/`ask_human` track and the MCP track.

### 3.1 QH / `ask_human` prerequisite

Agents must be able to ask humans and request approvals through the same persistent HITL flow used by the parent session. Therefore complete the QH task chain first:

```text
tasks/TODO/qh-04-ask-human-tool.md
tasks/TODO/qh-05-ask-human-agentcore-compatibility.md
tasks/TODO/qh-06-hitl-runtime-projection.md
tasks/TODO/qh-07-bind-hitl-to-question-coordinator.md
tasks/TODO/qh-08-resume-pending-hitl-question.md
tasks/TODO/qh-09-question-docs-tests-smoke.md
```

Some TUI question infrastructure already exists on main, but the agents implementation should treat the QH chain as a prerequisite and re-check task status before starting.

Relevant current code:

- `src/AgentCore/Application/Pipeline/ToolCallResultHandler.php`
  - transitions runs to `WaitingHuman` when a tool result contains an interrupt payload.
- `src/AgentCore/Application/Pipeline/ToolCallExtractor.php`
  - extracts interrupt payload details from tool results.
- `src/AgentCore/Application/Pipeline/ApplyCommandHandler.php`
  - processes `HumanResponse` commands and resumes the run.
- `src/AgentCore/Domain/Run/RunStatus.php`
  - already has `WaitingHuman`.
- `src/CodingAgent/Runtime/Protocol/RuntimeEventTranslator.php`
  - maps AgentCore events to runtime events.
- `src/Tui/Question/QuestionCoordinator.php`
  - queues TUI questions.
- `src/Tui/Question/QuestionController.php`
  - owns the question overlay lifecycle.
- `docs/hitl-and-approvals.md`
  - documents SafeGuard/HITL approval architecture.

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

The agents system should create hidden child runs that use this existing pipeline. Do not invent a separate agent loop.

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

Child agent tool calls must still pass through SafeGuard and any future approval hooks. If a hidden child agent requests approval or asks a human, the parent TUI must surface that requirement in the agent view and compact status area.

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

- background agent launch returns immediately;
- background agent completion notifies the parent session;
- no user question asking whether to background;
- no mid-run background handoff in the first production version;
- no shell process question bridge reuse unless needed later.

### 4.7 TUI layout and overlay architecture

The TUI is currently a single-column chat screen with widgets and overlays.

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

The initial dedicated agent view should be implemented as a TUI overlay/dedicated in-process view, not one tmux pane per subagent.

---

## 5. Core design decisions

### 5.1 Hidden child runs

Each subagent execution is a normal AgentCore run internally but hidden from normal session listing.

A hidden child run has:

- its own run id;
- its own `.hatfield/sessions/<child_run_id>/events.jsonl`;
- a real persisted `hidden` column/flag on `HatfieldSession` so session catalog queries can exclude it efficiently;
- parent run id metadata;
- root run id metadata;
- agent name/type metadata;
- depth metadata;
- launch mode metadata (`foreground` or `background`);
- artifact id metadata;
- duplicated hidden/non-user-visible metadata for replay/debugging and filesystem-only diagnostics.

The child run must not appear in `/sessions` or ordinary session pickers. It should appear only in:

- agent view for the parent session;
- session-scoped agent registry;
- session-scoped artifact registry;
- logs/debug tooling when explicitly requested.

### 5.2 Foreground mode

Foreground mode must not be implemented as a long blocking PHP process wait inside the tool.

Use this state-machine model:

```text
parent LLM calls agent tool with background=false
  → AgentLaunchService creates hidden child run
  → parent run records pending foreground agent wait
  → parent run transitions to WaitingAgent
  → child run executes independently
  → child completes/fails/cancels
  → AgentSupervisor writes artifact
  → AgentSupervisor injects child result as parent tool result
  → parent run leaves WaitingAgent and continues
```

This gives users foreground semantics while preserving runtime responsiveness, TUI updates, cancellation, and HITL handling.

### 5.3 Background mode

Background mode starts a hidden child run and returns immediately.

```text
parent LLM calls agent tool with background=true
  → AgentLaunchService creates hidden child run
  → artifact registry creates pending artifact entry
  → tool result returns agent_run_id/artifact_id/status
  → parent run continues immediately
  → child completes later
  → AgentSupervisor updates artifact registry
  → parent session receives an agent completion notification
  → result can be retrieved later from registry
```

The completion notification is similar in spirit to background bash completion, but simpler:

- no prompt asking whether to background;
- no local TUI question flow for the launch itself;
- no mid-run backgrounding in the first production version;
- no shell process semantics.

A future phase can add "move this foreground agent to background" because the architecture already separates parent wait state from child run execution.

### 5.4 Completion notification

Background agents must notify the parent when they finish, but detailed child events should **not** be duplicated into the parent session.

The source of truth is:

1. the child run's own session event stream for detailed replay/progress; and
2. the parent session's file-backed agent/artifact registry for summary status.

A completion notification should be persisted as a registry status transition. If the parent transcript/runtime needs a visible notification, emit at most one coarse parent-visible notification for the lifecycle transition, not a mirrored copy of child events.

Suggested registry/notification payload:

```json
{
  "agent_run_id": "...",
  "parent_run_id": "...",
  "artifact_id": "...",
  "agent_name": "scout",
  "status": "completed",
  "summary": "Short one-line handoff summary",
  "completed_at": "..."
}
```

The parent LLM should not automatically receive full artifact contents unless the foreground mode requires it. Background mode should make retrieval explicit through `agent_retrieve` or a TUI command.

### 5.5 Dedicated agent view in the first production implementation

A dedicated agent view is part of the first production implementation, not a later follow-up layered onto a minimal tool-only release.

Prefer an overlay/dedicated in-process TUI view for subagents rather than tmux panes.

The dedicated agent view should show:

- list/tree of agents for the current parent session;
- each agent's name, type, mode, status, elapsed time, and attention state;
- selected agent progress/events;
- selected agent artifact/handoff preview;
- controls for:
  - steer;
  - cancel;
  - answer pending question;
  - retrieve result / copy artifact id;
  - close view.

The main chat view should show compact status rows such as:

```text
Agents: scout#abc running · reviewer#def completed · worker#ghi requires attention
```

A shortcut should open the agent view. Exact keybinding can be chosen during implementation and must be listed in `/hotkeys`.

Tmux monitor mode may be useful later, especially for fork, but it is not the default subagent view. Avoid one tmux pane per subagent due to pane limits/layout instability and because subagents do not require full visibility by default.

### 5.6 Fork is out of scope

Fork should eventually reuse:

- hidden child run infrastructure;
- artifact registry;
- background/foreground mechanics;
- completion notifications;
- dedicated agent/fork view components where useful.

But fork is not simply an agent definition. It has special semantics:

- copies/snapshots parent session context;
- sanitizes recent fork tool calls;
- may inject a fork-specific child system prompt;
- may run in worktrees;
- produces implementation handoffs;
- may deserve tmux-pane visibility more than normal subagents.

Do not include fork in the initial builtin agent list. Track it as a separate design/implementation later.

---

## 6. Agent definitions

### 6.1 File format

Use markdown files with YAML-like frontmatter, inspired by Pi subagents.

Example:

```md
---
name: scout
description: Fast read-only codebase reconnaissance
type: scout
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
backgroundAllowed: true
foregroundAllowed: true
parallelAllowed: true
---

You are a scout. Explore read-only and return dense concrete findings with file paths,
classes, methods, risks, and recommendations. Do not edit files.
```

### 6.2 Discovery locations

Recommended discovery order:

```text
builtin agents bundled with Hatfield
user agents under ~/.hatfield/agents/
user agents under ~/.agents/
project agents under .hatfield/agents/
project agents under .agents/
```

`.agents/` support is first-class, not a legacy fallback. Project definitions should override user definitions by name. Hatfield-native `.hatfield/agents/` and cross-tool `.agents/` definitions should both be supported with deterministic precedence documented in `docs/agents.md`. Builtins should be lowest precedence unless a definition is protected/reserved.

Do not recreate `.hatfield.example/`.

### 6.3 Frontmatter fields

Recommended typed fields:

| Field | Type | Required | Purpose |
|---|---:|---:|---|
| `name` | string | yes | Unique agent name. |
| `description` | string | yes | Human-readable description and catalog text. |
| `type` | enum | yes | `scout`, `reviewer`, `researcher`, `worker`, or custom. |
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
| `backgroundAllowed` | bool | no | Whether background launches are allowed. |
| `foregroundAllowed` | bool | no | Whether foreground launches are allowed. |
| `parallelAllowed` | bool | no | Whether the agent can be launched as part of parallel execution. |
| `disabled` | bool | no | Disable definition without deleting it. |
| `handoffFormat` | string|null | no | Optional named handoff template. |

Unknown fields should be rejected or warned on during catalog validation. Prefer strict validation to avoid silent misconfiguration.

### 6.4 Builtin agents

Initial builtin agents:

#### `scout`

Purpose:

- codebase reconnaissance;
- architecture discovery;
- impact analysis;
- read-only findings.

Defaults:

- read-only tools;
- low/medium thinking;
- parallel allowed;
- background allowed;
- max depth 1.

#### `reviewer`

Purpose:

- code review;
- correctness/security/design risk analysis;
- validation of implementation diffs.

Defaults:

- read-only tools;
- higher thinking;
- foreground and background allowed;
- max depth 1.

#### `researcher`

Purpose:

- web/docs/MCP research;
- changelog/library investigation;
- external API lookup.

Defaults:

- web/MCP/documentation tools when enabled;
- no file editing;
- background allowed;
- max depth 1.

#### `worker`

Purpose:

- general execution where implementation-capable tools are intentionally allowed.

Defaults:

- more restricted than parent unless explicitly configured;
- not recursively allowed by default;
- use carefully with task workflow.

#### Excluded: `fork`

Do not include `fork` as a builtin agent in this milestone.

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

The resolved policy must be persisted in hidden run metadata so replay/debugging can explain which tools were available.

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

### 7.3 SafeGuard compatibility

Agent tool calls must pass through normal tool execution and extension hook paths. SafeGuard should not need a special subagent-only implementation.

If a child agent invokes a risky tool and SafeGuard requires approval:

```text
child run enters WaitingHuman
  → parent agent registry marks child as requires_attention
  → main TUI compact status shows attention indicator
  → agent view displays the pending approval/question
  → user answers
  → answer routes to child run
  → child continues
```

This depends on QH/HITL infrastructure being complete.

---

## 8. Steering and control

Agents must support steering.

Control operations:

- steer agent with additional instructions;
- cancel agent;
- answer pending human question;
- retrieve result artifact;
- list agents for current parent session;
- inspect agent status/progress;
- optionally resume/retry failed agent later.

App-layer service methods might look like:

```php
interface AgentControlServiceInterface
{
    public function launch(AgentLaunchRequestDTO $request): AgentLaunchResultDTO;
    public function steer(string $parentRunId, string $agentRunId, string $instructions): void;
    public function cancel(string $parentRunId, string $agentRunId): void;
    public function answerHuman(string $parentRunId, string $agentRunId, AgentHumanAnswerDTO $answer): void;
    public function status(string $parentRunId, string $agentRunId): AgentStatusDTO;
    public function list(string $parentRunId): AgentListDTO;
    public function retrieveArtifact(string $parentRunId, string $artifactId): AgentArtifactDTO;
}
```

Implementation should delegate to existing `AgentRunner`/runtime commands where possible:

- steer → `AgentRunner::steer()` / runtime `UserCommand::steer`;
- cancel → `AgentRunner::cancel()`;
- answer → `AgentRunner::answerHuman()` / runtime `UserCommand::answer_human`.

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
  <artifact_id>.json
  <artifact_id>.md
```

A DB-backed registry can be added if needed, but file-backed session-local storage is acceptable for v1 if it follows locking and replay conventions.

### 9.3 Registry entry shape

```json
{
  "artifact_id": "agent_01HX...",
  "parent_run_id": "parent-run-id",
  "agent_run_id": "child-run-id",
  "agent_name": "scout",
  "agent_type": "scout",
  "mode": "background",
  "status": "completed",
  "attention_state": "none",
  "created_at": "2026-06-15T12:00:00Z",
  "started_at": "2026-06-15T12:00:01Z",
  "completed_at": "2026-06-15T12:01:30Z",
  "summary": "Short human-readable summary",
  "handoff_path": "artifacts/agents/agent_01HX.md",
  "metadata_path": "artifacts/agents/agent_01HX.json",
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

Expose three model-visible tools to the parent LLM and TUI-facing command handlers:

- `agent_start` — launch one or more agents in foreground/background mode.
- `agent_status` — list agents for the current parent session or inspect one agent by `agent_run_id`/`artifact_id`.
- `agent_retrieve` — retrieve a completed handoff/artifact by `artifact_id` or `agent_run_id`.

Do not use one broad router tool for v1. Three focused schemas should be easier for models to call correctly and easier for SafeGuard/tool policy to reason about.

Control operations that are primarily interactive (`steer`, `cancel`, `answer question`) can be implemented through TUI commands/controllers and app-layer services first. If model-visible control becomes necessary later, add focused tools instead of expanding `agent_status` into a catch-all router.

---

## 10. Runtime/domain event model

### 10.1 AgentCore additions

Add explicit state/events instead of overloading `WaitingHuman`.

Potential `RunStatus` addition:

```php
enum RunStatus
{
    case WaitingAgent;
}
```

Potential command kind additions:

```php
enum CoreCommandKind
{
    case AgentResult;
    case AgentFailed;
}
```

Potential parent-run event types should be minimal:

```php
enum RunEventTypeEnum
{
    case AgentWaiting;          // parent is waiting for a foreground child result
    case AgentResultReceived;   // foreground child result was injected into parent
}
```

Do **not** add AgentCore events for every child progress/update. The child run already has its own canonical `events.jsonl`, and those events should remain the detailed source of truth. Keep AgentCore payloads generic and app-independent. Do not let AgentCore depend on `CodingAgent\Agent` classes.

### 10.2 App/runtime protocol and child event streaming

Do not mirror every child event into the parent runtime stream. A hidden child run is still a normal run with its own runtime events.

For the dedicated agent view:

```text
selected child run id
  → AgentControlService validates parent/child relationship
  → child session events are replayed through the normal RuntimeEventTranslator
  → RuntimeEvent stream keeps runId = child_run_id and includes parent_run_id/agent metadata
  → TUI agent view projects those events into the selected-agent panel
```

The main parent chat view should use the parent-scoped registry snapshot for compact status. It should not process every child token/tool/progress event unless the user has opened the agent view and selected that child.

Runtime protocol additions, if needed, should be query/stream APIs rather than many new event types:

```php
AgentSessionClient::agents(string $parentRunId): AgentListSnapshotDTO;
AgentSessionClient::agentEvents(string $parentRunId, string $agentRunId, int $afterSeq): AgentEventSliceDTO;
AgentSessionClient::agentArtifact(string $parentRunId, string $artifactId): AgentArtifactPreviewDTO;
```

The process controller can implement these by streaming existing child runtime events with `parent_run_id` metadata and letting the TUI filter/route them. Replay is simply rebuilding the selected agent view from the child run's own event stream.

### 10.3 Foreground result injection

For foreground mode, parent run is waiting for a specific child run/artifact. When the child completes:

1. `AgentSupervisor` writes/updates artifact.
2. It dispatches a parent command or event carrying a synthetic tool result payload.
3. Parent `ToolCallResultHandler`/new handler records the result for the original agent tool call.
4. Parent transitions out of `WaitingAgent` and continues.

Implementation details need careful design around idempotency and CAS retries. The supervisor command must be safe to retry.

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
    AgentDefinitionLoader.php
    AgentDefinitionCatalog.php
    AgentFrontmatterParser.php
    AgentDefinitionValidator.php
    AgentTypeEnum.php

  Launch/
    AgentLaunchRequestDTO.php
    AgentLaunchResultDTO.php
    AgentExecutionModeEnum.php
    AgentLaunchService.php
    AgentDepthGuard.php
    AgentToolPolicyResolver.php
    AgentPromptBuilder.php

  Runtime/
    AgentRunSupervisor.php
    AgentRunStatusTracker.php
    AgentCompletionNotifier.php
    AgentForegroundWaitStore.php

  Tool/
    AgentToolProvider.php
    AgentStartTool.php
    AgentStatusTool.php
    AgentRetrieveTool.php

  Tui/        # only if not placed under src/Tui/Agent
```

Recommended TUI namespace:

```text
src/Tui/Agent/
  AgentViewController.php
  AgentViewWidget.php
  AgentListWidget.php
  AgentDetailWidget.php
  AgentArtifactPreviewWidget.php
  AgentViewCommandHandler.php
  AgentStatusCompactWidget.php
```

Recommended core additions should stay in `src/AgentCore/` only for generic run statuses/events/commands. Avoid app-specific agent DTOs in AgentCore.

---

## 12. Agent launch flow

### 12.1 Launch request DTO

```php
final readonly class AgentLaunchRequestDTO
{
    /** @param list<AgentLaunchTaskDTO> $tasks */
    public function __construct(
        public string $parentRunId,
        public string $agentName,
        public string $task,
        public AgentExecutionModeEnum $mode,
        public array $tasks = [],
        public ?string $cwd = null,
        public ?string $model = null,
        public ?string $thinking = null,
        public ?int $concurrency = null,
        public array $metadata = [],
    ) {}
}
```

For parallel execution, either allow a top-level `tasks` list in one tool call or provide a separate `agent_parallel` tool. Use the Pi subagents shape as inspiration but keep the PHP DTO strict.

### 12.2 Launch algorithm

```text
AgentStartTool invoked
  → parse/validate parameters
  → AgentDefinitionCatalog resolves agent definition
  → AgentDepthGuard checks recursion limits
  → AgentToolPolicyResolver resolves allowed tools/MCP
  → AgentArtifactRegistry creates pending artifact entry
  → hidden child session/run is created with persisted hidden flag + metadata
  → AgentPromptBuilder builds child system/task prompt
  → AgentRunner starts child run
  → AgentRunSupervisor starts tracking child
  → if foreground: parent transitions to WaitingAgent
  → if background: tool returns handle/artifact id immediately
```

### 12.3 Hidden child session creation

Hidden child sessions must include enough metadata for registry and debugging:

```json
{
  "hidden": true,
  "kind": "agent_child",
  "parent_run_id": "...",
  "root_run_id": "...",
  "agent_name": "scout",
  "agent_type": "scout",
  "agent_depth": 1,
  "artifact_id": "...",
  "launch_mode": "background",
  "tool_policy": {
    "tools": ["read", "ide_find_file"],
    "mcp": {"mode": "none", "tools": []}
  }
}
```

If `HatfieldSession` already has `parentId`/`rootId` columns, use them where appropriate. Add/use a persisted hidden flag/column and also duplicate the hidden marker in metadata. Regular session catalog queries must exclude hidden child runs by default.

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

Initial shape can mirror Pi subagents:

```json
{
  "tasks": [
    {"agent": "scout", "task": "Inspect runtime events"},
    {"agent": "scout", "task": "Inspect TUI view options"},
    {"agent": "reviewer", "task": "Review proposed API"}
  ],
  "concurrency": 3,
  "mode": "background"
}
```

Rules:

- Enforce global and per-parent concurrency caps.
- Enforce per-agent `parallelAllowed`.
- Each child gets its own hidden run and artifact id.
- Parallel foreground means parent waits for all child results before resuming, unless a partial-failure policy is explicitly chosen.
- Parallel background returns all handles/artifact ids immediately.

Suggested settings:

```yaml
agents:
  max_concurrent_per_parent: 4
  max_concurrent_global: 8
  default_parallel_concurrency: 4
```

---

## 15. TUI design

### 15.1 Main chat compact status

Add compact agent status visible in the main chat view.

Examples:

```text
Agents: scout#2 running · reviewer#1 completed
Agents: worker#4 requires attention — open Agents view
```

This can be a status panel entry or a widget above/below editor.

### 15.2 Dedicated agent view

Open via slash command and keybinding, for example:

```text
/agents
Ctrl+A or another available shortcut
```

The actual keybinding must be chosen after checking current hotkeys and must be documented in `/hotkeys`.

View contents:

```text
┌ Agents for current session ───────────────────────────────┐
│ scout     running             00:12    artifact pending    │
│ reviewer  completed           01:03    artifact agent_x    │
│ worker    requires attention  00:44    approval pending    │
├ Selected: worker ─────────────────────────────────────────┤
│ Status/progress/events                                      │
│ Latest event: SafeGuard approval requested                  │
│                                                             │
│ Handoff/artifact preview or pending question                │
│                                                             │
│ Controls: steer | cancel | answer | retrieve | close        │
└─────────────────────────────────────────────────────────────┘
```

Implementation options:

1. Overlay mounted through `ChatScreen::insertOverlayAfterEditor()` or `insertOverlayBeforeEditor()`.
2. Dedicated in-process view controller that temporarily replaces a major layout region.
3. Future tmux monitor pane.
4. Future Symfony tabs if/when tab widget support is available and suitable.

For the first production implementation, prefer option 1 or 2. Avoid tmux as the default for subagents.

### 15.3 Event source for selected agent view

The selected-agent panel should rebuild from the child run's own events, not from duplicated parent events.

When the agent view opens or the selected child changes:

```text
agent registry gives child_run_id
  → TUI asks runtime/app contract for child events after seq 0
  → controller validates child belongs to current parent
  → existing RuntimeEventTranslator maps child AgentCore events to RuntimeEvent DTOs
  → agent view uses a dedicated projector/state for that selected child
```

For live updates, poll/stream only the selected child run after the last seen child seq. Compact list rows use the registry snapshot and do not need the full event stream for every child.

### 15.4 Attention flow

When a child agent needs human input or SafeGuard approval:

- compact status shows `requires attention` based on the registry/status snapshot;
- agent view highlights the child;
- selected detail replays/streams the child event that produced the question/approval;
- answering routes to the child run through `AgentControlService`;
- child continues;
- parent TUI updates from registry status and selected-child event stream.

### 15.5 TUI validation requirement

Any TUI implementation must include a real TUI E2E test using the project `TmuxHarness` and real test LLM endpoint, following `tests/AGENTS.md` and the `testing` skill.

Do not accept service-only tests as proof for user-visible agent view behavior.

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
  default_mode: foreground
  artifacts:
    retention_days: 14
  ui:
    mode: overlay # overlay|tmux|none, future: tabs
    compact_status: true
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
- Hidden child metadata must not leak raw prompts/tool outputs into logs.
- Runtime logs must use structured event-style fields and avoid raw prompts, tool output, environment values, API keys, and full session content by default.
- Agent artifacts may contain sensitive content; store under session directory and do not expose globally.
- Background completion notifications should contain summaries/ids, not full raw outputs unless explicitly intended.

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
- AgentCore/application tests for `WaitingAgent` state transitions;
- runtime protocol tests for agent events;
- TUI E2E tests for dedicated agent view and attention/completion display;
- end-to-end validation for foreground and background agent launches.

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

The dedicated agent view is a TUI feature. It is not complete until there is an automated TUI E2E test using:

- real `TmuxHarness`;
- real `llama_cpp_test/test` endpoint on port 9052;
- isolated `var/tmp/test-{uuid}` directories;
- snapshot/assertion proving the feature works through interactive TUI behavior.

A service test, mocked runtime, or manual smoke script is not enough.

---

## 19. Staged implementation roadmap

### Stage -1 — Throwaway POC/spike

Before production implementation, run a deliberately throwaway POC to test the risky shape of the system. This is **not** an MVP and should not be merged as the foundation for production work.

Purpose:

- prove that a hidden child run can be started and supervised;
- prove that a parent-scoped file registry can track the child;
- prove that a TUI overlay can select a child run and replay/stream its own events;
- discover state-machine and TUI projection problems before committing to production structure.

POC constraints:

- hardcode one agent definition, likely `scout`;
- skip custom discovery, settings polish, MCP policy, and full builtin catalog;
- skip compatibility/fallback layers;
- accept ugly internal seams if they help answer the architectural question;
- do not add production APIs solely to support the spike;
- do not preserve the spike implementation after the architecture is understood;
- write down findings and delete/rewrite the code for production.

The POC should validate the architecture, not become a fallback path. The production implementation should then change structure cleanly based on what the spike proves.

### Stage 0 — Finish prerequisites

Complete and merge:

```text
QH track: qh-04 → qh-09
MCP track: mcp-01 → mcp-06
```

Do not start agents implementation before these are complete unless the user explicitly reprioritizes.

### Stage 1 — Production RFC/task breakdown

Use the POC findings to create concrete production task files for the agents project. Do not copy spike shortcuts into production. Suggested tasks:

```text
AGENTS-01 Agent definitions, catalog, settings, and docs
AGENTS-02 Session-scoped artifact registry and hidden child run metadata
AGENTS-03 Agent launch service, tool policy, and recursion guard
AGENTS-04 Foreground WaitingAgent state and result injection
AGENTS-05 Background agent completion notification and retrieval tools
AGENTS-06 Parallel agent execution and concurrency caps
AGENTS-07 Dedicated TUI agent view and compact status widget
AGENTS-08 Builtin agents, prompt/tool docs, and final E2E validation
```

Fork should be a later separate track, for example:

```text
FORK-01 Native fork design using hidden child runs and artifacts
```

### Stage 2 — Agent definitions/catalog

Deliverables:

- `AgentDefinitionDTO`
- `AgentDefinitionLoader`
- `AgentFrontmatterParser`
- `AgentDefinitionCatalog`
- validation errors with actionable messages
- builtin definitions for `scout`, `reviewer`, `researcher`, `worker`
- settings/docs updates

Validation:

- unit tests;
- `castor phpstan`;
- `castor test` focused where applicable.

### Stage 3 — Artifact registry and hidden run metadata

Deliverables:

- session-scoped artifact storage;
- registry read/write/update with locking;
- hidden child session/run metadata support;
- normal session listing excludes hidden child runs;
- explicit retrieval APIs.

Validation:

- integration tests for registry;
- session catalog tests proving hidden runs do not appear in `/sessions`;
- `castor test`.

### Stage 4 — Launch service and recursion guard

Deliverables:

- `AgentLaunchService`;
- `AgentDepthGuard`;
- tool/MCP policy resolver;
- hidden child run start;
- persisted metadata and env guard setup;
- status tracking.

Validation:

- unit/integration tests for depth and policy;
- launch tests with stubbed/minimal runtime where appropriate;
- `castor deptrac` to verify boundaries.

### Stage 5 — Foreground mode

Deliverables:

- `WaitingAgent` state or equivalent explicit parent wait state;
- foreground wait store;
- child completion result injection;
- idempotent retry-safe supervisor command;
- parent resumes with agent result as tool result.

Validation:

- AgentCore/application pipeline tests;
- runtime tests;
- focused E2E if LLM-visible behavior changes.

### Stage 6 — Background mode and completion notification

Deliverables:

- background launch returns agent handle/artifact id immediately;
- completion notification persisted to parent session;
- retrieval tool/API;
- compact TUI status updates from runtime/registry;
- no mid-run background handoff in the first production version.

Validation:

- background completion tests;
- resume/replay tests for completion notification;
- TUI status test if user-visible.

### Stage 7 — Parallel execution

Deliverables:

- parallel launch shape;
- concurrency caps;
- aggregate foreground result behavior;
- aggregate background handle result behavior;
- per-child artifacts.

Validation:

- concurrency tests;
- partial failure behavior tests;
- registry consistency tests.

### Stage 8 — Dedicated TUI agent view

Deliverables:

- `/agents` command;
- hotkey catalog entry;
- agent view overlay/controller/widgets;
- list/tree;
- selected detail/progress/events;
- artifact preview;
- controls for steer/cancel/answer/retrieve;
- compact main status widget/row.

Validation:

- `castor test:tui`;
- real TmuxHarness E2E snapshot/assertion;
- `LLM_MODE=true castor check` before code review.

### Stage 9 — Docs and final validation

Deliverables:

- `docs/agents.md`;
- `docs/settings.md` updates;
- prompt/tool docs updates;
- examples for builtin and custom agents;
- final full validation.

Validation:

```bash
LLM_MODE=true castor check
```

---

## 20. Future fork plan boundary

Fork should be designed after normal agents are complete.

Expected fork relationship to this plan:

- reuse hidden child run infrastructure;
- reuse artifact registry;
- reuse foreground/background modes;
- reuse completion notification;
- reuse parts of agent view where appropriate.

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

1. Hidden child runs should be represented by **both**:
   - a persisted `hidden` column/flag on `HatfieldSession`; and
   - metadata in the hidden child session/run record.

2. Foreground agent waits should use an explicit `WaitingAgent` run status.
   - Do not overload `WaitingHuman` or generic interrupt details for foreground agent waits.

3. The artifact registry is file-backed for v1.
   - Store it under the parent session directory with locking and a clear future DB migration path.

4. Use three model-visible tools for v1:
   - `agent_start`;
   - `agent_status`;
   - `agent_retrieve`.

5. Selected child run events should come from the child run's own event stream.
   - Hard requirement: do not let TUI read AgentCore stores directly.
   - Do not mirror every child event into the parent session; that would duplicate data and increase event volume unnecessarily.
   - The controller/runtime layer should expose a parent-validated child event stream: selected `agent_run_id` events are replayed/streamed as normal `RuntimeEvent` DTOs with `runId = child_run_id` and `parent_run_id`/agent metadata for routing/filtering.
   - When the agent view opens, rebuild the selected child panel from the child session's own events. For live updates, poll/stream only that selected child after the last seen seq.
   - Compact parent status rows should use the file-backed registry snapshot, not the full child event stream.

6. Parallel foreground partial failures should use per-child artifacts with clear success/failure states.
   - Aggregate summaries must not silently collapse failures into success.

7. `.agents/` support is first-class.
   - Support project `.agents/` and user `~/.agents/` alongside Hatfield-native `.hatfield/agents/` locations with deterministic documented precedence.

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

- builtin agents can be listed/launched;
- custom agent definitions can be loaded and validated;
- a foreground scout can run as a hidden child and return a result to the parent;
- a background scout can run, notify completion, and expose its artifact for retrieval;
- child agents do not appear in normal `/sessions`;
- artifacts are session-scoped and retrievable after resume;
- recursion guard prevents accidental nested agent explosions;
- SafeGuard/`ask_human` attention from child agents is visible and answerable from parent TUI;
- per-agent tool/MCP policy is enforced;
- parallel launches respect concurrency limits;
- dedicated agent view works through real TUI E2E;
- `LLM_MODE=true castor check` passes.
