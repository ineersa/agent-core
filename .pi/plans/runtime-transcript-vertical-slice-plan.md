# Runtime transcript projection and real chat vertical slice plan

Date: 2026-05-17

## Purpose

This plan refines roadmap items 3 and 4:

3. Define the stable runtime event/block projection contract used by the TUI transcript.
4. Implement a minimal real chat vertical slice: user prompt -> real model call -> streamed assistant response -> persisted session/runtime events -> basic transcript rendering.

This does **not** replace AgentCore's run store, event store, or session storage. AgentCore remains the source of truth. The work here is a runtime/TUI projection layer so presentation code can render stable transcript blocks without importing AgentCore internals or decoding arbitrary domain event payloads.

## Current architecture constraints

- `session_id === run_id`.
- Session directories live under `.hatfield/sessions/<id>/`.
- Canonical AgentCore files: `events.jsonl` and `state.json`.
- Projection files: `transcript.jsonl` and `runtime-events.jsonl`.
- `src/Tui/` may depend only on `CodingAgent/Runtime/Contract`, `CodingAgent/Runtime/Protocol`, and Symfony TUI. It must not import AgentCore Application/Infrastructure/Messenger.
- `RuntimeEvent` is already the transport DTO:

```php
new RuntimeEvent(
    type: string,
    runId: string,
    seq: int,
    payload: array,
    v: 1,
)
```

- `RuntimeEventMapper` currently passes AgentCore `RunEvent` type/payload through mostly unchanged.
- `RuntimeEventPoller` currently formats raw runtime events directly into one-line `TranscriptEntry` values. This is the part that should evolve into a stable transcript projection.

## Pi repo scouting notes

Scouts inspected `/home/ineersa/claw/pi-mono` for event and rendering patterns.

Useful Pi references:

- `packages/ai/src/types.ts`
- `packages/ai/src/utils/event-stream.ts`
- `packages/agent/src/types.ts`
- `packages/agent/src/agent-loop.ts`
- `packages/agent/src/agent.ts`
- `packages/web-ui/src/components/AgentInterface.ts`
- `packages/web-ui/src/components/StreamingMessageContainer.ts`
- `packages/web-ui/src/components/Messages.ts`
- `packages/web-ui/src/components/ThinkingBlock.ts`
- `packages/coding-agent/src/modes/interactive/interactive-mode.ts`
- `packages/coding-agent/src/modes/interactive/components/assistant-message.ts`
- `packages/coding-agent/src/modes/interactive/components/tool-execution.ts`
- `packages/tui/src/components/markdown.ts`

Pi's central idea:

```ts
type AssistantMessageEvent =
  | { type: 'start'; partial: AssistantMessage }
  | { type: 'text_start'; contentIndex: number; partial: AssistantMessage }
  | { type: 'text_delta'; contentIndex: number; delta: string; partial: AssistantMessage }
  | { type: 'text_end'; contentIndex: number; content: string; partial: AssistantMessage }
  | { type: 'thinking_start'; contentIndex: number; partial: AssistantMessage }
  | { type: 'thinking_delta'; contentIndex: number; delta: string; partial: AssistantMessage }
  | { type: 'thinking_end'; contentIndex: number; content: string; partial: AssistantMessage }
  | { type: 'toolcall_start'; contentIndex: number; partial: AssistantMessage }
  | { type: 'toolcall_delta'; contentIndex: number; delta: string; partial: AssistantMessage }
  | { type: 'toolcall_end'; contentIndex: number; toolCall: ToolCall; partial: AssistantMessage }
  | { type: 'done'; reason: StopReason; message: AssistantMessage }
  | { type: 'error'; reason: StopReason; error: AssistantMessage };
```

Pi separates:

1. low-level assistant message stream events,
2. higher-level agent lifecycle events,
3. rendered transcript/message blocks.

Important design lessons to port:

- Use an ordered content/block array for assistant messages: text, thinking, tool calls.
- Streaming events may include both the delta and the full partial message/block state.
- Keep completed transcript blocks separate from the currently streaming block.
- Tool execution events are separate from assistant text deltas and keyed by `toolCallId`.
- Session replay should rebuild the same projection from persisted events.

