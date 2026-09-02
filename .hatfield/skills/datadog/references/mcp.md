# Datadog MCP

## Hatfield configuration

The Datadog MCP URL requests the `core`, `alerting`, `dashboards`, and `widgets` toolsets. Hatfield prefixes published tools with `datadog_`.

Every Datadog MCP call requires a short `telemetry.intent`. Never call internal `_dd_*` tools or tools marked `do-not-call`.

Large results can be truncated. Read a returned output file in bounded chunks instead of repeating a broad query.

## Dashboard tools

Use this sequence for dashboard changes:

1. `datadog_get_datadog_dashboard` reads the complete current dashboard.
2. `datadog_get_widget_reference` returns the schema for each new widget type.
3. `datadog_validate_dashboard_widget` validates every changed or new widget.
4. `datadog_upsert_datadog_dashboard` updates the authorized dashboard.
5. `datadog_get_datadog_dashboard` verifies the result.

Dashboard upsert replaces the widget list. Retain every unchanged widget and its ID. Retain template variables, the description, and tags unless the user authorizes changing them.

Fixed dashboards require explicit `x`, `y`, `width`, and `height` layout fields. Dashboard reads return `reflow_type`, but dashboard upsert rejects that field. Omit `reflow_type` from the mutation payload.

Do not guess empty schema objects. Use the published schema and widget validation tool.

## Authentication and organization gates

A Personal Access Token can have dashboard read and write scopes while Datadog still hides MCP mutation tools. Datadog also has an organization-level MCP write setting.

When the organization setting was disabled, dashboard reads worked but these tools were absent:

- `datadog_upsert_datadog_dashboard`
- `datadog_get_widget_reference`
- `datadog_validate_dashboard_widget`

Enabling the organization-level setting and reconnecting published the tools.

If a required tool is absent, do not use a direct HTTP fallback. Report the missing tool and ask the user to check the organization-level MCP access and write settings.
