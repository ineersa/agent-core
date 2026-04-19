# Agent Loop On-Call Runbook

Use this runbook when a run is stuck (`running`, `waiting_human`, or `cancelling`) or when alerts from `agent-loop-alert-rules.yaml` fire.

## 1) Inspect current run state

```bash
php bin/console agent-loop:run-inspect <runId>
php bin/console agent-loop:run-inspect <runId> --json
```

Check:

- `state.status`
- `state.active_step_id`
- `integrity.missing_sequences`
- `pending_commands`
- `metrics.stale_result_count`

## 2) Inspect recent event flow

```bash
php bin/console agent-loop:run-tail <runId> --limit=50
php bin/console agent-loop:run-replay <runId> --after-seq=<lastSeenSeq> --limit=200
```

Use these to confirm whether the run is progressing or repeatedly retrying the same boundary.

## 3) Rebuild hot prompt state if drift is suspected

```bash
php bin/console agent-loop:run-rebuild-hot-state <runId>
```

Expected outcome:

- `source` is `canonical_events` in normal operation.
- `missing_sequences` is empty.

## 4) Resume stale runs

```bash
php bin/console agent-loop:resume-stale-runs
```

This command:

- finds stale `running` runs by `commands.resume_stale_after_seconds`
- rebuilds missing hot state when needed
- dispatches `AdvanceRun` to continue execution

## 5) Failure drill playbooks

### Worker killed during LLM step

1. Inspect run (`run-inspect`) and verify `active_step_id` points to LLM step.
2. Replay/tail events to confirm no terminal `llm_step_completed` or `llm_step_failed` event.
3. Run `agent-loop:resume-stale-runs` to re-dispatch advancement path.

### Worker killed during tool batch

1. Tail run events and look for partial `tool_call_result_received` events without `tool_batch_committed`.
2. Verify pending tool calls in `run-inspect`.
3. Resume stale runs and confirm batch closes with deterministic ordering.

### Transient DB/event-store failure during commit

1. Inspect logs for `agent_loop.commit.event_persist_failed`.
2. Retry delivery path (automatic via messenger retry).
3. Confirm `run-inspect` shows contiguous sequences after retry.

### JSONL append failure

1. Inspect logs for outbox retry patterns and `agent_loop.commit.projection_failed`.
2. Confirm canonical events are intact via `run-replay`.
3. Re-run projector workers (or allow retry) until JSONL projection catches up.

### Duplicate delivery storm

1. Inspect run tail for duplicate transport attempts.
2. Confirm event stream does not duplicate terminal boundaries (`tool_batch_committed`, `agent_end`).
3. Validate idempotency keys for affected messages.

## 6) Escalation checklist

Escalate if any of the following remain true after recovery steps:

- `integrity.missing_sequences` remains non-empty
- run cannot transition from `running`/`cancelling` after resume
- repeated `event_persist_failed` for same run over 15 minutes
- stale-result counter continues increasing while throughput drops