## Symfony AI streaming compatibility notes

A scout inspected `/home/ineersa/projects/ai`. Symfony AI already provides most low-level model-stream concepts we need. We should not invent a parallel provider-level delta model.

Useful Symfony AI references:

- `src/platform/src/Result/Stream/ListenerInterface.php`
- `src/platform/src/Result/Stream/StartEvent.php`
- `src/platform/src/Result/Stream/DeltaEvent.php`
- `src/platform/src/Result/Stream/CompleteEvent.php`
- `src/platform/src/Result/Stream/Delta/DeltaInterface.php`
- `src/platform/src/Result/Stream/Delta/TextDelta.php`
- `src/platform/src/Result/Stream/Delta/ThinkingStart.php`
- `src/platform/src/Result/Stream/Delta/ThinkingDelta.php`
- `src/platform/src/Result/Stream/Delta/ThinkingComplete.php`
- `src/platform/src/Result/Stream/Delta/ThinkingSignature.php`
- `src/platform/src/Result/Stream/Delta/ToolCallStart.php`
- `src/platform/src/Result/Stream/Delta/ToolInputDelta.php`
- `src/platform/src/Result/Stream/Delta/ToolCallComplete.php`
- `src/platform/src/Result/Stream/Delta/MetadataDelta.php`
- `src/platform/src/TokenUsage/TokenUsage.php`
- `src/platform/src/Result/StreamResult.php`
- `src/platform/src/Result/DeferredResult.php`

Important Symfony AI delta/event concepts:

- `StreamResult` wraps a generator and dispatches `StartEvent`, `DeltaEvent`, and `CompleteEvent` to listeners.
- `TextDelta` carries assistant text increments.
- `ThinkingStart`, `ThinkingDelta`, `ThinkingComplete`, and `ThinkingSignature` cover reasoning/thinking stream state.
- `ToolCallStart`, `ToolInputDelta`, and `ToolCallComplete` cover tool-call argument streaming and final tool-call readiness.
- `MetadataDelta` carries provider metadata such as citations/grounding.
- `TokenUsage` implements both metadata and `DeltaInterface`; Symfony AI stream listeners promote token usage into result metadata.
- Provider bridges normalize provider-specific SSE/NDJSON into these delta classes.

Current AgentCore already consumes several of these in `src/AgentCore/Infrastructure/SymfonyAi/LlmPlatformAdapter.php`:

- `TextDelta`
- `ThinkingDelta`
- `ThinkingSignature`
- `ThinkingComplete`
- `ToolCallStart`
- `ToolInputDelta`
- `ToolCallComplete`
- `TokenUsageInterface` metadata

Recommendation:

- Reuse Symfony AI delta classes at the provider adapter boundary.
- Do not expose Symfony AI classes directly to `src/Tui/` or persist them as the public runtime contract.
- Map Symfony AI deltas into AgentCore messages/results and CodingAgent `RuntimeEvent` / transcript projection events.
- Keep cancellation in AgentCore/CodingAgent because Symfony AI's stream abstraction is generator/listener based and does not itself define run/turn cancellation semantics. AgentCore can still abort the underlying `RawHttpResult` where available.

## Target architecture

```text
Symfony AI StreamResult/DeltaInterface
        |
        v
AgentCore LlmPlatformAdapter
        |
        v
AgentCore RunEvent stream / RunStore
        |
        v
CodingAgent RuntimeEventMapper
        |
        v
RuntimeEvent stream + runtime-events.jsonl
        |
        v
TranscriptProjector / RuntimeProjection
        |
        v
TranscriptBlock model + transcript.jsonl
        |
        v
TUI Transcript widgets/renderers
```

AgentCore owns truth and ordering. Symfony AI owns provider stream normalization. Runtime/TUI owns presentation-safe projections.

## Item 3: Define stable runtime event/block projection contract

### Goal

Create a stable contract for runtime events and transcript blocks before building richer transcript rendering. The goal is not to invent new persistence; it is to normalize AgentCore events into presentation-safe events/blocks.

