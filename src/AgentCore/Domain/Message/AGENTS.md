# Domain\Message architecture notes

Transport contracts only — immutable bus payloads under `Ineersa\AgentCore\Domain\Message`.

## Taxonomy (current)

**Command / result payloads** (orchestration; most route to transport `run_control` on `agent.command.bus`):

- `StartRun`, `ApplyCommand`, `ApplyShellCommand`
- `LlmStepResult`, `ToolCallResult`, `CompactionStepResult`
- `CompleteDeferredToolCall` (deferred completion; identity from durable record)

**Sync (intentionally not transport-routed):**

- `AdvanceRun`, `CompactRun` — handled on the bus(es) without Messenger transport (see `config/packages/messenger.yaml` comments)

**Execution payloads** (`agent.execution.bus` → `llm` / `tool` transports):

- `ExecuteLlmStep`, `ExecuteCompactionStep` → `llm`
- `ExecuteToolCall`, `ExecuteShellToolCall` → `tool`

Producers/consumers and App-layer workers: `../../Application/AGENTS.md`.

## Contract boundaries

- Messages stay infrastructure-agnostic value objects
- Who dispatches/handles is Application / CodingAgent ownership, not TOON indexes
- Do not document removed types (`CollectToolBatch` is not in the tree)

## Maintenance

When a message type is added, removed, or re-routed, update this file and `../../Application/AGENTS.md` together.
