# Stage 09 - Testing, Observability, and Debugging

## Goal
Build confidence in loop correctness, recovery behavior, and production operability.

## Test Pyramid

### Unit Tests
- Reducer transition table tests.
- Hook invocation order tests.
- Tool mode tests (sequential, parallel, interrupt).
- Command application tests (`steer`, `follow_up`, `cancel`, `continue`).
- Extension seam tests (custom `ext:*` command routing, extension boundary hooks, `ext_*` event emission).

### Integration Tests
- Orchestrator + stores + JSONL log.
- LLM worker round-trip (fake Symfony AI provider).
- Parallel tool batch collection and deterministic ordering.
- Crash/restart and replay rebuild.
- Load extension package that adds a custom command/hook/event without core code changes.

### Contract Parity Tests
Mirror JS behavior:
- prompt event order
- continue validation
- steering boundary injection
- follow-up only on loop-stop boundary
- `beforeToolCall` block semantics
- `afterToolCall` override semantics
- core loop remains stable when extension hooks are enabled/disabled.

### Soak and Load Tests
- 1000 concurrent synthetic runs with short prompts.
- failure injection in LLM and tool workers.
- lock expiration and takeover tests.

## Observability

### Metrics
- active runs by status
- turn duration histogram
- LLM latency and error rate
- tool latency and timeout rate
- command queue lag
- stale result count
- replay rebuild count

### Structured Logs
Log with required fields:
- `run_id`, `turn_no`, `step_id`, `seq`, `status`, `worker_id`, `attempt`

### Tracing
- Root span per turn.
- Child spans for LLM call, each tool call, command application, persistence commit.

## Debug Tooling
- `agent-loop:run-inspect {runId}`
- `agent-loop:run-replay {runId}`
- `agent-loop:run-rebuild-hot-state {runId}`
- `agent-loop:run-tail {runId}`

## Failure Drills
- kill worker during LLM step
- kill worker during tool batch
- DB transient failure during commit
- JSONL append failure
- duplicate message deliveries

## Deliverables
- test suite scaffolding and fixtures
- fake provider + fake tools library
- observability dashboards and alerts
- debug CLI commands

## Acceptance Criteria
- All parity contract tests pass.
- At-least-once delivery does not break correctness.
- On-call runbook can recover any stuck run using shipped commands.
