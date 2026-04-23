# API DTO architecture notes

`Api\Dto` defines transport-facing envelope objects for HTTP and streaming payloads.

## Request DTOs

- `StartRunRequest`
  - HTTP request payload mapped by `RunApiController::startRun` via `#[MapRequestPayload]`
  - string fields use property hooks for normalization
  - validation constraints are declared on DTO properties
  - fields:
    - `prompt`
    - `system_prompt`
    - `metadata` (`StartRunMetadataRequest`)
- `StartRunMetadataRequest`
  - nested metadata DTO for run scope and optional settings
  - fields:
    - `tenant_id`
    - `user_id`
    - `session`
    - `model`
    - `tools_scope`
- `RunCommandRequest`
  - HTTP request payload mapped by `RunApiController::sendCommand` via `#[MapRequestPayload]`
  - string fields use property hooks for normalization
  - command-envelope validation lives in DTO constraints/callback
  - fields:
    - `kind`
    - `idempotency_key`
    - `payload`
    - `options`
- `TranscriptPageQueryRequest`
  - HTTP query DTO mapped by `RunApiController::transcriptPage` via `#[MapQueryString]`
  - validates paging inputs for transcript read endpoint
  - fields:
    - `cursor`
    - `limit`
- `ReplayEventsQueryRequest`
  - HTTP query DTO mapped by `RunApiController::replayEvents` via `#[MapQueryString]`
  - validates reconnect cursor input for event replay endpoint
  - fields:
    - `last_event_id`

## Stream DTOs

- `RunStreamEvent`
  - canonical stream envelope for UI updates
  - shape:
    - `run_id`
    - `seq`
    - `turn_no`
    - `type`
    - `payload`
    - `ts`

These DTOs are serialized via `Api\Serializer\RunEventSerializer` and used by:

- Mercure publishing (`RunEventPublisher`)
- reconnect replay endpoint (`GET /agent/runs/{runId}/events`)

## Maintenance rule

When API payload shape changes, update this file and ensure serializer tests cover the new fields.
