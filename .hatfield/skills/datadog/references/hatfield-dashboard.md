# Hatfield dashboard

Status (2026-09-11): the ddtrace PHP extension was retired on 2026-09-05, so no
widget queries APM spans or `trace.*` metrics. The dashboard uses log queries, the
Datadog Process Check, and log streams. The former span-derived widgets were rebuilt
as log queries; the Database operations and Messenger consume widgets were removed
because their data existed only in spans. See
[`docs/datadog.md`](../../../../docs/datadog.md).

Log queries filter `message:"agent_loop.trace.finish"` together with the span name.
Each span writes a start record and a finish record with the same
`@context.span_name`, so counting without the finish filter doubles every span count
and halves every span-based rate.

## Dashboard identity

| Item | Value |
|---|---|
| ID | `xza-j7r-e4s` |
| URL | `https://app.datadoghq.com/dashboard/xza-j7r-e4s` |
| Title | `Hatfield` |
| Layout | Fixed |
| Description | `[[suggested_dashboards]]` |
| Tag | `ai:modified_with_ai` |

## Environment selector

The dashboard uses one template variable:

```json
{
  "name": "env",
  "prefix": "env",
  "available_values": ["dev"],
  "defaults": ["dev"]
}
```

Use `$env` in widget queries. Datadog expands it to `env:<value>`. Do not write `env:$env`, because that duplicates the prefix.

## Widgets

The dashboard contains fifteen widgets. Preserve their IDs when updating them.

### LLM/provider latency (p95)

ID `5737519449404669`. Type `timeseries`. Layout: `x:0`, `y:0`, `width:4`, `height:4`.

- Query: `service:hatfield $env message:"agent_loop.trace.finish" @context.span_name:llm.call`
- Compute: `pc95` of `@context.duration_ms`
- Formula: `llm_p95`

### LLM-step error rate (%)

ID `4737903204572067`. Type `query_value`. Layout: `x:4`, `y:0`, `width:4`, `height:4`.

- Queries: `service:hatfield $env @context.event_type:llm.request.completed` (count, `completed`) and `service:hatfield $env @context.event_type:llm.request.failed` (count, `failed`)
- Formula: `failed / (completed + failed) * 100`

### Tool execution throughput

ID `5065655617896639`. Type `timeseries`. Layout: `x:8`, `y:0`, `width:4`, `height:4`.

- Query: `service:hatfield $env message:"agent_loop.trace.finish" @context.span_name:tool.call`
- Compute: count
- Group by: `@context.tool_name` (limit 20, count descending)
- Formula: `calls`

### LLM-step errors

ID `7338158574332695`. Type `query_value`. Layout: `x:0`, `y:4`, `width:2`, `height:2`.

- Query: `service:hatfield $env @context.event_type:llm.request.failed`
- Compute: count
- Formula: `failed`

### LLM/provider throughput

ID `8711659535887169`. Type `timeseries`. Layout: `x:2`, `y:4`, `width:6`, `height:4`.

- Query: `service:hatfield $env message:"agent_loop.trace.finish" @context.span_name:llm.call`
- Compute: count
- Formula: `turns`

This widget counts turns. On the normal path a turn issues one `llm.call` span.

### Tool execution latency (p50/p95)

ID `6627607068531676`. Type `timeseries`. Layout: `x:0`, `y:8`, `width:6`, `height:4`.

- Query: `service:hatfield $env message:"agent_loop.trace.finish" @context.span_name:tool.call`
- Computes: `avg` of `@context.duration_ms` (`tool_avg`) and `pc95` of `@context.duration_ms` (`tool_p95`)
- Formulas: `tool_avg`, `tool_p95`

The title says p50/p95 while the definition measures avg and p95. Rename the widget when the next update touches it.

### Hatfield warnings and errors

ID `1954057201076375`. Type `list_stream`. Layout: `x:6`, `y:8`, `width:6`, `height:5`.

- Query: `service:hatfield $env source:php status:(warn OR error OR critical OR alert OR emergency)`

