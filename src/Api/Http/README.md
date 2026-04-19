# API HTTP architecture notes

`Api\Http` contains transport adapters for the browser/web integration surface.

## Endpoints

- `POST /agent/runs`
  - starts a run (`AgentRunnerInterface::start`)
  - captures run access scope (`tenant_id`, `user_id`) for per-run authorization
- `POST /agent/runs/{runId}/commands`
  - validates command envelope (`kind`, `payload`, `idempotency_key`, `options`)
  - dispatches `ApplyCommand` to `agent.command.bus`
- `GET /agent/runs/{runId}`
  - read-model summary for UI restore (status, turns, timestamps, latest summary, waiting flags)
- `GET /agent/runs/{runId}/messages`
  - paged transcript view (`cursor` + optional `limit`)
- `GET /agent/runs/{runId}/events`
  - reconnect replay from `seq > Last-Event-ID`
  - emits `resync_required` envelope when sequence gaps are detected

## Authorization model

All run-scoped endpoints enforce `tenant_id` and `user_id` match using the run access store.

Headers used by API endpoints:

- `X-Agent-Tenant-Id`
- `X-Agent-User-Id`

## Maintenance rule

When endpoint routes, authorization requirements, command validation, or replay behavior change,
update this file in the same change.
