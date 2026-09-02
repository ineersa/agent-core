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
  "available_values": ["dev"],
  "defaults": ["dev"]
}
```

Use `$env` in widget queries. Datadog expands it to `env:<value>`. Do not write `env:$env`, because that duplicates the prefix.

## Validated widgets

The dashboard contains twelve widgets. Preserve their IDs when updating them.

### LLM/provider latency

ID `5737519449404669`. Layout: `x:0`, `y:0`, `width:8`, `height:4`.

```json
{
  "type": "timeseries",
  "title": "LLM/provider latency (p95)",
  "requests": [{
    "queries": [{
      "data_source": "spans",
      "name": "llm",
      "search": {"query": "service:hatfield $env resource_name:llm.call"},
      "compute": {"aggregation": "pc95"}
    }],
    "formulas": [{"formula": "llm"}],
    "response_format": "timeseries",
    "display_type": "line"
  }]
}
```

### LLM/provider throughput

ID `8711659535887169`. Layout: `x:8`, `y:0`, `width:2`, `height:4`.

```json
{
  "type": "query_value",
  "title": "LLM/provider throughput",
  "requests": [{
    "aggregator": "last",
    "queries": [{
      "data_source": "spans",
      "name": "llm",
      "search": {"query": "service:hatfield $env resource_name:llm.call"},
      "compute": {"aggregation": "count"}
    }],
    "formulas": [{"formula": "llm"}],
    "response_format": "scalar"
  }]
}
```

### LLM-step errors

ID `7338158574332695`. Layout: `x:10`, `y:0`, `width:2`, `height:4`.

```json
{
  "type": "query_value",
  "title": "LLM-step errors",
  "requests": [{
    "aggregator": "last",
    "queries": [{
      "data_source": "metrics",
      "name": "errors",
      "query": "sum:trace.symfony.messenger.consume.errors{service:hatfield AND $env AND resource_name:llm_-_ineersa_agentcore_domain_message_executellmstep}.as_count()",
      "aggregator": "sum"
    }],
    "formulas": [{"formula": "errors"}],
    "response_format": "scalar"
  }]
}
```

### LLM-step error rate

ID `4737903204572067`. Layout: `x:0`, `y:4`, `width:6`, `height:4`.

```json
{
  "type": "timeseries",
  "title": "LLM-step error rate (%)",
  "requests": [{
    "queries": [
      {
        "data_source": "metrics",
        "name": "errors",
        "query": "sum:trace.symfony.messenger.consume.errors{service:hatfield AND $env AND resource_name:llm_-_ineersa_agentcore_domain_message_executellmstep}.as_count()"
      },
      {
        "data_source": "metrics",
        "name": "steps",
        "query": "sum:trace.symfony.messenger.consume.hits{service:hatfield AND $env AND resource_name:llm_-_ineersa_agentcore_domain_message_executellmstep}.as_count()"
      }
    ],
    "formulas": [{"formula": "errors / steps * 100"}],
    "response_format": "timeseries",
    "display_type": "line"
  }]
}
```

### Tool execution throughput

ID `5065655617896639`. Layout: `x:6`, `y:4`, `width:3`, `height:4`.

```json
{
  "type": "query_value",
  "title": "Tool execution throughput",
  "requests": [{
    "aggregator": "last",
    "queries": [{
      "data_source": "spans",
      "name": "tools",
      "search": {"query": "service:hatfield $env resource_name:tool.call"},
      "compute": {"aggregation": "count"}
    }],
    "formulas": [{"formula": "tools"}],
    "response_format": "scalar"
  }]
}
```

### Tool execution latency

ID `6627607068531676`. Layout: `x:9`, `y:4`, `width:3`, `height:4`.

```json
{
  "type": "timeseries",
  "title": "Tool execution latency (p50/p95)",
  "requests": [{
    "queries": [
      {
        "data_source": "spans",
        "name": "tool_p50",
        "search": {"query": "service:hatfield $env resource_name:tool.call"},
        "compute": {"aggregation": "pc50"}
      },
      {
        "data_source": "spans",
        "name": "tool_p95",
        "search": {"query": "service:hatfield $env resource_name:tool.call"},
        "compute": {"aggregation": "pc95"}
      }
    ],
    "formulas": [
      {"formula": "tool_p50"},
      {"formula": "tool_p95"}
    ],
    "response_format": "timeseries",
    "display_type": "line"
  }]
}
```

### Messenger throughput and latency

ID `6792767117984366`. Layout: `x:0`, `y:8`, `width:12`, `height:4`.

```json
{
  "type": "query_table",
  "title": "Messenger consume: throughput and p95",
  "requests": [{
    "queries": [
      {
        "data_source": "spans",
        "name": "count",
        "search": {"query": "service:hatfield $env operation_name:symfony.messenger.consume"},
        "compute": {"aggregation": "count"},
        "group_by": [{"facet": "resource_name", "limit": 20}]
      },
      {
        "data_source": "spans",
        "name": "p95",
        "search": {"query": "service:hatfield $env operation_name:symfony.messenger.consume"},
        "compute": {"aggregation": "pc95"},
        "group_by": [{"facet": "resource_name", "limit": 20}]
      }
    ],
    "formulas": [
      {"formula": "count"},
      {"formula": "p95"}
    ],
    "response_format": "scalar"
  }]
}
```

### Database operations

ID `4309650939374206`. Layout: `x:0`, `y:12`, `width:12`, `height:4`.

```json
{
  "type": "query_table",
  "title": "Database operations: throughput and p95",
  "requests": [{
    "formulas": [
      {"formula": "count"},
      {"formula": "p95"}
    ],
    "queries": [
      {
        "data_source": "spans",
        "name": "count",
        "search": {"query": "service:hatfield $env operation_name:PDOStatement.execute"},
        "compute": {"aggregation": "count"},
        "group_by": [{"facet": "resource_name", "limit": 10}]
      },
      {
        "data_source": "spans",
        "name": "p95",
        "search": {"query": "service:hatfield $env operation_name:PDOStatement.execute"},
        "compute": {"aggregation": "pc95"},
        "group_by": [{"facet": "resource_name", "limit": 10}]
      }
    ],
    "response_format": "scalar"
  }]
}
```

### Logs by status

ID `3448242938512668`. Layout: `x:0`, `y:16`, `width:6`, `height:5`.

```json
{
  "type": "timeseries",
  "title": "Logs by status",
  "requests": [{
    "display_type": "line",
    "formulas": [{"formula": "logs"}],
    "queries": [{
      "data_source": "logs",
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
    "response_format": "timeseries"
  }]
}
```

### Warning and error stream

ID `1954057201076375`. Layout: `x:6`, `y:16`, `width:6`, `height:5`.

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

### Shared-host CPU

ID `5187045740648865`. Layout: `x:0`, `y:21`, `width:6`, `height:4`.

```json
{
  "type": "timeseries",
  "title": "Shared-host CPU context",
  "requests": [{
    "display_type": "line",
    "formulas": [
      {"formula": "user"},
      {"formula": "system"},
      {"formula": "iowait"}
    ],
    "queries": [
      {
        "data_source": "metrics",
        "name": "user",
        "query": "avg:system.cpu.user{host:server AND $env} by {host}"
      },
      {
        "data_source": "metrics",
        "name": "system",
        "query": "avg:system.cpu.system{host:server AND $env} by {host}"
      },
      {
        "data_source": "metrics",
        "name": "iowait",
        "query": "avg:system.cpu.iowait{host:server AND $env} by {host}"
      }
    ],
    "response_format": "timeseries"
  }]
}
```

These CPU metrics are native percentages. The host is shared, so this widget is not Hatfield-specific usage.

### Shared-host memory

ID `5027319727723062`. Layout: `x:6`, `y:21`, `width:6`, `height:4`.

```json
{
  "type": "timeseries",
  "title": "Shared-host memory usable (%)",
  "requests": [{
    "display_type": "line",
    "formulas": [{"formula": "usable * 100"}],
    "queries": [{
      "data_source": "metrics",
      "name": "usable",
      "query": "avg:system.mem.pct_usable{host:server AND $env} by {host}"
    }],
    "response_format": "timeseries"
  }]
}
```

`system.mem.pct_usable` is a fraction, so the formula converts it to a percentage. The host is shared, so this widget is not Hatfield-specific usage.

## Update checklist

1. Read the complete dashboard.
2. Retain every widget, widget ID, template variable, description, and tag.
3. Confirm data for each proposed query. See [Hatfield telemetry](hatfield-telemetry.md).
4. Load the reference for each new widget type.
5. Validate every changed or new widget.
6. Upsert the complete widget list and template variable.
7. Read the dashboard again. Verify the template variable, widget IDs, titles, queries, layouts, description, and tags.

Dashboard query values follow the dashboard time range. Do not add a time range to a title unless the widget has an explicit time override.

Use `resource_name:llm.call` and `resource_name:tool.call` as the canonical call spans. Their worker spans are nested duplicates.

See [Datadog MCP](mcp.md) for exact tool names, replacement semantics, schema quirks, and access gates.
