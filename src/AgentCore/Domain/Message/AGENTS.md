# Domain\Message architecture notes

Transport contracts only — immutable bus payloads under `Ineersa\AgentCore\Domain\Message`.

## Taxonomy (current)

**Command / result payloads** (orchestration; most route to transport `run_control` on `agent.command.bus`):

- `StartRun`, `ApplyCommand`, `ApplyShellCommand`
- `LlmStepResult`, `ToolCallResult`, `CompactionStepResult`
- `CompleteDeferredToolCall` (deferred completion; identity from durable record)
- `InvalidateRunContext` (internal canonical-event side channel; carries only run ID and clears process-local context)

**Run-control transitions** (transport `run_control` on `agent.command.bus`):

- `AdvanceRun`, `CompactRun` — state transitions handled only by the dedicated run_control consumer
- `RefreshRunContext` — rebuilt generated instructions from session attach; refreshes context without advancing execution

**Execution payloads** (`agent.execution.bus` → `llm` / `tool` transports):

- `ExecuteLlmStep`, `ExecuteCompactionStep` → `llm`
- `ExecuteToolCall`, `ExecuteShellToolCall` → `tool`

Producers/consumers and App-layer workers: `../../Application/AGENTS.md`.

## Contract boundaries

- Messages stay infrastructure-agnostic value objects
- Who dispatches/handles is Application / CodingAgent ownership, not TOON indexes
- Do not document removed types (`CollectToolBatch` is not in the tree)

## Immutable child-launch input

`ExecuteToolCall` may carry an optional `ToolLaunchContextDTO` (`launchContext`):

- Attach it only for `fork` and `subagent`. Ordinary tools keep `null`.
- Fork includes producing run/turn/model, agents text, and the owner message snapshot.
- Subagent includes producing run/turn/model and agents text only.
- The DTO is fixed at owner dispatch. HITL `withHumanInputAnswer()` copies it unchanged.
- Tool and durable tool-batch snapshots serialize it with `ToolBatchStateDTO::SNAPSHOT_GROUP`.

## Maintenance

When a message type is added, removed, or re-routed, update this file and `../../Application/AGENTS.md` together.
