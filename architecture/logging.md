# Logs, tracing, and process metrics

[Architecture map](README.md) · [Processes](processes-and-queues.md)

Logs explain execution. Canonical session events reconstruct conversation state.
Bash output, provider capture, application logs, and Datadog-collected telemetry are
separate streams with different retention and privacy rules.

## Application log pipeline

```mermaid
flowchart TD
    Producers["Independent log producers<br/>TUI, controller, AgentCore,<br/>LLM, tools, extensions"] --> PSR[Psr LoggerInterface]
    PSR --> Monolog[Monolog logger and service handler wiring]
    Processor["LogContextProcessor<br/>ambient run scope, PID, memory"]
    Monolog --> Processor
    Processor --> Filter[Main handler level and channel selection]
    Filter --> Handler[HatfieldRotatingLogHandler]
    Handler --> JSON[JsonFormatter with newline and stack traces]
    JSON --> Files[("resolved logDir/agent-YYYY-MM-DD.log")]
    Filter -.->|"event, doctrine, console excluded from main handler"| Excluded[Not written by this handler]
```

The processor enriches records; it is not a secret scrubber. Main-handler exclusions
are channel names, not guarantees that all database or console-related information
is absent from every application log message.

## Directory, format, and retention

```mermaid
flowchart TB
    Settings["logging.path, logging.level, logging.maxFiles"] --> Loader[AppConfigLoader resolves configuration]
    Loader --> Config[LoggingConfig]
    Config --> Handler[HatfieldRotatingLogHandler]
    Handler --> Directory{Log directory exists?}
    Directory -->|No| Mkdir[Create directory, mode 0755]
    Directory -->|Yes| Write[Parent RotatingFileHandler write]
    Mkdir --> Write
    Mkdir -->|Failure| Error[RuntimeException propagates]
    Write --> JSONL[(Daily rotated JSONL files)]
    Write -->|Write failure| Failure[Exception, not silent discard]
```

| Property | Current behavior |
|---|---|
| Application directory | Resolved `logging.path`, normally project `.hatfield/logs` |
| File basename | Handler starts from `agent.log`; Monolog adds the rotation date |
| Format | One JSON object per line, stack traces included |
| Default minimum level | `info` in `LoggingConfig` |
| Default retained file count | 14 in `LoggingConfig` |
| Creation permissions | Directory `0755`, log file `0644`, subject to process umask |
| File locking | Handler explicitly sets `useLocking: false` |
| Sink failure | Can propagate; this is not a best-effort discard handler |

Symfony kernel log-directory resolution through `HATFIELD_LOG_DIR` is a separate
path from the custom handler's injected `LoggingConfig`. Do not assume that setting
one automatically retargets every log or capture file. Check the owning writer.

## Correlation belongs to each execution scope

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    participant Controller
    participant Queue as Messenger transport
    participant Worker as Run-control, LLM, or tool worker
    participant Context as RunLogContext
    participant Operation as Handler or provider/tool operation
    participant Logger as PSR logger
    participant Processor as LogContextProcessor
    participant Sink as Rotating JSONL handler

    Controller->>Queue: Message carrying run and operation identity
    Queue->>Worker: Deliver in a different process
    Worker->>Context: enter run_id, worker, queue, component
    Note over Worker,Context: No ambient PHP context crosses the process boundary
    Worker->>Context: Enter nested handler or operation scope
    Worker->>Operation: Execute
    Operation->>Logger: Event-style message and explicit fields
    Logger->>Processor: LogRecord
    Processor->>Processor: Add missing PID and memory fields
    Processor->>Context: current()
    Context-->>Processor: Merged nested scope
    Processor->>Processor: Fill missing fields<br/>Explicit call-site fields win
    Processor->>Sink: Enriched record
    Operation-->>Worker: Return or throw
    Worker->>Context: Leave scopes during cleanup
```

`RunLogContext` maintains a nested stack per Fiber through a `WeakMap`, with a
separate stack for non-Fiber code. Reusing a worker process does not justify leaving
a previous run's context active.

| Field | Where it comes from |
|---|---|
| `run_id`, `session_id` | Call-site or ambient worker scope; workers set session identity explicitly |
| `component`, `handler`, `worker`, `message_type`, `tool_name` | The operation that enters scope or writes the record |
| `event_type` | Explicit event fields override broader ambient values |
| `pid`, `memory_usage`, `memory_allocated` | Processor defaults when absent |
| `queue` | Scope label; some call sites record a bus name, not a transport name |

Do not equate `queue=agent.execution.bus` with a physical queue or infer process
ownership from that field alone. Correlate worker, PID, run, and message identity.

## Trace logs

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    participant Worker
    participant Tracer as RunTracer
    participant Logger
    participant Operation as LLM, tool, or persistence operation

    Worker->>Tracer: inSpan(name, attributes, operation)
    Tracer->>Tracer: Allocate local span ID and parent span ID
    Tracer->>Logger: agent_loop.trace.start
    Tracer->>Operation: Invoke operation
    alt Operation succeeds
        Operation-->>Tracer: Result
        Tracer->>Tracer: status = ok
    else Operation throws
        Operation-->>Tracer: Exception propagates through finally
        Tracer->>Tracer: status remains error
    end
    Tracer->>Logger: agent_loop.trace.finish, duration_ms, status
```

