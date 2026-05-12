# Stage 08 - API Surface and Mercure Streaming

## Goal
Provide a simple HTTP API and real-time event stream suitable for website integration.

## API Endpoints

### Start run
`POST /agent/runs`
- request: prompt, model, session metadata, optional tools scope
- response: `run_id`, initial status

### Send command
`POST /agent/runs/{runId}/commands`
- request: `{ kind, payload, idempotency_key, options? }`
- kinds: `steer`, `follow_up`, `cancel`, `human_response`, `continue`, `ext:*`
- reserved option: `options.cancel_safe` (boolean, only valid for `ext:*` kinds)
- unknown `options` keys are rejected with validation error

### Get run summary
`GET /agent/runs/{runId}`
- response: status, turn count, timestamps, latest summary, waiting flags

### Get transcript page
`GET /agent/runs/{runId}/messages?cursor=...`
- response: paged transcript summary for UI reload

## Mercure Topics
- Topic pattern: `agent/runs/{runId}`
- Event id: use `seq` from run log
- Event type: lifecycle event type (`message_update`, `turn_end`, etc.)

## Stream Payload Shape

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

Extension event types (`ext_*`) are published on the same run topic and follow the same envelope shape.

## Reconnect Behavior
- UI sends `Last-Event-ID`.
- Server replays from `seq > last_event_id` using canonical event index (fallback JSONL when needed).
- On gaps, server sends `resync_required` event with reload endpoint.

## Authorization
- Enforce per-run access checks on all endpoints and stream topics.
- Include tenant and user scoping in run rows.

## Backpressure Policy
- `message_update` events can be coalesced per 50-100ms window for UI.
- Always publish full `message_end` and `turn_end` events.

## Deliverables
- Controller endpoints.
- Mercure publisher service and topic policy.
- Event DTOs and serializers.

## Acceptance Criteria
- Browser client can start run, stream updates, steer, cancel, and resume.
- Reconnect resumes without duplicate rendering.
- Unauthorized user cannot subscribe to another user's run topic.