### Proposed normalized runtime event families

Names are intentionally presentation/runtime oriented and should be emitted by `RuntimeEventMapper` or a projection service, not by TUI widgets.

For model-stream events, these are projection names over Symfony AI deltas, not a replacement for Symfony AI's `DeltaInterface` hierarchy.

#### Run/turn lifecycle

- `run.started`
- `turn.started`
- `turn.completed`
- `turn.failed`
- `turn.cancelled`
- `run.completed`
- `run.failed`
- `run.cancelled`

#### User input

- `user.message_submitted`

Payload:

```php
[
    'message_id' => '...',
    'text' => '...',
]
```

#### Assistant message stream

- `assistant.message_started` — projected from model invocation / stream start
- `assistant.text_started` — optional projection when first `TextDelta` creates a text block
- `assistant.text_delta` — projected from Symfony AI `TextDelta`
- `assistant.text_completed` — projected when a text block is finalized
- `assistant.thinking_started` — projected from Symfony AI `ThinkingStart` or first `ThinkingDelta`
- `assistant.thinking_delta` — projected from Symfony AI `ThinkingDelta`
- `assistant.thinking_completed` — projected from Symfony AI `ThinkingComplete`
- `assistant.message_completed` — projected from final assistant message / stream completion
- `assistant.message_failed` — projected from provider/adapter error result

Recommended payload shape:

```php
[
    'message_id' => '...',
    'content_index' => 0,
    'block_id' => '...',
    'delta' => '...',              // for *_delta
    'text' => '...',               // for completed text block / final snapshot, optional
    'model' => 'provider/model',
    'stop_reason' => 'stop|length|tool_use|error|aborted',
]
```

Symfony AI exposes stream deltas, not Pi-style full partial assistant messages on every event. For v1, runtime events should preserve those deltas plus stable IDs. The `TranscriptProjector` should accumulate the current partial assistant/thinking/tool-call state in memory and on replay. Emit full text/message snapshots only at completion or explicit checkpoint events, not on every delta.

#### Tool call lifecycle

- `tool_call.started` — projected from Symfony AI `ToolCallStart`
- `tool_call.arguments_delta` — projected from Symfony AI `ToolInputDelta`
- `tool_call.arguments_completed` — projected from Symfony AI `ToolCallComplete`
- `tool_execution.started` — projected from AgentCore tool execution state, not Symfony AI provider stream
- `tool_execution.output_delta` — projected from cancellable tool execution when available
- `tool_execution.completed` — projected from AgentCore tool result
- `tool_execution.failed` — projected from AgentCore tool result/error
- `tool_execution.cancelled` — projected from AgentCore cancellation/tool result

Recommended payload:

```php
[
    'tool_call_id' => '...',
    'tool_name' => 'bash',
    'arguments' => [...],          // complete args when available
    'delta' => '...',              // streaming args/output when available
    'result' => '...',             // final rendered/capped result
    'is_error' => false,
    'duration_ms' => 123,
    'cancelled' => false,
    'timed_out' => false,
]
```

#### Progress/status

- `progress.updated`
- `status.updated`

Recommended payload:

```php
[
    'scope' => 'model|tool|session|compaction',
    'message' => '...',
    'percent' => null,
    'indeterminate' => true,
]
```

#### Question/approval/human input

There are two different concepts that should share widget/schema conventions but not be conflated:

1. **Local TUI questions** — presentation/application prompts owned by the TUI or CodingAgent UI layer. Examples: command still running, move to background?; change model?; confirm local setting change? These are not AgentCore run events and should not be persisted in transcript projection.
2. **AgentCore human-in-the-loop requests** — agent/tool requests that pause the run and require a human answer before the agent can continue. These are part of run state, replay, and transcript projection.

The transcript runtime contract in this plan covers only concept 2. The TUI can still reuse the same visual `QuestionWidget` / `ApprovalWidget` components for concept 1 by feeding them a local request DTO, but local UI prompts must not write `transcript.jsonl`, must not become runtime transcript blocks, and should not use `CoreCommandKind::HumanResponse` unless they correspond to an AgentCore `waiting_human` state.

