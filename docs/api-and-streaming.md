# API Surface and Streaming

`agent-core` exposes HTTP endpoints for run control/read APIs and uses Mercure for real-time event delivery.

## REST endpoints

- **`POST /agent/runs`**
  - Starts a run from prompt + optional `system_prompt`, `model`, `session`, and `tools_scope`.
  - Requires actor scope (`tenant_id`, `user_id`) via headers or `session` payload.
  - Returns `run_id`, initial `status` (`queued`), and `stream_topic`.

- **`POST /agent/runs/{runId}/commands`**
  - Sends control-plane commands (`steer`, `follow_up`, `cancel`, `human_response`, `continue`, `ext:*`).
  - Requires `idempotency_key`.
  - Validates command `options` (currently supports `cancel_safe` only for extension commands).

- **`GET /agent/runs/{runId}`**
  - Returns run summary (`status`, `turn_count`, timestamps, `latest_summary`, `waiting_flags`) + `stream_topic`.

- **`GET /agent/runs/{runId}/messages`**
  - Cursor-based transcript pagination (`cursor`, `limit`, `next_cursor`, `has_more`, `items`).

- **`GET /agent/runs/{runId}/events`**
  - Replay endpoint for reconnect (`Last-Event-ID` header or `last_event_id` query).
  - Returns replay source (`canonical_events` / `jsonl_fallback`), `resync_required`, and normalized event list.

## Access scope model

Run-scoped endpoints enforce actor ownership using:

- `X-Agent-Tenant-Id`
- `X-Agent-User-Id`

Run scope is saved at creation and checked on all subsequent run endpoints.

## Mercure streaming

- **Topic pattern**: `agent/runs/{runId}`
- **Event id**: run `seq`
- **Event type**: lifecycle `type`
- **Payload shape**: normalized `RunStreamEvent`

`message_update` events are coalesced in a short window (default `75ms`) to reduce update volume. Structural boundaries (e.g. `message_end`, `turn_end`) are not coalesced.

If replay detects missing sequences, clients should use `reload_endpoint` from replay responses and refresh via REST.
