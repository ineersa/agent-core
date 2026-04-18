# Infrastructure\Messenger

## BusNames

Central constants for messenger bus wiring:

| Constant | Bus | Purpose |
|----------|-----|---------|
| `COMMAND_BUS` | `agent.command.bus` | Incoming commands (StartRun, ApplyCommand, etc.) |
| `EXECUTION_BUS` | `agent.execution.bus` | Async execution (LLM steps, tool calls) |
| `PUBLISHER_BUS` | `agent.publisher.bus` | Outbox projection (JSONL, Mercure) |