AgentCore already has the core human-response loop for concept 2:

- `RunStatus::WaitingHuman`
- `CoreCommandKind::HumanResponse`
- runtime `UserCommand` type `answer_human`
- `ToolCallResultHandler` emits `waiting_human` when an interrupt-mode tool result contains interrupt details
- `ApplyCommandHandler::applyHumanResponseCommand()` accepts the answer only while the run is `WaitingHuman`, appends a human-response message, transitions back to `Running`, and advances the run

So the runtime/TUI projection should normalize that existing flow rather than inventing another agent input path.

Normalized AgentCore HITL events:

- `human_input.requested` — projection of AgentCore `waiting_human`
- `human_input.answered` — projection of accepted `human_response` / `agent_command_applied`
- `human_input.rejected` — projection of rejected `human_response` / `agent_command_rejected`
- `approval.requested` — specialized view of `human_input.requested` when schema/kind indicates approval
- `approval.approved` — specialized accepted answer
- `approval.rejected` — specialized rejected/negative answer

Shared in-memory request shape for widgets. Only AgentCore HITL requests are transcript/projected runtime events:

```php
[
    'request_id' => '...',          // stable UI/request id; for HITL usually question_id
    'source' => 'tui|agent_core',
    'question_id' => '...',         // HITL only
    'kind' => 'question|approval|choice|confirm',
    'prompt' => '...',
    'schema' => ['type' => 'string'],
    'choices' => [...],
    'default' => null,
    'tool_call_id' => null,         // HITL/tool-originated only
    'tool_name' => null,
    'transcript' => true,           // must be true only for AgentCore HITL
]
```

For AgentCore HITL, the corresponding TUI command should call:

```php
$client->send($runId, new UserCommand(
    type: 'answer_human',
    payload: [
        'question_id' => $questionId,
        'answer' => $answer,
    ],
));
```

For local TUI questions, the answer should complete a local callback/future/action and should not go through `answer_human` unless the active run is actually waiting for human input. Local TUI questions are ephemeral UI state and should not be included in transcript replay.

For approvals, keep the same visual schema and encode the answer according to the request schema, for example `true|false`, `'approved'|'rejected'`, or a structured object. The routing differs by source: local approval invokes local UI/application behavior; AgentCore approval sends `answer_human` and is the only kind that appears in the transcript projection.

#### Cancellation/interruption

- `cancellation.requested`
- `operation.cancelled`
- `turn.cancelled`
- `run.cancelled`

Recommended payload:

```php
[
    'reason' => 'user_cancelled|timeout|provider_aborted|tool_cancelled',
    'operation_id' => '...',
    'operation_type' => 'model|tool|turn|run',
    'partial_output_available' => true,
]
```

#### Model/usage/cost metadata

These overlap with AI-13 and should be aligned with it:

- `model.changed`
- `reasoning.changed`
- `usage.updated`
- `context.updated`
- `cost.updated`

Recommended payload:

```php
[
    'provider' => 'zai',
    'model' => 'glm-5.1',
    'display' => 'zai/glm-5.1',
    'reasoning' => 'high',
    'input_tokens' => 123,
    'output_tokens' => 456,
    'total_tokens' => 579,
    'cost_usd' => 0.0123,
    'context_used' => 1000,
    'context_window' => 200000,
    'tokens_per_second' => 42.5,
]
```

### Transcript block model

Introduce a TUI-safe block model under `src/Tui/Transcript/` or, if it must be shared with runtime tests, under `src/CodingAgent/Runtime/Projection/` with only scalar/array DTOs.

Candidate blocks:

```php
enum TranscriptBlockKind: string
{
    case UserMessage = 'user_message';
    case AssistantMessage = 'assistant_message';
    case AssistantThinking = 'assistant_thinking';
    case ToolCall = 'tool_call';
    case ToolResult = 'tool_result';
    case Progress = 'progress';
    case Question = 'question';
    case Approval = 'approval';
    case Cancelled = 'cancelled';
    case Error = 'error';
    case System = 'system';
}
```

Block DTO shape:

