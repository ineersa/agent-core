# Datadog setup for local Hatfield development

Hatfield Datadog support is intentionally opt-in. The PHP `ddtrace` extension and the Datadog Agent are separate pieces:

- **APM/traces**: `ddtrace` in the PHP CLI process sends spans to the local Agent trace socket.
- **Logs**: the Datadog Agent tails Hatfield JSONL log files from `.hatfield/logs`.

## Current local paths

The checked-in Datadog log template tails:

- main checkout: `/home/ineersa/projects/agent-core/.hatfield/logs/*.log`
- task worktrees: `/home/ineersa/projects/agent-core-worktrees/*/.hatfield/logs/*.log`

## Agent log collection

1. Ensure `/etc/datadog-agent/datadog.yaml` contains only explicit collection settings for this use case:

   ```yaml
   logs_enabled: true
   container_collect_all: false

   apm_config:
     enabled: true
   ```

   Avoid `extra_config_providers: [process_log]` and broad process log collection when you only want Hatfield logs.

2. Install the Hatfield log config under an integration directory (not directly as `conf.d/conf.yaml`):

   ```bash
   sudo mkdir -p /etc/datadog-agent/conf.d/hatfield.d
   sudo install -o dd-agent -g dd-agent -m 0644 \
     ops/datadog/hatfield.d/conf.yaml \
     /etc/datadog-agent/conf.d/hatfield.d/conf.yaml
   sudo rm -f /etc/datadog-agent/conf.d/conf.yaml
   ```

3. Let the `dd-agent` user traverse the home directory and read Hatfield logs:

   ```bash
   setfacl -m u:dd-agent:--x /home/ineersa
   setfacl -m u:dd-agent:rX /home/ineersa/projects/agent-core/.hatfield/logs
   setfacl -m u:dd-agent:rX /home/ineersa/projects/agent-core-worktrees 2>/dev/null || true
   ```

4. Restart the Agent and generate a fresh log line:

   ```bash
   sudo systemctl restart datadog-agent
   castor datadog:smoke-log
   ```

The Agent tails from the current file offset; it will not backfill old Hatfield log lines after a new config is installed.

## Optional APM/traces

The normal launcher auto-enables Datadog APM when `ddtrace` is loaded and a local Datadog trace endpoint is reachable:

```bash
castor run:agent
```

You can force the default launcher either way:

```bash
HATFIELD_DATADOG=1 castor run:agent   # force-enable if ddtrace is loaded
HATFIELD_DATADOG=0 castor run:agent   # force-disable
```

When enabled, the launcher sets:

- `DD_TRACE_ENABLED=1`
- `DD_TRACE_CLI_ENABLED=1`
- `DD_SERVICE=hatfield`
- `DD_ENV=dev` unless already set
- `DD_VERSION=<git short sha>` when available
- `DD_LOGS_INJECTION=true`
- `DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=true`
- `DD_TRACE_AGENT_URL=unix:///var/run/datadog/apm.socket` when the socket exists

## Local checks

```bash
castor datadog:smoke
php --ri ddtrace | grep -E 'enabled_cli|agent_url|service|env|append_trace_ids'
```

If logs do not appear, check:

- `/etc/datadog-agent/conf.d/hatfield.d/conf.yaml` exists.
- `logs_enabled: true` is set.
- `/home/ineersa` has an ACL entry for `dd-agent` execute traversal.
- a new log line was written after the Agent restart.

## Structured correlation fields (RunLogContext)

All logs emitted within a run, handler, or worker scope automatically carry
correlation context injected by `RunLogContext` + `LogContextProcessor`.

Fields set automatically (where known):

