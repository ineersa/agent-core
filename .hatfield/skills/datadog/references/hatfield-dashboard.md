# Hatfield dashboard

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
  "defaults": ["dev"],
  "available_values": ["dev"]
}
```

Use `$env` in widget queries. Datadog expands it to `env:<value>`. Do not write `env:$env`, because that duplicates the prefix.

## Validated widgets

The dashboard contains five widgets. Preserve their IDs when updating them.

### Hatfield activity

Widget ID `2566829832931263`. Layout: `x:0`, `y:0`, `width:8`, `height:4`.

```json
{
  "type": "timeseries",
  "title": "Hatfield activity",
  "requests": [{
    "data_source": "metrics",
    "queries": [{
      "name": "hits",
      "query": "sum:trace.hatfield.phar.hits{$env} by {env}.as_count()"
    }],
    "formulas": [{"formula": "hits"}],
    "response_format": "timeseries",
    "display_type": "line"
  }]
}
```

### Hatfield log volume

Widget ID `1080218736501504`. Layout: `x:8`, `y:0`, `width:4`, `height:4`.

```json
{
  "type": "query_value",
  "title": "Hatfield log volume",
  "requests": [{
    "data_source": "logs",
    "queries": [{
      "name": "logs",
      "indexes": ["*"],
      "search": {"query": "service:hatfield $env"},
      "compute": {"aggregation": "count"}
    }],
    "formulas": [{"formula": "logs"}],
    "response_format": "scalar"
  }]
}
```

### Hatfield logs by status

Widget ID `3448242938512668`. Layout: `x:0`, `y:4`, `width:8`, `height:5`.

```json
{
  "type": "timeseries",
  "title": "Hatfield logs by status",
  "requests": [{
    "data_source": "logs",
    "queries": [{
      "name": "logs",
      "indexes": ["*"],
      "search": {"query": "service:hatfield $env"},
      "compute": {"aggregation": "count"},
      "group_by": [{
        "facet": "status",
        "limit": 10,
        "sort": {"aggregation": "count", "order": "desc"}
      }]
    }],
    "formulas": [{"formula": "logs"}],
    "response_format": "timeseries",
    "display_type": "line"
  }]
}
```

### Hatfield spans by resource

Widget ID `4536005268307188`. Layout: `x:8`, `y:4`, `width:4`, `height:5`.

```json
{
  "type": "toplist",
  "title": "Hatfield spans by resource",
  "requests": [{
    "data_source": "spans",
    "queries": [{
      "name": "spans",
      "search": {"query": "service:hatfield $env"},
      "compute": {"aggregation": "count"},
      "group_by": [{
        "facet": "resource_name",
        "limit": 10,
        "sort": {"aggregation": "count", "order": "desc"}
      }]
    }],
    "formulas": [{"formula": "spans"}],
    "response_format": "scalar"
  }]
}
```

### Hatfield warnings and errors

Widget ID `1954057201076375`. Layout: `x:0`, `y:9`, `width:12`, `height:5`.

```json
{
  "type": "log_stream",
  "title": "Hatfield warnings and errors",
  "query": "service:hatfield $env source:php status:(warn OR error OR critical OR alert OR emergency)",
  "columns": ["service", "status", "message"],
  "indexes": [],
  "message_display": "expanded-md",
  "show_date_column": true,
  "show_message_column": true
}
```

## Update checklist

1. Read the complete dashboard.
2. Retain every widget, widget ID, template variable, description, and tag.
3. Confirm data for each proposed query. See [Hatfield telemetry](hatfield-telemetry.md).
4. Load the reference for each new widget type.
5. Validate every changed or new widget.
6. Upsert the complete widget list and template variable.
7. Read the dashboard again. Verify the template variable, widget IDs, titles, queries, layouts, description, and tags.

See [Datadog MCP](mcp.md) for exact tool names, replacement semantics, schema quirks, and access gates.