```php
final readonly class TranscriptBlock
{
    public function __construct(
        public string $id,
        public TranscriptBlockKind $kind,
        public string $runId,
        public int $seq,
        public string $text = '',
        public array $meta = [],
        public bool $streaming = false,
        public bool $collapsed = false,
    ) {}
}
```

Projection rules:

- `user.message_submitted` creates a `UserMessage` block.
- `assistant.message_started` starts a streaming assistant group.
- `assistant.text_delta` appends to the active assistant text block.
- `assistant.thinking_delta` appends to the active thinking block.
- `tool_call.started` creates/updates a `ToolCall` block keyed by `tool_call_id`.
- `tool_execution.output_delta` appends to that tool's output block.
- `tool_execution.completed|failed|cancelled` finalizes the tool block.
- `human_input.requested` creates a `Question` block.
- `approval.requested` creates an `Approval` block.
- `turn.cancelled|run.cancelled` marks current streaming blocks incomplete and appends a `Cancelled` block.
- `usage.updated` updates footer/status projection, not necessarily transcript.

### Deliverables

- Document event names and payload schemas near `src/CodingAgent/Runtime/Protocol/`.
- Add constants/enums or small named constructors for event names to avoid string drift.
- Add `TranscriptProjector` tests: feed runtime events, assert stable block output.
- Update `RuntimeEventMapper` to normalize important AgentCore events instead of raw passthrough where needed.
- Keep raw AgentCore event names available only as debug metadata if useful.

### Dependencies / timing

Do this after the AI/provider tasks expose the real fields:

- AI-10 per-turn model/reasoning routing,
- AI-11 trace replay application tests,
- AI-13 footer/status model/usage/cost projection.

It can be designed before then, but implementation should avoid guessing payloads that AI tasks will soon clarify.

## Item 4: Minimal real chat vertical slice

### Goal

Prove the full path with minimal rendering:

```text
user prompt in
  -> AgentCore real model call
  -> streamed assistant response
  -> AgentCore events persisted
  -> runtime-events.jsonl projection persisted
  -> transcript blocks projected
  -> TUI renders user + streaming assistant text
```

### Scope

Keep this deliberately small. It is a vertical slice, not the final renderer.

Required:

- User submits prompt in TUI editor.
- Prompt is sent through `AgentSessionClient`.
- Real configured model is called through AgentCore/Symfony AI path.
- Assistant text streams or updates incrementally if provider supports streaming.
- Runtime events are appended to `.hatfield/sessions/<id>/runtime-events.jsonl`.
- Transcript projection is appended/updated in `.hatfield/sessions/<id>/transcript.jsonl`.
- TUI renders:
  - user message block,
  - assistant text block,
  - basic cancelled/error block if the turn is aborted/fails.

Optional if already available from AI tasks:

- basic thinking block,
- basic tool-call placeholder block,
- footer model/usage update.

Out of scope for this slice:

- rich markdown rendering beyond plain text/newlines,
- full tool widgets,
- approvals UI,
- compaction UI,
- branch/fork UI,
- final footer design,
- model picker.

### Suggested implementation steps

1. **Runtime event contract constants**
   - Add stable event-name constants/enums under `CodingAgent/Runtime/Protocol`.
   - Keep `RuntimeEvent` shape unchanged for JSONL/process compatibility.

2. **Transcript projection DTOs**
   - Add `TranscriptBlock` / `TranscriptBlockKind` or evolve existing `TranscriptEntry` if we want to stay minimal.
   - Prefer blocks over final rendered strings so renderers can improve later without changing persisted projection semantics.

3. **TranscriptProjector**
   - Input: ordered `RuntimeEvent`s.
   - Output: current list of `TranscriptBlock`s, plus incremental append/update operations if helpful.
   - Tests should cover text deltas, thinking deltas, tool lifecycle, cancellation, and replay idempotence.

4. **RuntimeEventPoller refactor**
   - Stop formatting raw events directly into one-line entries.
   - Poll events, persist them, feed them into `TranscriptProjector`, then update `TuiSessionState` transcript blocks/entries.

