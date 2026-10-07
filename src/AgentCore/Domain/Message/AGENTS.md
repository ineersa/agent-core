# Messages

Immutable bus payloads and owner-local processor messages under `Ineersa\AgentCore\Domain\Message`.

## Taxonomy (current)

**Command / result payloads** (orchestration; most route to transport `run_control` on `agent.command.bus`):

- `StartRun`, `ApplyCommand`, `ApplyShellCommand`
- `LlmStepResult`, `ToolCallResult`, `CompactionStepResult`
- `DurableExecutionResult` carries the sealed result reference for owner consumption
- `ExecutionOutcomeUnknown` reports a confirmed-dead claim without a recoverable result, not a synthetic execution result
- `ToolExecutionOutcomeUnknown` reports the same condition for a batch-backed ordinary tool. Its scalar receipt identifies the invocation and claim independently of the current mutable call.
- `CompleteDeferredToolCall` (deferred completion; identity from durable record)
- `CommitSubagentProgress` (frozen normalized progress, durable lifecycle and revision identity; App handler commits under the parent owner lock)

**Run-control transitions** (transport `run_control` on `agent.command.bus`):

- `AdvanceRun`, `CompactRun` — state transitions handled only by the dedicated run_control consumer

**Owner-local processor messages** (no Messenger route):

- `RefreshRunContext` — attach passes rebuilt generated instructions directly to `RunMessageProcessor`; refreshes context without advancing execution

**Execution payloads** (`agent.execution.bus` → `llm` / `tool` transports):

- `ExecutionRequest` references a sealed LLM, compaction, or standalone-shell invocation. Application middleware selects its configured transport from the request type. Authorization stamps survive native PHP transport serialization.
- `ExecuteToolCall` → `tool`, retaining the ordinary tool-batch authority

`ExecuteLlmStep`, `ExecuteCompactionStep`, and `ExecuteShellToolCall` are frozen local inputs stored in immutable files. They are not Messenger deliveries. The gate atomically claims the reference before resolving the input and invoking its normal worker handler. Duplicate Running deliveries do not resolve the file or execute again. ResultReady deliveries notify the owner with the original durable result reference.

Producers/consumers and App-layer workers: `../../Application/AGENTS.md`.

## Contract boundaries

- Messages stay infrastructure-agnostic value objects
- Who dispatches/handles is Application / CodingAgent ownership, not TOON indexes
- Do not document removed types (`CollectToolBatch` is not in the tree)

## Immutable child-launch input

`ExecuteToolCall` may carry an optional `ToolLaunchInputReferenceDTO` in `launchContext`:

- Only fork and subagent calls carry a reference. Ordinary tools keep `null`.
- The reference contains producing run/turn/step/call/model, kind, checksum, and byte length. It contains no path or conversation body.
- Owner publication seals the separate file before dispatch. HITL `withHumanInputAnswer()` preserves the reference.
- Messenger and durable tool-batch snapshots serialize only the reference. `ToolLaunchContextDTO` is resolved input used locally by the execution worker.

## Maintenance

When a message type is added, removed, or re-routed, update this file and `../../Application/AGENTS.md` together.