Span IDs are log-local identifiers (`span-1`, `span-2`) used to correlate start and
finish records, including `parent_span_id` nesting. They are not external trace IDs.
Log-derived metrics filter on `message:agent_loop.trace.finish` and the canonical span
name; see [Datadog setup](../docs/datadog.md). This sequence assumes logging succeeds;
a failed log write can itself interrupt execution.

## Extension-free Datadog inputs

```mermaid
flowchart TB
    PHP[Agent and consumer PHP processes] -->|Application records| Files[(Rotated JSONL logs)]
    Files --> Tail[Datadog Agent file collection]
    LogsConfig[logs_enabled and readable configured paths] --> Tail
    Tail --> Mask[Configured regex masking rules]
    Mask --> Explorer[Datadog Logs]
    Explorer --> LogMetrics[Log-based metrics]
    LogMetrics --> Dashboards[Dashboards and monitors]
    OS[OS process table] --> Check[Datadog Process Check]
    Match[Configured command-line match patterns] --> Check
    Check --> Metrics[system.processes metrics]
    Metrics --> Dashboards
    QA[Castor QA launch environment] --> Off[Disable ddtrace tracing and log injection; isolate transport DSNs]
```

The application never talks to Datadog. It writes JSONL logs; the Agent collects the
files it is configured to read. File collection requires a separately configured
Datadog Agent: installing the application does not ship log files. Process metrics
come from the Agent's Process Check, not from Monolog or `RunTracer`. Log-based
metrics are defined in Datadog and derived from the same collected logs.

APM spans, `trace.*` metrics, profiling, and `dd.trace_id` log fields are
intentionally absent since the ddtrace extension was retired; see
[Datadog setup](../docs/datadog.md).

The supplied Agent configuration uses example checkout paths and collects the main
checkout plus worktrees. Custom log paths need matching collector configuration.
`castor datadog:smoke-log` writes the project's default `.hatfield/logs` path, not
every possible configured sink.

## Do not confuse these artifacts

```mermaid
flowchart TB
    Conversation[Committed conversation events] --> Canonical[(sessions/id/events.jsonl)]
    Diagnostic[Application diagnostic calls] --> Logs[(Rotated agent JSONL logs)]
    Bash[Shell stdout and stderr] --> BG[(Owned bash log sidecars)]
    Cap[Oversized tool text] --> Temp[(Output-cap temporary files)]
    Capture[Explicit raw provider stream capture] --> Sensitive[(Sensitive capture artifact)]
    Canonical --> Replay[Resume and transcript rebuild]
    Logs --> Investigate[Operational investigation]
    BG --> ToolRead[bg_status log or foreground result]
    Temp --> Notice[Inspection path in capped result]
```

## Privacy and failure boundaries

- Call sites must avoid raw prompts, tool output, credentials, and full session data.
  The application processor and handler do not enforce universal payload redaction.
- JSON exception formatting includes stack traces. Exception messages and extension
  log calls can still expose sensitive values.
- Datadog regex masking happens after local file creation. It covers known patterns,
  not arbitrary private data, and cannot sanitize an already-written local file.
- Explicit raw provider capture can contain generated text and tool arguments.
  Treat it as sensitive debugging output rather than ordinary operational logging.
- A committed effect-dispatch failure is logged without rolling back canonical events.
  Tool failure may become a model-visible tool result. These are different from a
  logging sink failure, which can propagate.

## Owning source

- [Monolog wiring](../config/packages/monolog.yaml)
- [Handler](../src/CodingAgent/Logging/HatfieldRotatingLogHandler.php)
- [Processor](../src/CodingAgent/Logging/LogContextProcessor.php)
- [Ambient context](../src/AgentCore/Infrastructure/RunLogContext.php)
- [LoggingConfig](../src/CodingAgent/Config/LoggingConfig.php)
- [RunTracer](../src/AgentCore/Application/Handler/RunTracer.php)
- [Castor launch environment](../.castor/env.php)
- [Datadog log collection](../ops/datadog/hatfield.d/conf.yaml)
- [Datadog process metrics](../ops/datadog/process.d/conf.yaml)
- [Datadog setup procedure](../docs/datadog.md)