| Field | Description | Set by |
|---|---|---|
| `run_id` | Active run identifier | RunOrchestrator, ExecuteLlmStepWorker, ExecuteToolCallWorker |
| `session_id` | Session (same as run_id) | RunOrchestrator, workers |
| `component` | Subsystem: `runtime`, `llm`, `tool`, `storage` | RunMessageProcessor, workers |
| `queue` | Messenger bus: `agent.command.bus`, `agent.execution.bus` | RunMessageProcessor, workers |
| `scope` | Message processing scope | RunMessageProcessor |
| `handler` | RunMessageHandler class name | RunMessageProcessor |
| `worker` | Worker type: `llm`, `tool` | Workers |
| `tool_name` | Current tool being executed | ExecuteToolCallWorker |
| `model` | Model name for LLM requests | ExecuteLlmStepWorker |
| `provider` | Provider: `symfony-ai` | ExecuteLlmStepWorker |
| `event_type` | Current event/message type | Various |
| `retry_count` | Current CAS retry attempt | RunMessageProcessor |
| `dd.trace_id` | Datadog trace ID for log-trace correlation | LogContextProcessor (automatic) |
| `dd.span_id` | Datadog span ID for log-trace correlation | LogContextProcessor (automatic) |

### Recommended Datadog facets

Configure these as Datadog Log Explorer facets:

- `run_id` — group/filter by run
- `session_id` — group/filter by session
- `component` — filter to subsystem: `runtime`, `llm`, `tool`, `storage`
- `event_type` — filter to specific event names
- `handler` — filter by handler class
- `queue` — filter by Messenger bus
- `worker` — filter by worker type (`llm`, `tool`)
- `tool_name` — filter by tool being executed
- `model` — filter by LLM model
- `dd.trace_id` — link log to trace
- `dd.span_id` — link log to span

## Event-style log messages

Important runtime events now emit stable event-name messages instead of prose.
Search Datadog for these message patterns:

| Log message | Context fields | When |
|---|---|---|
| `messenger.message.cas_conflict_retry` | scope, run_id, message_type, attempt | CAS retry attempt |
| `messenger.message.cas_conflict_exhausted` | scope, run_id, message_type, attempts | All CAS retries exhausted |
| `persistence.events_committed` | run_id, event_count, events_by_type, new_status | Events persisted to store |
| `event_store.appended` | run_id, seq, turn_no, event_type | Single event appended |
| `llm.request.completed` | duration_ms | LLM request succeeded |
| `llm.request.failed` | duration_ms, error_type | LLM request failed |
| `agent_loop.trace.start` | span_id, span_name, run_id, ... | Span started |
| `agent_loop.trace.finish` | span_id, span_name, duration_ms, status | Span finished |

Datadog query example — find all errors for a specific run:
```
@run_id:"run-abc123" AND @level:error
```

Query — all LLM activity for a run:
```
@run_id:"run-abc123" AND @component:llm
```

## Span and trace names (ddtrace)

When ddtrace is loaded, the following spans are emitted automatically through
`RunTracer.inSpan()` + `DdtraceSpanProvider`:

| Span name | Tags |
|---|---|
| `command.start_run` | run_id, turn_no, step_id |
| `command.apply` | run_id, turn_no, step_id, command_kind |
| `turn.orchestrator.advance` | run_id, turn_no, step_id |
| `turn.orchestrator.llm_result` | run_id, turn_no, step_id |
| `turn.orchestrator.tool_result` | run_id, turn_no, step_id, tool_call_id |
| `turn.execution.llm_worker` | run_id, turn_no, step_id, worker |
| `turn.execution.tool_worker` | run_id, turn_no, step_id, tool_call_id, tool_name, worker |
| `llm.call` | run_id, turn_no, step_id, model |
| `tool.call` | run_id, turn_no, step_id, tool_call_id, tool_name |
| `persistence.commit` | run_id, turn_no, step_id, event_count, effects_count |
| `replay.rebuild_hot_prompt_state` | run_id |
| `command.application.turn_start_boundary` | run_id, turn_no, step_id |
| `command.application.stop_boundary` | run_id, turn_no, step_id |

A span's `outcome` tag is set to `success` or `error` on close.
Duration is recorded in `duration_ms`.

## Privacy note

Hatfield logs can contain prompts, paths, tool output, and exception context. The Agent config masks common secret shapes, but it cannot reliably remove all sensitive prompt/session content. Keep this enabled only for local development or after reviewing/redacting what the application logs.
