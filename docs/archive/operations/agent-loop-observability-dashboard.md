# Agent Loop Observability Dashboard

This dashboard spec maps directly to metrics exposed by `RunMetrics` and debug tooling (`agent-loop:run-inspect`).

## Core panels

1. **Active runs by status**
   - Source: `active_runs_by_status`
   - Use stacked chart for `queued`, `running`, `waiting_human`, `cancelling`, `completed`, `failed`, `cancelled`.

2. **Turn duration histogram**
   - Source: `turn_duration_ms`
   - Track `avg_ms`, `max_ms`, and bucket distribution.

3. **LLM latency and error rate**
   - Source: `llm.calls`, `llm.errors`, `llm.error_rate`, `llm.latency_ms`

4. **Tool latency, timeout rate, and error rate**
   - Source: `tools.calls`, `tools.errors`, `tools.error_rate`, `tools.timeouts`, `tools.timeout_rate`, `tools.latency_ms`

5. **Command queue lag**
   - Source: `command_queue_lag.sum`, `command_queue_lag.max`, `command_queue_lag.by_run`

6. **Stale result counter**
   - Source: `stale_result_count`

7. **Replay rebuild activity**
   - Source: `replay_rebuild_count`, `replay_rebuild_by_source`
   - Split by source (`canonical_events`, `jsonl_fallback`).

## Drill-down filters

- `run_id`
- `status`
- `turn_no`
- `worker_id`

## Structured log pivots

When investigating a run, pivot by:

- `run_id`
- `turn_no`
- `step_id`
- `seq`
- `status`
- `worker_id`
- `attempt`

These fields are emitted through `agent_loop.event` log entries from orchestrator commit boundaries.
