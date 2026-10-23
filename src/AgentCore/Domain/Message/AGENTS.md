# Messages

Immutable bus payloads and owner-local processor messages under `Ineersa\AgentCore\Domain\Message`.

## Taxonomy (current)

**Command / result payloads** (orchestration; most route to transport `run_control` on `agent.command.bus`):

- `StartRun`, `ApplyCommand`, `ApplyShellCommand`
- `LlmStepResult`, `ToolCallResult`, `CompactionStepResult`
- `CompleteDeferredToolCall` (deferred completion; identity from durable record)
- `RedriveDeferredToolCall` (explicit repair from retained processed or first accepted deferred evidence; bounded run and deferred identities, no execution body)
- `CommitSubagentProgress` (frozen normalized progress, durable lifecycle and revision identity; App handler commits under the parent owner lock)

**Run-control transitions** (transport `run_control` on `agent.command.bus`):

- `AdvanceRun`, `CompactRun` — state transitions handled only by the dedicated run_control consumer

**Owner-local processor messages** (no Messenger route):

- `RefreshRunContext` — attach passes rebuilt generated instructions directly to `RunMessageProcessor`; refreshes context without advancing execution

**Execution payloads** (`agent.execution.bus` → `llm` / `tool` transports):

- `ExecuteLlmStep`, `ExecuteCompactionStep` → `llm`
- `ExecuteToolCall`, `ExecuteShellToolCall` → `tool`

`ExecuteToolCall`, `ExecuteShellToolCall`, `ToolCallResult` and `CompleteDeferredToolCall` are local typed processing values. `ToolPayloadMiddleware` seals their data before sending and transports only `ToolPayloadMessage`, a bounded identity and file reference. It resolves execution data after admission and result data after owner initialization under the run lock. Subagent and MCP routing run before sealing. LLM and compaction message formats are unchanged. Critical tools own their external idempotency contracts; canonical events do not prove whether an external action occurred.

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
