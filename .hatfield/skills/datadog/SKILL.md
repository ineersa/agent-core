---
name: datadog
description: Investigate Datadog logs, traces, metrics, monitors, dashboards, and incidents with evidence correlation and mutation safety. Use when diagnosing Datadog observability data, querying Datadog, or changing a Datadog resource.
---

# Datadog observability

For Hatfield work, read the references that match the task before calling Datadog tools:

- [Hatfield telemetry](references/hatfield-telemetry.md) contains known service identifiers, queries, facets, metrics, and trace correlation fields.
- [Hatfield dashboard](references/hatfield-dashboard.md) contains the dashboard identifier, environment selector, validated widget definitions, layouts, and the safe update sequence.
- [Datadog MCP](references/mcp.md) contains tool naming, schema quirks, authentication gates, and the organization-level write setting.

## Safety

- Treat every Datadog request as read-only unless the user explicitly authorizes the exact mutation.
- Before creating, editing, muting, acknowledging, or deleting a Datadog resource, restate the target and intended effect; do not infer approval from an investigation request.
- Never place credentials in prompts, files, queries, output, or handoffs. Use the configured MCP connection only.
- Keep query scope narrow: begin with the smallest useful time range, service/environment filter, field set, and result/token limit. Expand only when evidence requires it.

## Investigation workflow

1. State the question and an explicit UTC or relative time range. Load the relevant Hatfield reference for Hatfield work. For another service, discover available guides with `list_datadog_skills` and load the relevant guide before using unfamiliar query syntax.
2. Use searches to find raw evidence: `search_datadog_logs`, `search_datadog_spans`, `search_datadog_metrics`, `search_datadog_monitors`, `search_datadog_dashboards`, `search_datadog_incidents`, and service and dependency searches. Use aggregations (`analyze_datadog_logs`, `aggregate_spans`, `aggregate_events`) for counts, rates, or grouped trends instead of sampling raw events.
3. Correlate by timestamp, service, environment, trace ID, deployment/change event, monitor state, dashboard query, or incident timeline. Retrieve a trace, dashboard, incident, or change story only after identifying its ID from read-only evidence.
4. Distinguish observation from inference. If evidence is insufficient, say what query/range is missing rather than guessing.

## Tool naming and output

- Use the runtime catalog, not assumed names. Hatfield exposes Datadog MCP tools as `datadog_<tool>`.
- The connected catalog includes log, span, trace, metric, monitor, dashboard, incident, event, service, dependency, host, notebook, and Datadog-guide tools. It also includes internal `_dd_*` tools marked `do-not-call`; never invoke those.
- Include in every handoff: question, exact query or identifier, time range, source tool, relevant limit/aggregation, concise evidence, correlation, and uncertainty. Do not paste raw log bodies or unrelated tenant data.
