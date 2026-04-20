# Events and Traces

## Events Map

The system uses Event Sourcing for the core domain. Events are the source of truth for the `RunState`.

### Ordered Lifecycle (`CoreLifecycleEventType`)

The system guarantees the following event emission order per run/turn:
1. `agent_start`: The run is initialized.
2. `turn_start`: A new conversational turn begins.
3. `message_start`: The LLM starts streaming a message.
4. `message_update`: Streaming chunks are received (optional).
5. `message_end`: The LLM message is complete.
6. `tool_execution_start`: A tool call is initiated.
7. `tool_execution_update`: Mid-execution status (optional).
8. `tool_execution_end`: Tool execution finishes.
9. `turn_end`: The conversational turn finishes (yielding to the user or wrapping up).
10. `agent_end`: The run is completed or cancelled.

**Projection**: Events are committed to Doctrine, then projected via `OutboxProjector` to JSONL files (Flysystem) and real-time SSE (Mercure).

---

## Traces and Spans

The Application layer utilizes `RunTracer` to emit structured latency and observability spans.

### Using `RunTracer`

The tracer wraps operations and logs `agent_loop.trace.start` and `agent_loop.trace.finish` with duration metrics.

```php
$tracer->inSpan('turn.process', ['run_id' => $runId], function() {
    // ... work
});
```

### Core Spans
- **`command.*`**: Tracks the latency of top-level command processing in the Orchestrator.
- **`turn.*`**: Tracks the duration of a single agent turn.
- **`persistence.commit`**: Tracks the database commit phase.
- **`llm.call`**: Emitted by `ExecuteLlmStepWorker` to track LLM response latency.
- **`tool.call`**: Emitted by `ExecuteToolCallWorker` to track tool execution time.
