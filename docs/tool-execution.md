# Tool Execution & Idempotency

The orchestrator handles local tool execution safely, supporting strict sequential constraints, parallel fan-out, and explicit interrupts.

## Tool Modes

1. **`sequential`**: Executed strictly in the order requested by the assistant.
2. **`parallel`**: Fanned out to local workers with bounded parallelism (semaphore limits). Commit order strictly follows the original assistant request order.
3. **`interrupt`**: Execution pauses immediately, emitting a `waiting_human` event. The system will not schedule the next LLM step until a `human_response` command arrives.

## Execution Pipeline

1. **Preflight**: Emit `tool_execution_start`, resolve the tool by name, validate its JSON schema arguments.
2. **Hooks**: Execute `BeforeToolCallHookInterface` (can intercept or cache the response).
3. **Run**: Execute the actual tool (or fan-out). May emit streaming `tool_execution_update` events.
4. **Hooks**: Execute `AfterToolCallHookInterface` (can mask sensitive data or summarize large strings).
5. **Commit**: Emit `tool_execution_end` and append a synthetic `ToolCallMessage` for the next LLM step.

## Error & Cancellation Semantics

- **Exceptions**: Any PHP exceptions thrown by a tool are caught and converted into an `is_error=true` tool result, allowing the LLM to see the error and optionally correct its arguments.
- **Cancellation**: If a run is cancelled mid-tool, cooperative tools receive the cancellation token and stop. Non-cooperative tools are marked stale and discarded.
- **Stale Results**: If an async tool result arrives for an outdated `step_id`, it is ignored and a `stale_result_ignored` trace is logged.

## Idempotency for Side Effects

For tools performing external side-effects (e.g., calling an upstream API):
- They can return a `tool_idempotency_key` via a contract.
- If a run crashes and replays, the executor will check `(tool_name, tool_idempotency_key)`. 
- If a terminal result already exists, it skips execution and safely reuses the previous result, avoiding duplicate downstream writes.
