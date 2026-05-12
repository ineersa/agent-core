# Stage 04 - Orchestrator and Worker Topology

## Goal
Implement a scalable worker topology with strict single-writer semantics and clear ownership boundaries.

## Topology
- Orchestrator workers: mutate run state.
- LLM workers: call provider and return result messages.
- Tool workers: execute tool calls and return results.
- Publisher workers (optional): publish Mercure/SSE updates.

## Ownership Rules
- Only orchestrator updates DB run state, persists canonical events/outbox rows, and updates hot prompt.
- LLM/tool workers are pure executors and cannot commit run state.
- Orchestrator validates staleness (`turn_no`, `step_id`, `version`) on all results.
- Every state commit uses DB compare-and-swap (`version` check) to reject stale writers.

## Message Contracts

### `ApplyCommand`
- `run_id`, `command_id`, `kind`, `payload`, `options` (optional), `idempotency_key`, `attempt`

### `ExecuteLlmStep`
- `run_id`, `turn_no`, `step_id`, `context_ref`, `tools_ref`, `attempt`

### `LlmStepResult`
- `run_id`, `turn_no`, `step_id`, `assistant_message`, `usage`, `stop_reason`, `error`

### `ExecuteToolCall`
- `run_id`, `turn_no`, `step_id`, `tool_call_id`, `tool_name`, `args`, `order_index`, `tool_idempotency_key` (nullable)

### `ToolCallResult`
- `run_id`, `turn_no`, `step_id`, `tool_call_id`, `order_index`, `result`, `is_error`, `error`

## Lock Strategy
- Use Symfony Lock component with lock key `run_id`.
- Orchestrator acquires lock before mutation and renews on long sections.
- Lock timeout allows takeover after worker crash.
- Lock is advisory for work coordination; correctness is enforced by DB CAS writes:
  - updates include `WHERE run_id = :id AND version = :expected`,
  - if affected rows = 0, writer is stale and must reload/requeue.

## Turn Execution Sequence
1. `AdvanceRun` command arrives.
2. Orchestrator acquires lock and loads run + hot context + pending commands.
3. Orchestrator routes pending commands (core + `ext:*`) and commits accepted/rejected command outcomes.
4. Reducer emits `ExecuteLlmEffect`.
5. LLM worker executes and emits `LlmStepResult`.
6. Orchestrator validates and either:
   - commits final turn (no tools), or
   - dispatches tool batch.
7. Tool workers return results.
8. Orchestrator collects all expected tool results and commits ordered tool messages (`tool` role / Symfony `ToolCallMessage`) using CAS write.
9. Orchestrator polls steering/follow-up commands and schedules next `AdvanceRun` if needed.

## Command Routing Rules
- Core command kinds are handled by built-in handlers.
- Extension command kinds must use `ext:` prefix and are routed to registered extension command handlers.
- `options.cancel_safe` is parsed only for `ext:*` commands and defaults to `false`.
- Command-level `options.cancel_safe=true` is honored only when the matched extension handler is registered as cancel-safe for that kind.
- If no extension handler supports a command kind, orchestrator marks command `rejected` and persists a rejection event.
- Extension handlers may emit extension events (`ext_*`) but cannot write run state outside orchestrator commit path.

## Parallel Tool Collection
- Create expected set from assistant tool calls.
- Store per-call completion in `agent_tool_jobs`.
- Commit once all required jobs are terminal or timeout reached.
- Emit tool results ordered by `order_index`.

## Retry Policy
- LLM step retries controlled by max attempts and backoff.
- Tool call retries can be per-tool configurable.
- Retries keep the same `tool_idempotency_key` when provided by the tool contract.
- Retries never duplicate committed events (idempotency check required).

## Error Policy
- Executor errors become structured `*_Result` with `error` field.
- Orchestrator decides state transition (`failed`, `cancelling`, or continue).
- Never throw away executor responses; always persist decision event.

## Deliverables
- Messenger handlers for all command/result messages.
- Lock manager integration service.
- Command router with extension handler registry.
- Tool batch collector.
- Idempotency service for message handling.

## Acceptance Criteria
- 100 concurrent runs show no double-commit for same run/turn.
- Duplicate result deliveries do not produce duplicate log events.
- Tool batch ordering remains deterministic across retries.
- Simulated lock expiry + takeover cannot overwrite newer state (CAS conflict path is exercised).
- Extension `ext:*` command routing works without changing orchestrator/reducer core logic.
