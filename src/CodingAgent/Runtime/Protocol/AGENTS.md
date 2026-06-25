# Runtime protocol event names and payloads

This file documents the stable runtime event contract used by the TUI
rendering layer and JSONL transport. Every `RuntimeEvent.type` field
MUST use a value from `RuntimeEventTypeEnum`.

For the canonical enum definition, see `RuntimeEventTypeEnum.php`.

## Event families

### Run/turn lifecycle

| Constant | Event type string | Meaning |
|----------|-------------------|---------|
| `RunStarted` | `run.started` | Run created and transitioning to Running |
| `TurnStarted` | `turn.started` | New turn begun (agent message processing cycle) |
| `TurnCompleted` | `turn.completed` | Turn finished successfully |
| `TurnFailed` | `turn.failed` | Turn finished with an error |
| `TurnCancelled` | `turn.cancelled` | Turn aborted by cancellation |
| `RunCompleted` | `run.completed` | Run reached terminal Completed state |
| `RunFailed` | `run.failed` | Run reached terminal Failed state |
| `RunCancelled` | `run.cancelled` | Run aborted by cancellation |

Payload: none standardized yet (see `RuntimeEventMapper` normalization).

---

### User input

| Constant | Event type string | Meaning |
|----------|-------------------|---------|
| `UserMessageSubmitted` | `user.message_submitted` | User submitted a chat message |

Payload:

```php
[
    'message_id' => string,
    'text'       => string,
]
```

---

### Assistant message stream

Projected from Symfony AI provider deltas (`TextDelta`, `ThinkingDelta`,
`ToolCallStart`, etc.). These events carry deltas plus stable IDs; the
`TranscriptProjector` accumulates partial state and emits full snapshots
at completion.

| Constant | Event type string | Meaning |
|----------|-------------------|---------|
| `AssistantMessageStarted` | `assistant.message_started` | Model invocation / stream started |
| `AssistantTextStarted` | `assistant.text_started` | First TextDelta created a text block |
| `AssistantTextDelta` | `assistant.text_delta` | Incremental text token |
| `AssistantTextCompleted` | `assistant.text_completed` | Text block finalized |
| `AssistantThinkingStarted` | `assistant.thinking_started` | Thinking/reasoning block started |
| `AssistantThinkingDelta` | `assistant.thinking_delta` | Thinking token |
| `AssistantThinkingCompleted` | `assistant.thinking_completed` | Thinking block finalized |
| `AssistantMessageCompleted` | `assistant.message_completed` | Assistant message finalized (all blocks) |
| `AssistantMessageFailed` | `assistant.message_failed` | Provider/adapter error result |

Payload:

```php
[
    'message_id'    => string,
    'content_index' => int,     // 0-based index within message
    'block_id'      => string,  // stable block id for deltas
    'delta'         => ?string, // incremental token (for *_delta events)
    'text'          => ?string, // full text (for completed events)
    'model'         => ?string, // e.g. 'zai/glm-5.1'
    'stop_reason'   => ?string, // 'stop'|'length'|'tool_use'|'error'|'aborted'
]
```

---

### Tool call lifecycle

| Constant | Event type string | Meaning |
|----------|-------------------|---------|
| `ToolCallStarted` | `tool_call.started` | Provider emitted ToolCallStart |
| `ToolCallArgumentsDelta` | `tool_call.arguments_delta` | Streaming args fragment |
| `ToolCallArgumentsCompleted` | `tool_call.arguments_completed` | Full args assembled |
| `ToolExecutionStarted` | `tool_execution.started` | AgentCore began tool execution |
| `ToolExecutionOutputDelta` | `tool_execution.output_delta` | Streaming tool output |
| `ToolExecutionCompleted` | `tool_execution.completed` | Tool finished successfully |
| `ToolExecutionFailed` | `tool_execution.failed` | Tool returned an error |
| `ToolExecutionCancelled` | `tool_execution.cancelled` | Tool cancelled or timed out |

Payload:

