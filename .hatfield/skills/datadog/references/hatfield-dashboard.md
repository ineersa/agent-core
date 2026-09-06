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

ID `5737519449404669`. Layout: `x:0`, `y:0`, `width:4`, `height:4`.

```json
{
  "type": "timeseries",
  "title": "LLM/provider latency (p95)",
  "requests": [{
    "queries": [{
      "data_source": "spans",
      "indexes": ["*"],
      "name": "llm",
      "search": {"query": "service:hatfield $env resource_name:llm.call"},
      "compute": {
        "aggregation": "pc95",
        "metric": "@duration"
      }
    }],
    "formulas": [{"formula": "llm"}],
    "response_format": "timeseries",
    "display_type": "line"
  }]
}
```

### LLM/provider throughput

ID `8711659535887169`. Layout: `x:8`, `y:2`, `width:2`, `height:2`.

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

ID `7338158574332695`. Layout: `x:10`, `y:0`, `width:2`, `height:2`.

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

ID `4737903204572067`. Layout: `x:4`, `y:0`, `width:4`, `height:4`.

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

ID `5065655617896639`. Layout: `x:8`, `y:0`, `width:2`, `height:2`.

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

ID `6627607068531676`. Layout: `x:0`, `y:4`, `width:6`, `height:4`.

```json
{
  "type": "timeseries",
  "title": "Tool execution latency (p50/p95)",
  "requests": [{
    "queries": [
      {
        "data_source": "spans",
        "indexes": ["*"],
        "name": "tool_p50",
        "search": {"query": "service:hatfield $env resource_name:tool.call"},
        "compute": {
          "aggregation": "pc50",
          "metric": "@duration"
        }
      },
      {
        "data_source": "spans",
        "indexes": ["*"],
        "name": "tool_p95",
        "search": {"query": "service:hatfield $env resource_name:tool.call"},
        "compute": {
          "aggregation": "pc95",
          "metric": "@duration"
        }
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

ID `6792767117984366`. Layout: `x:0`, `y:8`, `width:6`, `height:4`.

```json
{
  "type": "query_table",
  "title": "Messenger consume: throughput and p95",
  "requests": [{
    "queries": [
      {
        "data_source": "spans",
        "indexes": ["*"],
        "name": "count",
        "search": {"query": "service:hatfield $env operation_name:symfony.messenger.consume"},
        "compute": {"aggregation": "count"},
        "group_by": [{"facet": "resource_name", "limit": 20}]
      },
      {
        "data_source": "spans",
        "indexes": ["*"],
        "name": "p95",
        "search": {"query": "service:hatfield $env operation_name:symfony.messenger.consume"},
        "compute": {
          "aggregation": "pc95",
          "metric": "@duration"
        },
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

ID `4309650939374206`. Layout: `x:6`, `y:4`, `width:6`, `height:4`.

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
        "indexes": ["*"],
        "name": "count",
        "search": {"query": "service:hatfield $env operation_name:PDOStatement.execute"},
        "compute": {"aggregation": "count"},
        "group_by": [{"facet": "resource_name", "limit": 10}]
      },
      {
        "data_source": "spans",
        "indexes": ["*"],
        "name": "p95",
        "search": {"query": "service:hatfield $env operation_name:PDOStatement.execute"},
        "compute": {
          "aggregation": "pc95",
          "metric": "@duration"
        },
        "group_by": [{"facet": "resource_name", "limit": 10}]
      }
    ],
    "response_format": "scalar"
  }]
}
```

### Logs by status

ID `3448242938512668`. Layout: `x:0`, `y:12`, `width:6`, `height:5`.

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

ID `1954057201076375`. Layout: `x:6`, `y:8`, `width:6`, `height:5`.

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

### Hatfield process CPU

ID `5473361968423792`. Layout: `x:6`, `y:13`, `width:6`, `height:4`.

```json
{
  "type": "timeseries",
  "title": "Hatfield process CPU (%)",
  "requests": [{
    "queries": [{
      "data_source": "metrics",
      "name": "cpu",
      "query": "sum:system.processes.cpu.pct{service:hatfield AND $env}"
    }],
    "formulas": [{"formula": "cpu"}],
    "response_format": "timeseries",
    "display_type": "line"
  }],
  "show_legend": true
}
```

This is the aggregate CPU percentage across the Hatfield processes matched by the Datadog Process Check. It may exceed 100% when the process group uses more than one CPU core.

### Hatfield process RSS memory

ID `3097066923179713`. Layout: `x:0`, `y:17`, `width:6`, `height:4`.

```json
{
  "type": "timeseries",
  "title": "Hatfield process RSS memory (bytes)",
  "requests": [{
    "queries": [{
      "data_source": "metrics",
      "name": "rss",
      "query": "sum:system.processes.mem.rss{service:hatfield AND $env}"
    }],
    "formulas": [{"formula": "rss"}],
    "response_format": "timeseries",
    "display_type": "line"
  }],
  "show_legend": true,
  "yaxis": {
    "include_zero": false,
    "label": "bytes",
    "scale": "linear"
  }
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
8. Execute every final widget with `datadog_get_widget`. Treat any runtime query error as a failed update even when schema validation passed.

Dashboard query values follow the dashboard time range. Do not add a time range to a title unless the widget has an explicit time override.

Use `resource_name:llm.call` and `resource_name:tool.call` as the canonical call spans. Their worker spans are nested duplicates.

Span percentile widgets require `indexes: ["*"]` and `compute.metric: "@duration"`. Schema validation does not catch every missing runtime field.

See [Datadog MCP](mcp.md) for exact tool names, replacement semantics, schema quirks, and access gates.
