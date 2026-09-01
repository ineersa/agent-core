---
name: datadog
description: Investigate Hatfield in Datadog and safely maintain its dashboards, widgets, monitors, and notebooks.
disable-model-invocation: true
---

# Hatfield in Datadog

Use the known Hatfield identifiers below. Do not rediscover them on every run.

## Known Hatfield profile

| Item | Value |
|---|---|
| Primary dashboard | `xza-j7r-e4s` |
| Dashboard URL | `https://app.datadoghq.com/dashboard/xza-j7r-e4s` |
| Dashboard title | `Hatfield` |
| Service | `hatfield` |
| Environment | `dev` |
| Source | `php` |
| Host observed in development | `server` |
| Warning-and-higher query | `service:hatfield env:dev source:php status:(warn OR error OR critical OR alert OR emergency)` |

Hatfield logs expose `trace_id`, `service`, `env`, `source`, `status`, and a version facet. A `span_id` can appear in injected message text. Do not assume that `run_id`, `session_id`, `component`, or `event_type` are facets unless the current query results show them.

## Safety

- Treat every Datadog request as read-only unless the user explicitly authorizes the exact mutation.
- Before a write, restate the resource ID and the exact intended change. Authorization for one widget or resource does not authorize any other change.
- Fetch the complete current resource immediately before a write. Preserve every field and child item outside the authorized change.
- After a write, fetch the resource again and verify the requested change and the preservation of existing content.
- Do not write when the read response omits content needed for preservation, such as an existing dashboard's widget definitions.
- Never place credentials in prompts, files, queries, output, or handoffs. Use the configured MCP connection only.
- Never call internal `_dd_*` tools or bypass a missing MCP capability with direct HTTP requests.

## Find Hatfield logs

1. Start with `datadog_search_datadog_logs`, a `now-15m` to `now` range, and a result limit of 25 or less.
2. Begin with `service:hatfield env:dev source:php`, then add the condition under investigation. Use the recorded warning-and-higher query without probing for alternate service, environment, or source names.
3. Use `datadog_analyze_datadog_logs` for counts and grouped trends. Do not infer a rate from a sample of raw events.
4. Expand the time range only when the narrow search has insufficient evidence.

Do not paste raw log bodies into a handoff. Report the query, UTC or relative range, limit or aggregation, matching count, relevant facets, and uncertainty.

## Follow a log into a trace

1. Find a relevant Hatfield log with `datadog_search_datadog_logs`.
2. Read its `trace_id`.
3. Fetch that trace with `datadog_get_datadog_trace`.
4. Use `datadog_search_datadog_spans` only when the log has no usable trace ID or the trace lookup needs a narrow timestamp and service search.
5. Correlate by trace ID, timestamp, service, environment, and version. Treat temporal proximity without a shared identifier as inference.

## Maintain the Hatfield dashboard

Use dashboard `xza-j7r-e4s` unless the user names another dashboard.

For a widget change:

1. Call `datadog_get_datadog_dashboard` and require the complete dashboard definition, including every existing widget and layout.
2. Call `datadog_get_widget_reference` for the intended widget type. Do not guess the widget schema.
3. Build one widget from the requested Hatfield query. Preserve the dashboard title, description, layout type, template variables, access settings, and all existing widgets.
4. Call `datadog_validate_dashboard_widget` before writing.
5. Call `datadog_upsert_datadog_dashboard` with dashboard ID `xza-j7r-e4s` and the complete preserved definition.
6. Fetch `xza-j7r-e4s` again. Verify the new widget and confirm that the prior widgets remain.

Dashboard widgets are members of the dashboard upsert payload. There is no separate widget-create step. `datadog_search_datadog_widgets` and related tools inspect or verify widgets; they do not replace dashboard upsert.

## Other resource changes

- Use `datadog_get_datadog_monitor_templates` and `datadog_validate_datadog_monitor` before `datadog_create_datadog_monitor`. The public tool creates a draft monitor; do not claim that it updates an existing monitor.
- Fetch a notebook before calling `datadog_edit_datadog_notebook`. Use `datadog_create_datadog_notebook` only when the user authorizes a new notebook.
- Treat incidents and SLOs as read-only unless the runtime catalog exposes a documented public write tool and the user authorizes that exact write.
- Exclude dashboard deletion, monitor muting, notification changes, and unrelated Datadog product mutations unless the user requests them explicitly.

## Required MCP capabilities

The Datadog MCP connection must request the `core`, `alerting`, `dashboards`, and `widgets` toolsets. Hatfield loads the MCP catalog when the parent session starts. If `datadog_upsert_datadog_dashboard`, `datadog_get_widget_reference`, or `datadog_validate_dashboard_widget` is missing, stop and report that the session needs to restart with the updated MCP configuration.

The Datadog principal also needs `mcp_read` or `mcp_write` and the corresponding dashboard, monitor, or notebook permissions. A missing tool indicates catalog configuration; a permission error indicates Datadog authorization.

## Handoff

Include:

- the question or authorized mutation;
- the dashboard or resource ID;
- each source tool;
- the exact query and time range;
- the result limit or aggregation;
- concise evidence and correlation identifiers;
- the write and post-write verification result, or the exact missing tool or permission;
- uncertainty without guesses.
