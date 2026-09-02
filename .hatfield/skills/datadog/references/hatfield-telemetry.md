# Hatfield telemetry

Use these known identifiers and queries instead of rediscovering the Hatfield service. Re-run discovery only when a query stops returning data or the user asks for broader coverage.

## Known identifiers

| Item | Value |
|---|---|
| Service | `hatfield` |
| Known environment | `dev` |
| Log source | `php` |
| LLM call resource | `llm.call` |
| Tool call resource | `tool.call` |
| Messenger operation | `symfony.messenger.consume` |
| Database operation | `PDOStatement.execute` |
| Host | `server` |

## Confirmed queries

The Hatfield dashboard defines an `env` template variable with the `env` prefix. Use `$env` in dashboard widget queries. Datadog expands it to `env:<value>`. Outside a dashboard, replace `$env` with an explicit filter such as `env:dev`.

| Signal | Query |
|---|---|
| All Hatfield logs | `service:hatfield $env` |
| Warning and higher logs | `service:hatfield $env source:php status:(warn OR error OR critical OR alert OR emergency)` |
| LLM call spans | `service:hatfield $env resource_name:llm.call` |
| Tool call spans | `service:hatfield $env resource_name:tool.call` |
| Messenger consume spans | `service:hatfield $env operation_name:symfony.messenger.consume` |
| Database statement spans | `service:hatfield $env operation_name:PDOStatement.execute` |
| LLM-step errors | `sum:trace.symfony.messenger.consume.errors{service:hatfield AND $env AND resource_name:llm_-_ineersa_agentcore_domain_message_executellmstep}.as_count()` |
| LLM-step count | `sum:trace.symfony.messenger.consume.hits{service:hatfield AND $env AND resource_name:llm_-_ineersa_agentcore_domain_message_executellmstep}.as_count()` |
| Shared-host CPU user | `avg:system.cpu.user{host:server AND $env} by {host}` |
| Shared-host CPU system | `avg:system.cpu.system{host:server AND $env} by {host}` |
| Shared-host CPU I/O wait | `avg:system.cpu.iowait{host:server AND $env} by {host}` |
| Shared-host usable memory | `avg:system.mem.pct_usable{host:server AND $env} by {host}` |

Use `pc50` and `pc95` as span percentile aggregations in dashboard widget definitions. Datadog investigation tools may label equivalent results p50 and p95.

`llm.call` and `turn.execution.llm_worker` are nested duplicates. `tool.call` and `turn.execution.tool_worker` are also nested duplicates. Use only the canonical call resources listed above when counting or measuring logical calls.

CPU metrics are native percentages. `system.mem.pct_usable` is a fraction and must be multiplied by 100 for a percentage display. The host is shared. Never present these metrics as Hatfield-specific CPU or memory usage.

Recent evidence confirmed log statuses `info`, `ok`, `notice`, `warn`, `error`, and `emergency`. Recent APM data included LLM calls, tool calls, Messenger work, database statements, and controller work.

## Correlation fields

Logs and spans have exposed these fields:

- `trace_id` or `traceid`
- `span_id` or `spanid`
- `service`
- `env`
- `operation_name`
- `resource_name`
- `status`

Do not assume that `run_id`, `session_id`, `component`, `event_type`, tool name, provider, or model are indexed facets. Check a current result before filtering or grouping on them.

To trace a failure, search warning and higher logs over `now-15m` to `now`. Extract a returned trace ID, then call `datadog_get_datadog_trace`. Expand to one hour only when the narrow range lacks evidence.

## Unsupported signals

### Cache hit rate

A seven-day search found no Hatfield provider, prompt, response, or llama cache hit and miss telemetry. Cache-related metrics that did appear measured code spans or Datadog Trace Agent internals, not a Hatfield cache numerator and denominator.

To support a cache hit-rate widget, emit a counter such as `hatfield.cache.requests` with these tags:

- `cache:provider`, `cache:prompt`, or `cache:response`
- `result:hit` or `result:miss`
- `env`
- optional `provider` or `model`

Calculate the percentage from hits divided by hits plus misses. Name the cache in the widget title. Do not combine unrelated caches into one rate.

### Session storage

No metric measures `.hatfield` or session-directory size. Whole-filesystem metrics are not a substitute.

To support a storage widget, publish a directory-specific gauge such as `hatfield.session_storage.bytes{env:<env>}`. The collector must measure the configured session directory and document whether it includes sessions, logs, cache, or all `.hatfield` data.

### Other gaps

- Tool names are not available as a usable span facet, so the dashboard cannot rank slow tools honestly.
- Some tool failure event spans report `status:ok`, so a tool failure-rate widget is unreliable.
- Provider and model fields are not available as confirmed span facets.
- No queue-depth or oldest-message metric exists.
- No run-level span measures end-to-end run duration and outcome.
- Disk and network metrics have only shared-host scope.

## Discovery tools

Use these tools when current data needs confirmation:

1. `datadog_search_datadog_entities` finds the Hatfield service.
2. `datadog_search_datadog_logs` checks log fields and representative events.
3. `datadog_analyze_datadog_logs` counts or groups logs without sampling messages.
4. `datadog_search_datadog_spans` checks span fields and resources.
5. `datadog_aggregate_spans` counts resources and measures latency.
6. `datadog_search_datadog_metrics` finds Hatfield and host metrics.
7. `datadog_get_datadog_metric_context` checks metric dimensions and units.
8. `datadog_get_datadog_metric` confirms current metric data.

Read-back of a dashboard proves its definitions, not rendered values. Prove current data with the corresponding log, span, or metric tool.
