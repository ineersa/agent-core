# API HTTP architecture notes

`Api\Http` contains transport adapters for the browser/web integration surface.

## Endpoints

- `POST /agent/runs`
  - maps JSON payload to `StartRunRequest` via `#[MapRequestPayload]`
  - expects nested `metadata` DTO with `tenant_id` / `user_id`
  - starts a run (`AgentRunnerInterface::start`)
  - captures run access scope (`tenant_id`, `user_id`) for run metadata bookkeeping
- `POST /agent/runs/{runId}/commands`
  - maps JSON payload to `RunCommandRequest` via `#[MapRequestPayload]`
  - command-envelope validation is defined on DTO constraints/callbacks
  - dispatches `ApplyCommand` to `agent.command.bus`
- `GET /agent/runs/{runId}`
  - read-model summary for UI restore (status, turns, timestamps, latest summary, waiting flags)
- `GET /agent/runs/{runId}/messages`
  - paged transcript view (`cursor` + optional `limit`)
  - maps query string to `TranscriptPageQueryRequest` via `#[MapQueryString]`
  - validates paging arguments before invoking read service
- `GET /agent/runs/{runId}/events`
  - reconnect replay from `seq > last_event_id`
  - maps query string to `ReplayEventsQueryRequest` via `#[MapQueryString]`
  - validates replay cursor before invoking read service
  - emits `resync_required` envelope when sequence gaps are detected

## Access model

Run-scoped endpoints are currently keyed by `run_id` only.

`tenant_id` and `user_id` from start payload metadata are persisted as run access scope metadata,
but no header-based actor matching is enforced by this controller.

## Maintenance rule

When endpoint routes, access requirements, command validation, or replay behavior change,
update this file in the same change.
