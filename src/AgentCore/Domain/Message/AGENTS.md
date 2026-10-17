# Messages

Immutable bus payloads and owner-local processor messages under `Ineersa\AgentCore\Domain\Message`.

## Taxonomy (current)

**Command / result payloads** (orchestration; most route to transport `run_control` on `agent.command.bus`):

- `StartRun`, `ApplyCommand`, `ApplyShellCommand`
- `LlmStepResult`, `ToolCallResult`, `CompactionStepResult`
- `CompleteDeferredToolCall` (deferred completion; identity from durable record)
- `ReceiveToolCompletion` (runtime-only committed source reference; retained recipient dedupe and pending mailbox admission, never human input)
- `CommitSubagentProgress` (frozen normalized progress, durable lifecycle and revision identity; App handler commits under the parent owner lock)

**Run-control transitions** (transport `run_control` on `agent.command.bus`):

- `AdvanceRun`, `CompactRun` — state transitions handled only by the dedicated run_control consumer

Runtime completion continuations use `AdvanceRun.completionFence`; bounded
metadata-only progress also sets `completionMailboxDrain`. These tokens never
authorize pre-handler history-tail discard and must revalidate the captured
generations before draining. Ordinary human and foreground successors retain
their existing semantics without these fields.
An already-applied provider-stop continuation also carries `appliedCompletion`
so the owner verifies the finalized recipient before using that intent.

**Owner-local processor messages** (no Messenger route):

- `RefreshRunContext` — attach passes rebuilt generated instructions directly to `RunMessageProcessor`; refreshes context without advancing execution

**Execution payloads** (`agent.execution.bus` → `llm` / `tool` transports):

- `ExecuteLlmStep`, `ExecuteCompactionStep` → `llm`
- `ExecuteToolCall`, `ExecuteShellToolCall` → `tool`

Requests carry immutable invocation inputs and their original identities. Workers post ordinary result DTOs on the command bus. Critical tools own their external idempotency contracts; canonical events do not prove whether an external action occurred.

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
- Messenger and SQL active tool batches serialize only the reference. `ToolLaunchContextDTO` is resolved input used locally by the execution worker.

## Maintenance

When a message type is added, removed, or re-routed, update this file and `../../Application/AGENTS.md` together.