```php
[
    'tool_call_id' => string,
    'tool_name'    => string,
    'arguments'    => ?array,  // complete args when available
    'delta'        => ?string, // streaming args/output fragment
    'subagent_progress' => ?array, // structured inline subagent snapshot (replaces delta append semantics in projection)
    'result'       => ?string, // final rendered/capped result
    'is_error'     => bool,
    'duration_ms'  => ?int,
    'cancelled'    => bool,
    'timed_out'    => bool,
]
```

---

### Progress / status

| Constant | Event type string | Meaning |
|----------|-------------------|---------|
| `ProgressUpdated` | `progress.updated` | Progress percent or indeterminate |
| `StatusUpdated` | `status.updated` | Status message changed |

Payload:

```php
[
    'scope'         => string,  // 'model'|'tool'|'session'|'compaction'
    'message'       => string,
    'percent'       => ?int,
    'indeterminate' => bool,
]
```

---

### Human-in-the-loop (AgentCore HITL only)

These events cover AgentCore `waiting_human` requests that pause a run.
Local TUI prompts (settings, confirmations) use the same widget schema
but do NOT become transcript blocks or persist as `RuntimeEvent`s.

| Constant | Event type string | Meaning |
|----------|-------------------|---------|
| `HumanInputRequested` | `human_input.requested` | AgentCore is waiting for human answer |
| `HumanInputAnswered` | `human_input.answered` | Accepted human_response applied |
| `HumanInputRejected` | `human_input.rejected` | Human response rejected |
| `ApprovalRequested` | `approval.requested` | `human_input.requested` when schema=approval |
| `ApprovalApproved` | `approval.approved` | Approval granted |
| `ApprovalRejected` | `approval.rejected` | Approval denied |

Shared in-memory request shape (only HITL instances are persisted):

```php
[
    'request_id'    => string,
    'source'        => 'agent_core', // always agent_core for HITL
    'question_id'   => string,
    'kind'          => string,  // 'question'|'approval'|'choice'|'confirm'
    'prompt'        => string,
    'schema'        => array,   // e.g. ['type' => 'string']
    'choices'       => ?array,
    'default'       => mixed,
    'tool_call_id'  => ?string,
    'tool_name'     => ?string,
    'transcript'    => true,
]
```

---

### Cancellation / interruption

| Constant | Event type string | Meaning |
|----------|-------------------|---------|
| `CancellationRequested` | `cancellation.requested` | Client requested cancellation |
| `OperationCancelled` | `operation.cancelled` | A specific operation was cancelled |
| `TurnCancelled` | `turn.cancelled` | Turn aborted by cancellation (also lifecycle) |
| `RunCancelled` | `run.cancelled` | Run aborted by cancellation (also lifecycle) |

Payload:

```php
[
    'reason'                   => string,  // 'user_cancelled'|'timeout'|'provider_aborted'|'tool_cancelled'
    'operation_id'             => ?string,
    'operation_type'           => ?string, // 'model'|'tool'|'turn'|'run'
    'partial_output_available' => bool,
]
```

---

### Model / usage / cost metadata

| Constant | Event type string | Meaning |
|----------|-------------------|---------|
| `ModelChanged` | `model.changed` | Active model changed |
| `ReasoningChanged` | `reasoning.changed` | Reasoning effort level changed |
| `UsageUpdated` | `usage.updated` | Token usage state updated |
| `ContextUpdated` | `context.updated` | Context window fill updated |
| `CostUpdated` | `cost.updated` | Cost estimate updated |

Payload:

```php
[
    'provider'          => ?string,  // e.g. 'zai'
    'model'             => ?string,  // e.g. 'glm-5.1'
    'display'           => ?string,  // e.g. 'zai/glm-5.1'
    'reasoning'         => ?string,  // 'low'|'medium'|'high'
    'input_tokens'      => ?int,
    'output_tokens'     => ?int,
    'total_tokens'      => ?int,
    'cost_usd'          => ?float,
    'context_used'      => ?int,
    'context_window'    => ?int,
    'tokens_per_second' => ?float,
]
```