### Logs by status

ID `3448242938512668`. Type `timeseries`. Layout: `x:0`, `y:12`, `width:6`, `height:5`.

- Query: `service:hatfield $env`
- Compute: count
- Group by: `status` (limit 10, count descending)
- Formula: `logs`

### Hatfield process CPU (%)

ID `5473361968423792`. Type `timeseries`. Layout: `x:6`, `y:13`, `width:6`, `height:4`.

- Metric query: `sum:system.processes.cpu.pct{service:hatfield AND $env}`
- Formula: `cpu`

This is the aggregate CPU percentage across the Hatfield processes matched by the Datadog Process Check. It may exceed 100% when the process group uses more than one CPU core.

### Hatfield process RSS memory (bytes)

ID `3097066923179713`. Type `timeseries`. Layout: `x:0`, `y:17`, `width:6`, `height:4`.

- Metric query: `sum:system.processes.mem.rss{service:hatfield AND $env}`
- Formula: `rss`

### Tool failures by tool

ID `6255147078461533`. Type `toplist`. Layout: `x:6`, `y:17`, `width:3`, `height:4`.

- Query: `service:hatfield $env status:warn "Failed to execute tool"`
- Compute: count
- Group by: `@extra.tool_name` (limit 20, count descending)
- Formula: `failures`

Tool failures are warning records, not span statuses. `tool.call` spans finish `status:ok` even when a tool fails.

### Tool error rate (%)

ID `282712535813754`. Type `query_value`. Layout: `x:9`, `y:17`, `width:3`, `height:2`.

- Queries: `service:hatfield $env status:warn "Failed to execute tool"` (count, `failures`) and `service:hatfield $env message:"agent_loop.trace.finish" @context.span_name:tool.call` (count, `calls`)
- Formula: `failures / calls * 100`

### Edit tool error rate (%)

ID `8165117138217963`. Type `query_value`. Layout: `x:9`, `y:19`, `width:3`, `height:2`.

- Queries: `service:hatfield $env status:warn "Failed to execute tool" @extra.tool_name:edit` (count, `failures`) and `service:hatfield $env message:"agent_loop.trace.finish" @context.span_name:tool.call @context.tool_name:edit` (count, `calls`)
- Formula: `failures / calls * 100`

### LLM failures by category

ID `2910107111724977`. Type `toplist`. Layout: `x:0`, `y:21`, `width:6`, `height:4`.

- Query: `service:hatfield $env @context.event_type:llm.request.failed`
- Compute: count
- Group by: `@context.error_category` (limit 20, count descending)
- Formula: `failures`

### LLM retries

ID `939816583907402`. Type `timeseries`. Layout: `x:6`, `y:21`, `width:6`, `height:4`.

- Query: `service:hatfield $env @context.event_type:llm.request.retrying`
- Compute: count
- Formula: `retries`

## Update checklist

1. Read the complete dashboard.
2. Retain every widget, widget ID, template variable, description, and tag.
3. Confirm data for each proposed query. See [Hatfield telemetry](hatfield-telemetry.md).
4. Load the reference for each new widget type.
5. Validate every changed or new widget.
6. Upsert the complete widget list and template variable. Omit `reflow_type` from the payload.
7. Read the dashboard again. Verify the template variable, widget IDs, titles, queries, layouts, description, and tags.
8. Execute every final widget with `datadog_get_widget`. Treat any runtime query error as a failed update even when schema validation passed.

Log widgets use `data_source: "logs"` with `search.query`, `compute`, optional
`group_by`, and formulas. Fixed-layout widgets need explicit `x`, `y`, `width`, and
`height`. The dashboard range controls every widget; do not encode a time range in a
title unless the widget has an explicit override.

The rare-event widgets (LLM-step errors, LLM failures by category, LLM retries) show
data only when failures or retries occurred in the selected range. Use 24 hours or
more to see them.

See [Datadog MCP](mcp.md) for exact tool names, replacement semantics, schema quirks, and access gates.
