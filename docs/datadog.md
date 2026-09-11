# Datadog setup for local Hatfield development

Optional local log and process observability for maintainers. Not model-visible.

Hatfield does not use the ddtrace PHP extension. It was retired on 2026-09-05 after
`datadog-ipc-helper` processes exhausted host memory and CPU
([dd-trace-php#4042](https://github.com/DataDog/dd-trace-php/issues/4042)). Keep the
extension disabled. The application emits no APM spans, no `trace.*` metrics, no
profiling data, and no log trace correlation. Observability comes from structured
JSONL logs, the Datadog Agent's file collection and Process Check, and log-derived
metrics defined in Datadog.

## Principles

- Structured event-style log messages with correlation fields (`run_id`, `session_id`, `component`, `event_type`).
- Never log raw prompts, tool output, env secrets, API keys, or full session content.
- Logging and application work never depend on Datadog. No Datadog client, transport, or buffer runs in the application.

## Castor helpers

```bash
castor datadog:smoke        # Agent status, log path, and collector config diagnostic
castor datadog:log-config   # print ops/datadog/hatfield.d/conf.yaml + install hints
castor datadog:smoke-log    # append one JSONL smoke line under project .hatfield/logs
```

## Agent log collection

1. Enable logs in the Datadog Agent (`logs_enabled: true`).
2. Install the sample config from this repo:

```bash
castor datadog:log-config
# then follow the printed install steps, e.g.:
sudo mkdir -p /etc/datadog-agent/conf.d/hatfield.d
sudo install -o dd-agent -g dd-agent -m 0644 \
  ops/datadog/hatfield.d/conf.yaml \
  /etc/datadog-agent/conf.d/hatfield.d/conf.yaml
```

3. Ensure the Agent user can traverse to and read Hatfield log files under the active
   log directory (`logging.path` / `HATFIELD_LOG_DIR`, default project `.hatfield/logs/`).
   On Linux this often means ACL execute on parent dirs and read on the log dir
   (`setfacl`). Exact paths depend on your checkout layout, so adapt the hints printed
   by `castor datadog:log-config`.
4. Restart the Agent and write a smoke line:

```bash
# Linux (systemd). Adapt for other service managers.
sudo systemctl restart datadog-agent
castor datadog:smoke-log
```

`castor datadog:smoke-log` writes under project `.hatfield/logs/` only. It does
not follow a custom `logging.path` / `HATFIELD_LOG_DIR`. For a non-default log path,
inspect or inject a smoke line in the configured directory manually.

Search Logs Explorer for the printed smoke message. The sample config includes
masking rules for common secret shapes. It collects the main checkout
(`checkout:main`) and worktree logs (`checkout:worktree`), so scope queries when a
result should cover only one checkout.

## Hatfield process metrics

The Datadog Process Check can aggregate CPU, resident memory, and process I/O for
the Hatfield CLI, controller, and Messenger workers. Install the sample config:

```bash
sudo install -o dd-agent -g dd-agent -m 0644 \
  ops/datadog/process.d/conf.yaml \
  /etc/datadog-agent/conf.d/process.d/conf.yaml
sudo systemctl restart datadog-agent
```

The config matches Hatfield PHAR processes and source launches through
`bin/console`. It tags the resulting `system.processes.*` metrics with
`service:hatfield`, `env:dev`, and `app:hatfield`.

Check the integration after the Agent has completed one collection interval:

```bash
sudo datadog-agent check process
```

Use these dashboard queries:

```text
sum:system.processes.cpu.pct{service:hatfield AND $env}
sum:system.processes.mem.rss{service:hatfield AND $env}
per_second(sum:system.processes.ioread_bytes_count{service:hatfield AND $env})
per_second(sum:system.processes.iowrite_bytes_count{service:hatfield AND $env})
```

The Process Check must match every Hatfield process that belongs in the total.
Short-lived processes can disappear before collection. Datadog documents
`system.processes.cpu.pct` as inaccurate for processes that live for less than
30 seconds. Linux process I/O availability also depends on Agent access to
`/proc/<pid>/io`; verify both I/O metrics before adding dashboard widgets.

## Application metrics from logs

Eight dashboard widgets (`xza-j7r-e4s`) queried APM spans and `trace.*` metrics and
went empty when the extension was disabled. The signals behind them survive in the
JSONL logs, so they can be republished as log-based metrics. This needs no
application change and no new transport: Datadog derives the metrics from the log
stream it already collects.

### Field rules

- Span metrics filter `message:agent_loop.trace.finish` and the canonical span name
  (`tool.call`, `llm.call`). Without the finish filter, each `agent_loop.trace.start`
  record doubles the count. Without the span-name filter, the nested worker spans
  (`turn.execution.llm_worker`, `turn.execution.tool_worker`) double it again.
- Tool failures are warning records, not span statuses. `tool.call` spans finish
  `status:ok` even when a tool fails, because the worker converts the exception into a
  model-visible tool result. Symfony AI Toolbox emits
  `Failed to execute tool "<name>".` at warning level, and the ambient log context
  attaches `@extra.tool_name`.
- LLM requests emit a success/failure pair: `llm.request.completed` and
  `llm.request.failed` (with `@context.error_category`). Retries emit
  `llm.request.retrying` with `@context.attempt` and `@context.max_attempts`.
- Span records and request events are INFO, so they need `logging.level: info`. The
  project `.hatfield/settings.yaml` sets this; the shipped default is `warning`.
  Failure metrics keep working at `warning` level.
- Durations are numeric: `@context.duration_ms` works as a measure (verified against
  indexed logs on 2026-09-11).

### Proposed log-based metrics

| Metric | Log query | Group by | Measure |
|---|---|---|---|
| `hatfield.tool.calls` | `service:hatfield env:dev message:agent_loop.trace.finish @context.span_name:tool.call` | `@context.tool_name` | count |
| `hatfield.tool.failures` | `service:hatfield env:dev status:warn "Failed to execute tool"` | `@extra.tool_name` | count |
| `hatfield.llm.requests.completed` | `service:hatfield env:dev @context.event_type:llm.request.completed` | `@context.model` (optional) | count |
| `hatfield.llm.requests.failed` | `service:hatfield env:dev @context.event_type:llm.request.failed` | `@context.error_category` | count |
| `hatfield.llm.requests.retrying` | `service:hatfield env:dev @context.event_type:llm.request.retrying` | `@context.attempt` | count |
| `hatfield.llm.duration` | `service:hatfield env:dev message:agent_loop.trace.finish @context.span_name:llm.call` | `@context.model` (optional) | distribution of `@context.duration_ms` |
| `hatfield.tool.duration` | `service:hatfield env:dev message:agent_loop.trace.finish @context.span_name:tool.call` | `@context.tool_name` | distribution of `@context.duration_ms` |

Rates come from widget formulas over these metrics:

```text
# Tool error rate (all tools)
sum:hatfield.tool.failures{service:hatfield AND $env}.as_count()
  / sum:hatfield.tool.calls{service:hatfield AND $env}.as_count()

# Edit tool error rate
sum:hatfield.tool.failures{service:hatfield AND $env AND context.tool_name:edit}.as_count()
  / sum:hatfield.tool.calls{service:hatfield AND $env AND context.tool_name:edit}.as_count()

# LLM error rate
sum:hatfield.llm.requests.failed{service:hatfield AND $env}.as_count()
  / (sum:hatfield.llm.requests.completed{service:hatfield AND $env}.as_count()
     + sum:hatfield.llm.requests.failed{service:hatfield AND $env}.as_count())
```

Datadog turns each group-by facet into a metric tag. Confirm the exact tag name in
Metrics Explorer after publication; the leading `@` from the log attribute is not
part of the metric tag in practice for the checked queries.

### Publication

The connected MCP catalog publishes read-only log and metric tools, plus dashboard
writes. It does not publish log-based metric or facet management tools. Create each
metric in Datadog under Logs > Configuration > Log-Based Metrics, or with the Logs
Metrics API. Then verify against a narrow window and compare the counts with the
local log file for the same day. Expect small differences from ingestion lag and from
worktree logs that the Agent collects.

### Alternatives evaluated

DogStatsD was the other candidate. The Agent's UDP listener works without the PHP
extension (`127.0.0.1:8125`), but publishing needs a client
(`datadog/php-datadogstatsd`, `league/statsd`, or a small emitter), a configuration
gate, and explicit failure behavior when the Agent is down. That adds a dependency
and a write path for signals the logs already carry, so it is not adopted here.
Revisit DogStatsD when a required signal has no log record, for example database
statement latency or queue depth.

## Retained visibility and intentional loss

Retained:

- All structured application logs in Datadog Logs, including warnings and errors.
- Log-derived application metrics listed above, once published.
- Process Check metrics: CPU, RSS, and process I/O for matched Hatfield processes.

Intentionally lost with the extension:

- APM traces and trace waterfall, and the `trace.*` metrics derived from them.
- `dd.trace_id` / `dd.span_id` log correlation. Log ingestion continues; the
  trace-linked views and trace-based filters do not.
- Profiling and database statement spans (`PDOStatement.execute`).
- The eight span-based dashboard widgets: LLM latency, LLM throughput, LLM-step
  errors, LLM-step error rate, tool throughput, tool latency, Messenger consume,
  and database operations. Repoint them to the log-derived metrics or remove them.

Verify extension-free operation:

```bash
php -m | grep ddtrace    # expect no output
castor datadog:smoke     # expect: log path readable, collector config present
php bin/console diagnostic
```

## Provider failure diagnostics

The `llm.provider.stream_error` warning includes HTTP status, content type, and
the response request ID when available. For non-JSON HTTP errors, it also includes
`response_body_preview`, capped at 2,048 UTF-8 bytes, and `response_body_truncated`.
The existing diagnostic sanitizer redacts common credential patterns before truncation.
JSON error bodies retain structural metadata only.

Redaction is best-effort. Non-JSON error pages can echo request content that the
sanitizer does not recognize. Treat these logs as sensitive and review excerpts
before sharing them.

## Related

- Logging keys: [settings.md](settings.md)
- Runtime privacy notes: root `AGENTS.md`
- Logging architecture: [architecture/logging.md](../architecture/logging.md)
