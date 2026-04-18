# Stage 03 - Persistence (Hot/Cold Storage)

## Goal
Implement storage that is simple to reason about, cheap to operate, and resilient for long-running sessions.

## Storage Strategy
- DB is source of truth for committed state and canonical events.
- JSONL run log and Mercure events are projections driven asynchronously from DB outbox.
- Hot prompt state is a temporary cache for next LLM turn.
- Large payloads are artifact refs, not inline DB blobs.

## Core Tables

ID strategy:
- Internal DB primary keys are auto-increment bigint (`id`).
- Public/external identifiers are UUID (`public_id`) with unique index.
- Queue messages and APIs use `public_id`; internal joins use bigint IDs.

### `agent_runs`
Stores run control state.
- `id` (pk, bigint autoincrement)
- `public_id` (uuid, unique)
- `user_id`
- `status`
- `version`
- `turn_no`
- `last_seq`
- `cancel_requested`
- `waiting_human`
- `created_at`, `updated_at`, `finished_at`

### `agent_commands`
Mailbox for steering and control commands.
- `id` (pk)
- `run_id` (fk -> `agent_runs.id`)
- `kind` (core: `steer|follow_up|cancel|human_response|continue`; extension: `ext:*`)
- `payload_json`
- `options_json` (nullable, reserved core command metadata, e.g. `{"cancel_safe": true}`)
- `idempotency_key`
- `status` (`pending|applied|rejected|superseded`)
- `created_at`, `applied_at`
- index (`run_id`, `status`, `created_at`)
- unique index (`run_id`, `idempotency_key`)

### `agent_hot_prompt_state`
One row per active run, overwritten each committed turn.
- `id` (pk, bigint autoincrement)
- `run_id` (fk unique -> `agent_runs.id`)
- `last_seq`
- `token_estimate`
- `context_compressed`
- `updated_at`

### `agent_turn_index`
Small turn summaries for query/debug.
- `id` (pk, bigint autoincrement)
- `run_id` (fk -> `agent_runs.id`)
- `turn_no`
- `assistant_stop_reason`
- `tool_calls_count`
- `usage_json`
- `started_at`, `ended_at`

### `agent_run_events`
Canonical persisted lifecycle events (authoritative event stream).
- `id` (pk, bigint autoincrement)
- `run_id` (fk -> `agent_runs.id`)
- `seq` (monotonic per run)
- `turn_no`
- `type` (core lifecycle names + extension namespaced `ext_*`)
- `payload_json`
- `created_at`
- unique index (`run_id`, `seq`)

### `agent_outbox`
Projection jobs emitted in the same transaction as run state/event commits.
- `id` (pk, bigint autoincrement)
- `run_id` (fk -> `agent_runs.id`)
- `seq`
- `sink` (`jsonl|mercure`)
- `payload_json`
- `status` (`pending|processing|processed|failed`)
- `attempts`
- `available_at`, `processed_at`, `created_at`
- unique index (`sink`, `run_id`, `seq`) for idempotent delivery

### `agent_tool_jobs`
Execution tracking for tool calls and batch collection.
- `id` (pk, bigint autoincrement)
- `run_id` (fk -> `agent_runs.id`)
- `turn_no`
- `step_id`
- `tool_call_id`
- `tool_name`
- `order_index`
- `mode` (`sequential|parallel|interrupt`)
- `tool_idempotency_key` (nullable)
- `status` (`queued|running|completed|failed|timed_out|cancelled`)
- `attempt`
- `result_ref` (nullable)
- `external_request_id` (nullable)
- `error_json` (nullable)
- `started_at`, `finished_at`, `created_at`, `updated_at`
- unique index (`run_id`, `tool_call_id`)
- optional unique index (`tool_name`, `tool_idempotency_key`) where key is not null

## JSONL Run Log
Per-run append-only file, example path:

```text
var/agent-runs/{yyyy}/{mm}/{run_id}.jsonl
```

JSONL entries are written by an outbox projector after DB commit, preserving the same `seq` as `agent_run_events`.

Event schema:

```json
{
  "seq": 42,
  "ts": "2026-04-12T12:00:00Z",
  "run_id": "...",
  "turn_no": 7,
  "type": "turn_committed",
  "payload": { "assistant": "...", "tool_results": [] }
}
```

## What Is Stored Where
- DB (`agent_runs`, `agent_commands`, `agent_turn_index`): scheduling, status, compact metadata.
- DB (`agent_run_events`): canonical ordered event stream per run.
- DB (`agent_outbox`): reliable projection queue for JSONL and Mercure publication.
- DB (`agent_tool_jobs`): per-call execution tracking and deterministic batch collection.
- DB (`agent_hot_prompt_state`): prompt-ready context for active runs only.
- JSONL: append-only projection for operations and auditing.
- Artifacts: large tool outputs, raw provider payloads, binary content.

## Hot State Lifecycle
1. Create on run start.
2. Overwrite each turn commit.
3. Delete on run finished/cancelled/failed.
4. Rebuild from canonical events (fallback JSONL) when missing or stale.

## Rebuild Algorithm
1. Read canonical events from `agent_run_events` seq `1..last_seq`.
2. If event rows are unavailable/corrupted, fallback to JSONL replay for recovery.
3. Apply deterministic reducer replay to reconstruct transcript.
4. Build prompt-visible context.
5. Write `agent_hot_prompt_state`.

## Data Growth Controls
- Never store chunk-level streaming deltas in DB.
- Keep only turn-level summaries in DB.
- Compress JSONL segments daily.
- Archive run logs older than retention window.
- Optionally strip heavy payloads into artifact refs during compaction.

## Failure Safety
- On each turn commit:
  1. begin DB transaction,
  2. persist state update + `agent_run_events` row(s) + `agent_outbox` row(s),
  3. commit DB transaction,
  4. project outbox to JSONL and Mercure asynchronously.
- If DB transaction fails, nothing is committed.
- If projection fails, retry outbox delivery idempotently using unique (`sink`, `run_id`, `seq`).
- Projection lag must never block run correctness.

## Deliverables
- Doctrine migrations for core tables.
- `RunLogWriter` + `RunLogReader`.
- `HotPromptStateStore` implementation.
- `ReplayService` for rebuild and integrity checks.
- Outbox projector workers for JSONL and Mercure sinks.

## Acceptance Criteria
- Resume works when hot prompt row is deleted.
- Single run can be replayed to identical transcript.
- JSONL/Mercure outages do not lose committed run state.
- 7-day soak test shows stable DB size growth profile.
