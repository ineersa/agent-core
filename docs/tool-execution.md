# Tool Execution & Idempotency

The orchestrator handles local tool execution with policy control, idempotent result reuse, cancellation boundaries, and Symfony AI Toolbox integration.

## Tool Modes

1. **`sequential`**: Executed strictly in the order requested by the assistant.
2. **`parallel`**: Fanned out to local workers with bounded parallelism (semaphore limits). Commit order strictly follows the original assistant request order.
3. **`interrupt`**: Execution pauses immediately with an interrupt payload. The system will not schedule the next LLM step until a `human_response` command arrives.

## Execution Pipeline

1. **Policy & cache preflight**: Resolve mode/timeout/parallelism and reuse cached idempotent results when available.
2. **Cancellation gate**: Abort early when run cancellation is already requested.
3. **Toolbox execution**: Execute through Symfony AI `ToolboxInterface` (wrapped in `FaultTolerantToolbox`).
4. **Tool lifecycle interception**: Use Symfony events (`ToolCallRequested`, `ToolCallSucceeded`, `ToolCallFailed`) for deny/short-circuit/observability.
5. **Commit**: Persist normalized `ToolResult` metadata (mode, timeout, duration, idempotency) and append tool output for the next LLM step.

## Error & Cancellation Semantics

- **Toolbox exceptions**: Converted by `FaultTolerantToolbox` into tool-call results consumable by the LLM.
- **Executor-level failures**: Unexpected runtime failures still become `is_error=true` tool results.
- **Cancellation**: If a run is cancelled before execution, the tool call is rejected immediately. If cancelled after execution, the result is marked stale.
- **Stale results**: If an async tool result arrives for an outdated `step_id`, it is ignored and traced as stale.

## Idempotency for Side Effects

For tools performing external side effects (for example, upstream API writes):

- They can provide a `tool_idempotency_key`.
- The executor checks `(run_id, tool_call_id)` and `(tool_name, tool_idempotency_key)` before executing.
- When a terminal result already exists, execution is skipped and the prior result is reused safely.
