# Hatfield telemetry

Use these known identifiers and queries instead of rediscovering the Hatfield service. Re-run discovery only when a query stops returning data or the user asks for broader coverage.

## Known identifiers

| Item | Value |
|---|---|
| Service | `hatfield` |
| Known environment | `dev` |
| Log source | `php` |
| Activity metric | `trace.hatfield.phar.hits` |

## Confirmed queries

The Hatfield dashboard defines an `env` template variable with the `env` prefix. Use `$env` in dashboard widget queries. Datadog expands it to `env:<value>`.

| Signal | Query |
|---|---|
| All Hatfield logs | `service:hatfield $env` |
| Warning and higher logs | `service:hatfield $env source:php status:(warn OR error OR critical OR alert OR emergency)` |
| Hatfield spans | `service:hatfield $env` |
| Activity metric | `sum:trace.hatfield.phar.hits{$env} by {env}.as_count()` |

Outside a dashboard, replace `$env` with an explicit environment filter such as `env:dev`.

Recent evidence confirmed log statuses `info`, `ok`, `notice`, `warn`, `error`, and `critical`. Recent APM data included Symfony events, Messenger work, database operations, and tool execution.

## Correlation fields

Logs and spans have exposed these fields:

- `trace_id` or `traceid`
- `span_id` or `spanid`
- `service`
- `env`
- `resource_name`
- `status`

Do not assume that `run_id`, `session_id`, `component`, or `event_type` are indexed facets. Check a current result before filtering or grouping on them.

To trace a failure, search warning and higher logs over `now-15m` to `now`. Extract a returned trace ID, then call `datadog_get_datadog_trace`. Expand to one hour only when the narrow range lacks evidence.

## Discovery tools

Use these tools when current data needs confirmation:

1. `datadog_search_datadog_entities` finds the Hatfield service.
2. `datadog_search_datadog_logs` checks log fields and representative events.
3. `datadog_analyze_datadog_logs` counts or groups logs without sampling messages.
4. `datadog_search_datadog_spans` checks span fields and resources.
5. `datadog_aggregate_spans` counts resources and measures latency.
6. `datadog_search_datadog_metrics` finds Hatfield metrics.
7. `datadog_get_datadog_metric` confirms current metric data.

Read-back of a dashboard proves its definitions, not rendered values. Prove current data with the corresponding log, span, or metric tool.
