# Stage 06 - Tool Execution, HITL, and Parallelism

## Goal
Implement robust tool execution modes with deterministic ordering, human-in-the-loop interrupts, and bounded parallelism.

## Tool Modes
Define explicit mode per tool call.
- `sequential`: run in assistant tool-call order.
- `parallel`: fan-out to tool workers with semaphore limit.
- `interrupt`: pause run and wait for user response.

## Tool Call Pipeline
1. Emit `tool_execution_start`.
2. Resolve tool by name.
3. Prepare args and execution context (including optional idempotency key).
4. Validate args against schema.
5. Run `beforeToolCall` hook.
6. Execute or block.
7. Stream optional updates (`tool_execution_update`).
8. Run `afterToolCall` hook.
9. Emit `tool_execution_end`.
10. Emit synthetic `tool` message start/end and persist turn input for next LLM step.

## Symfony AI Tool Message Compatibility
- Provider turn continuation uses Symfony `ToolCallMessage` (`tool` role, string `content`).
- Internal rich tool outcomes (`is_error`, structured content, details) are encoded into the `ToolCallMessage` content string (JSON envelope or artifact ref).
- `tool_call_id` is preserved via the embedded `ToolCall` object.

## Tool Idempotency (v1)
- Baseline dedupe always applies by (`run_id`, `tool_call_id`).
- Side-effecting tools may opt into stronger idempotency by implementing an idempotency contract that returns `tool_idempotency_key`.
- For tools that provide a key:
  - retries must reuse the same key,
  - executor checks prior terminal result by (`tool_name`, `tool_idempotency_key`) before executing,
  - existing result is reused when present.
- For tools that do not provide a key, behavior remains baseline per-call dedupe.
- HTTP/API tool adapters should forward key as `Idempotency-Key` header when upstream supports it.

## Interrupt Tool Contract
`ask_user`-style tools return interrupt outcome:

```json
{
  "kind": "interrupt",
  "question_id": "...",
  "prompt": "Approve deployment?",
  "schema": { "type": "boolean" }
}
```

Reducer behavior:
- set run `waiting_human=true`, `status=waiting_human`.
- emit `waiting_human` event.
- do not schedule next LLM step until `human_response` command arrives.

## Parallel Tool Policy
- Preflight is sequential.
- Execution fan-out is concurrent up to configured parallelism.
- Final commit order follows assistant tool call order (`order_index`).
- Timeout policy can fail individual tools without collapsing entire batch.

## Cancellation in Tools
- All tools receive cancel token.
- Cooperative tools stop quickly.
- Non-cooperative tools are marked stale on completion if run cancelled.
- Optional hard timeout can terminate subprocess-based tools.

## Error Semantics
- Tool exceptions become `is_error=true` tool results.
- Blocked tools also emit error tool results.
- `afterToolCall` can override `is_error`, content, details.

## Deliverables
- Tool execution engine.
- Tool mode selector.
- Interrupt handling path.
- Parallel batch collector and ordered commit.
- Optional idempotency contract + lookup path for side-effecting tools.

## Acceptance Criteria
- Sequential and parallel modes both pass parity tests.
- Interrupt tool pauses and resumes correctly.
- Cancelled run does not apply late tool results.
- Idempotent side-effecting tool retries do not repeat external side effects.
