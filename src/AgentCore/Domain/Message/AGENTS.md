# Domain\Message architecture notes

`Domain\Message` contains transport contracts only (immutable bus payloads).

## Role in runtime topology

- Command-bus payloads: `StartRun`, `ApplyCommand`, `ApplyShellCommand`, `AdvanceRun`, `LlmStepResult`, `ToolCallResult`, `CompactionStepResult` (`StartRun`/`ApplyCommand`/`ApplyShellCommand`/results route to `run_control`; `AdvanceRun` is sync)
- Execution-bus payloads: `ExecuteLlmStep`, `ExecuteToolCall`, `ExecuteShellToolCall`, `CollectToolBatch`

For concrete producers/consumers, see `src/Application/AGENTS.md`.

## Contract boundaries

- Messages should remain infrastructure-agnostic value objects.
- Runtime ownership (who dispatches/handles) is documented in Application architecture notes, not in TOON indexes.

## Maintenance rule

When a new message type is added, removed, or re-routed, update this file and `src/Application/AGENTS.md` together.