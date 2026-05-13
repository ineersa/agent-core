# Events and Traces

## Event model

`RunEvent` is the canonical event envelope for a run.

### Ordered lifecycle (`CoreLifecycleEventType`)

The core order is validated by `CoreLifecycleEventType::validateOrder()`:

1. `agent_start`
2. `turn_start`
3. `message_start`
4. `message_update` (optional)
5. `message_end`
6. `tool_execution_start`
7. `tool_execution_update` (optional)
8. `tool_execution_end`
9. `turn_end`
10. `agent_end`

### Projection and replay sources

Committed events are:

- stored in the canonical event store (`RunEventStore`)
- projected to outbox sinks (JSONL + Mercure)

Read/replay paths use:

- `canonical_events` when event-store data is present
- `jsonl_fallback` when canonical events are unavailable

If sequence gaps are detected during replay, the API emits `resync_required` metadata/event guidance.

---

## Tracing

`RunTracer` emits structured span logs:

- `agent_loop.trace.start`
- `agent_loop.trace.finish`

Core span names include:

- `command.*` (top-level command handling)
- `turn.*` (turn lifecycle)
- `persistence.commit`
- `llm.call`
- `tool.call`
- `replay.rebuild_hot_prompt_state`

Each finish event includes duration and status, enabling latency and failure analysis.