5. **Basic renderer**
   - Render `UserMessage`, `AssistantMessage`, `AssistantThinking`, `ToolCall`, `Cancelled`, and `Error` blocks.
   - Start plain; richer markdown/widget rendering can come later.

6. **Session replay**
   - On resume, load `runtime-events.jsonl` and rebuild transcript blocks.
   - `transcript.jsonl` can remain a cache/projection, but replay from runtime events should be possible and tested.

7. **Vertical slice test**
   - Prefer a trace replay/provider fixture from AI-11 for deterministic testing.
   - Assert user prompt, assistant response, runtime events, transcript blocks, and session files.

8. **Manual smoke**
   - Use `castor run:agent` against a configured local/remote model.
   - Verify prompt submission, streaming text, persisted events, resume display.

### Acceptance criteria

- TUI does not import AgentCore internals.
- Runtime event names and payloads used by transcript rendering are documented and tested.
- A user prompt produces a persisted runtime event sequence and visible assistant text.
- Resume rebuilds the same basic transcript from persisted runtime events.
- Cancellation/failure produces a visible non-generic block and does not corrupt the transcript.
- `castor deptrac` passes.
- Focused tests pass with `castor test` filters for runtime projection/session replay.

## Relationship to AI tasks

The AI tasks are prerequisites for real model data and metadata:

- AI-11 should validate AgentCore + runtime with replayed provider output.
- AI-13 should own footer/status metadata: model, reasoning, tokens, cost, context, tokens/sec.

This plan owns the transcript-facing projection and rendering backbone:

- assistant text/thinking/tool/cancel/question/approval blocks,
- rendering-friendly projection from runtime events,
- basic real chat transcript display.

## Open questions

1. Should runtime events include full `partial` assistant messages on every delta, Pi-style, or only deltas plus IDs?
   - Symfony AI exposes deltas (`TextDelta`, `ThinkingDelta`, `ToolInputDelta`, etc.), not full partial assistant messages.
   - Recommended v1: react to deltas, persist delta events with stable message/block/tool IDs, and let `TranscriptProjector` accumulate partial state.
   - Emit final/full snapshots on `assistant.message_completed`, `assistant.thinking_completed`, and `tool_call.arguments_completed` for easier validation and replay recovery.
   - Avoid persisting full partial assistant messages on every token unless a later UI performance/reconnect problem proves it necessary.

2. Should `TranscriptBlock` live in `Tui` or `CodingAgent/Runtime/Projection`?
   - If persisted/replayed by the app, put scalar DTOs in `CodingAgent`.
   - If purely render-only, keep in `Tui`.
   - Recommended: projection DTOs in `CodingAgent/Runtime/Projection`; widgets in `Tui`.

3. Should `transcript.jsonl` persist blocks or rendered entries?
   - Recommended: persist block/projection data, not ANSI/rendered strings.

4. How much of tool execution should appear in the first vertical slice?
   - Recommended: at least create placeholder/final blocks from tool lifecycle events, but do not build rich widgets until toolbox tasks land.

5. How should approval/question requests integrate with existing human response commands?
   - Keep two concepts separate:
     - local TUI questions/approvals for UI/application interaction, settings, and prompts such as moving a command to background;
     - AgentCore human-in-the-loop requests that pause a run and must be replayable/transcript-visible.
   - Reuse visual widgets and request schema where possible, but route answers differently based on source.
   - AgentCore HITL uses the existing interrupt path as source of truth: interrupt-mode tool result -> `waiting_human` event -> `RunStatus::WaitingHuman` -> runtime `answer_human` command -> `CoreCommandKind::HumanResponse`.
   - Project AgentCore `waiting_human` into `human_input.requested` and, when schema/kind indicates approval, `approval.requested`.
   - HITL blocks should carry `question_id`, `tool_call_id`, prompt, schema, choices/default, and current status (`pending|answered|rejected|cancelled`).
   - Answering HITL sends `UserCommand(type: 'answer_human', payload: ['question_id' => ..., 'answer' => ...])` through `AgentSessionClient`.
   - Local TUI questions complete local callbacks/actions and are never transcript blocks. Only AgentCore HITL questions/approvals appear in `transcript.jsonl`.
