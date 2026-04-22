# Agent Loop On-Call Runbook

Use this runbook when runs appear stuck (`running`, `waiting_human`, `cancelling`) or alert rules fire.

## 0) Quick health check

```bash
php bin/console agent-loop:health
```

Confirm runtime/streaming/storage settings are as expected for the environment.

## 1) Inspect run state

```bash
php bin/console agent-loop:run-inspect <runId>
php bin/console agent-loop:run-inspect <runId> --json
```

Check:

- `state.status`
- `state.active_step_id`
- `integrity.source` (`canonical_events` or `jsonl_fallback`)
- `integrity.missing_sequences`
- `pending_commands`
- `metrics.command_queue_lag.max`
- `metrics.stale_result_count`

## 2) Inspect recent events

```bash
php bin/console agent-loop:run-tail <runId> --limit=50
php bin/console agent-loop:run-replay <runId> --after-seq=<lastSeenSeq> --limit=200
```

Use this to confirm whether the run is advancing or looping/retrying on the same boundary.

## 3) Rebuild hot prompt state (if drift is suspected)

```bash
php bin/console agent-loop:run-rebuild-hot-state <runId>
```

Expected:

- `source` preferably `canonical_events` (or `jsonl_fallback` if canonical store is empty)
- `missing_sequences` is empty

## 4) Resume stale running runs

```bash
php bin/console agent-loop:resume-stale-runs
```

This command:

- finds stale `running` runs (`commands.resume_stale_after_seconds`)
- acquires per-run lock
- rebuilds hot state when missing
- dispatches `AdvanceRun` to continue processing

## 5) Failure drills

### Worker died during LLM step

1. `run-inspect` confirms run is still `running` and step is active.
2. `run-tail` / `run-replay` show no terminal LLM result boundary.
3. Run `agent-loop:resume-stale-runs`.

### Worker died during tool batch

1. Replay/tail events for partial tool-result boundaries.
2. Confirm pending tool calls in `run-inspect`.
3. Resume stale runs and verify batch closure.

### Commit/persistence failures

1. Check logs for `agent_loop.commit.*` warnings.
2. Confirm replay continuity (`missing_sequences` empty).
3. Re-run stale resume path if needed.

### Replay fallback spike

1. Check `integrity.source` and rebuild counters.
2. Validate canonical event-store availability.
3. Keep operations running via JSONL fallback while investigating.

## 6) Escalate when

- `integrity.missing_sequences` remains non-empty after rebuild/retry
- runs cannot leave `running`/`cancelling`
- repeated commit failures persist for the same run
- stale-result count rises while throughput drops
