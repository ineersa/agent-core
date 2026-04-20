# API Surface and Streaming

The `agent-core` exposes an HTTP API and a real-time Mercure SSE (Server-Sent Events) topic for seamless web/UI integration.

## REST Endpoints

- **`POST /agent/runs`**: Starts a new run. Accepts the initial prompt, model, session metadata, and tool scope. Returns a `run_id`.
- **`POST /agent/runs/{runId}/commands`**: The control-plane entry point. Dispatches commands like `steer`, `follow_up`, `cancel`, `continue`, or `ext:*`. Requires an `idempotency_key` to prevent double-submissions.
- **`GET /agent/runs/{runId}`**: Returns the current summary, status, turn count, and waiting flags.
- **`GET /agent/runs/{runId}/messages`**: Returns a paginated transcript of the run's messages for UI hydration.

## Real-Time Streaming (Mercure)

The system projects lifecycle events in real-time to specific topics.

- **Topic Pattern**: `agent/runs/{runId}`
- **Event ID**: Corresponds to the sequence number (`seq`) from the canonical event log.
- **Payload Shape**:
  ```json
  {
    "run_id": "...",
    "seq": 123,
    "turn_no": 9,
    "type": "message_update",
    "payload": { "delta": "..." },
    "ts": "2026-04-12T12:00:00Z"
  }
  ```

### Backpressure & Coalescing
To prevent overwhelming the browser, fast-firing `message_update` events (like LLM streaming deltas) can be coalesced within a 50-100ms window.
Critical structural events like `message_end` and `turn_end` are always published instantly and fully intact.

### Reconnect Behavior
If a UI disconnects and reconnects with a `Last-Event-ID`, the server pulls from the canonical event store (or JSONL fallback) and replays all events where `seq > last_event_id`. If there's a permanent gap or missing index, the server emits a `resync_required` event prompting the UI to hit the REST API and perform a full reload.
