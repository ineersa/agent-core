# API DTO architecture notes

`Api\Dto` defines transport-facing envelope objects for HTTP and streaming payloads.

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
