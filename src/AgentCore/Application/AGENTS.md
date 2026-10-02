# Application architecture notes

Topology map for AgentCore application handlers. Authoritative routing: `config/packages/messenger.yaml`. Domain message list: `../Domain/Message/AGENTS.md`.

## Command → orchestrator entry

`RunOrchestrator` (`Pipeline/RunOrchestrator.php`) is the bus facade; `RunMessageProcessor` owns the per-run lock, bounded state-transition validation, and dispatches tagged `RunMessageHandler` implementations.

| Message | Bus registration | Downstream handler |
|---|---|---|
| `StartRun` | `agent.command.bus` | `StartRunHandler` |
| `ApplyCommand` | `agent.command.bus` | `ApplyCommandHandler` |
| `ApplyShellCommand` | `agent.command.bus` | `ApplyShellCommandHandler` → effect `ExecuteShellToolCall` |
| `AdvanceRun` | `agent.command.bus` (transport `run_control`) | `AdvanceRunHandler` |
| `LlmStepResult` | `agent.command.bus` (transport `run_control`) | `LlmStepResultHandler` |
| `ToolCallResult` | `agent.command.bus` (transport `run_control`) | `ToolCallResultHandler` |
| `CompactRun` | `agent.command.bus` (transport `run_control`) | `Ineersa\CodingAgent\Application\Pipeline\CompactRunHandler` (App layer; depends on compaction services) |
| `CompactionStepResult` | `agent.command.bus` (transport `run_control`) | `Ineersa\CodingAgent\Application\Pipeline\CompactionStepResultHandler` |
| `CompleteDeferredToolCall` | `agent.command.bus` (transport `run_control`) | `CompleteDeferredToolCallHandler` |
| `CommitSubagentProgress` | `agent.command.bus` (transport `run_control`) | `Ineersa\CodingAgent\Application\Pipeline\CommitSubagentProgressHandler` |
| `InvalidateRunContext` | `agent.command.bus` (transport `run_control`) | `RunOrchestrator::onInvalidateRunContext()` clears active context only |
| `RefreshRunContext` | `agent.command.bus` (transport `run_control`) | `RefreshRunContextHandler` replaces generated messages and commits `context_refreshed` without advancing a turn |

## Async workers (`agent.execution.bus`)

| Message | Transport (messenger.yaml) | Worker |
|---|---|---|
| `ExecuteLlmStep` | `llm` | `ExecuteLlmStepWorker` |
| `ExecuteToolCall` | `tool` (subagent/MCP middleware may re-stamp) | `ExecuteToolCallWorker` |
| `ExecuteShellToolCall` | `tool` | `Ineersa\CodingAgent\Runtime\Controller\CommandHandler\ExecuteShellToolCallWorker` |
| `ExecuteCompactionStep` | `llm` | `ExecuteCompactionStepWorker` |

Workers post results (`LlmStepResult`, `ToolCallResult`, `CompactionStepResult`) back onto `agent.command.bus` → `run_control`. Provider/request/stream retries for one LLM invocation are owned by `LlmRequestRetryExecutor` inside `LlmPlatformAdapter` (settings: `ai.http.max_retries`, default five). After that budget is exhausted the result is terminal (`retryable: false`). `LlmWorkerFailedEventSubscriber` still posts one sanitized terminal `LlmStepResult` after final `ExecuteLlmStep` delivery failure and rethrows so the original envelope remains recoverable. Failed `run_control` deliveries reset the default Doctrine connection and manager after Messenger decides retry eligibility but before permanent-failure terminalization, so redelivery and `agent_end` persistence never reuse failed transaction state. `WorkerFailedEventSubscriber` handles permanent owner failures under `RunLockManager` and commits the terminal transition through `RunCommit`, including normal sequence/state publication, collector release, and post-commit cleanup hooks. A failed load, lock, append, or publication is logged without an alternate canonical writer.

## Dispatch ownership (producers)

- `StartRun` — `AgentRunner::start()`
- `ApplyCommand` — `AgentRunner` steer/followUp/cancel/answerHuman via `applyCoreCommand()`
- `ApplyShellCommand` — `AgentRunner::shell()`, controller shell path, in-process shell send
- `AdvanceRun` — post-commit kickoffs (`StartRunHandler`, apply/LLM/shell follow-up callbacks), stale-run resume command
- `AdvanceRun` / `CompactRun` — state-transition effects through `RunMessageProcessor` / `RunCommit` → `agent.command.bus` → `run_control`
- `ExecuteLlmStep` / `ExecuteToolCall` / `ExecuteCompactionStep` — external-I/O effects through `RunMessageProcessor` / `RunCommit` → `agent.execution.bus`
- `CompactRun` — auto-compaction hooks, manual `/compact`, pre-LLM compaction guard / overflow recovery paths
- `InvalidateRunContext` — history selection and explicit repair after persistence; it only clears the receiving run_control process-local context

## Subagent progress ownership

`SubagentProgressEventAppender` submits `CommitSubagentProgress` for canonical progress. `RunOrchestrator` routes consumption through the locked `RunMessageProcessor` and App handler. The handler validates durable lifecycle, parent invocation, and revision identities before returning a `tool_execution_update` transition. `RunCommit` publishes the new owner sequence without invalidation or parent replay.

Controller nonterminal progress remains transient at sequence zero. Terminal snapshots and in-process progress remain canonical. Queued commands do not advance `deliveredProgressRevision` or the existing `interruptionProgressEnqueuedAt` marker. Owner callbacks advance those existing consumption markers using a freshly read projection version and schedule lifecycle delivery. For a current destination, this happens after canonical commit. When a newer parent turn or resolved call supersedes the destination, consumption retires the outstanding progress obligation without appending or publishing progress. A retired normal destination consumes the current aggregate revision; a retired forced destination consumes its interruption obligation. These markers therefore mean consumed or discarded, not proof of canonical append. Natural and forced-interruption completion wait for consumption, including explicit discard. Duplicate or stale commands do not append again.

Valid launch reservations always contain child rows: `DeferredSubagentBatchLaunchService` rejects empty task lists, and `DeferredSubagentBatchRepository::reserveBatch()` inserts the batch and planned children in one transaction. Missing child rows are an invariant failure, not a reason to wait for an unsubmitted command. Parallel timeout completion requires no forced snapshot and never waits for the interruption-progress marker.

This ordering does not provide atomic recovery across canonical append and lifecycle-marker persistence. The later transition crash protocol remains separate work.

There is **no** `CollectToolBatch` message type in `src/` (stale historical name — do not reintroduce docs for it).

## Direct shell lifecycle ownership

`ApplyShellCommandHandler` commits `agent_command_applied` plus canonical
`tool_execution_start` (with flat bash `arguments.command`) under the owner lock,
then returns the `ExecuteShellToolCall` effect. Idempotent command redelivery
still short-circuits before those events or the effect. The shell worker has no
EventStore dependency: it only executes bash and posts `ToolCallResult`.
`tool_execution_start` is lifecycle acceptance before external work, not measured
subprocess start; duration remains on the later result metadata.

## File-backed child launch input

`LlmStepResultHandler` writes child input from the owner's current messages through `ToolLaunchInputStoreInterface`, one message at a time. It attaches only `ToolLaunchInputReferenceDTO` to fork/subagent `ExecuteToolCall` effects. Ordinary tools keep `launchContext=null`.

- The private immutable file lives beside tool batches in `runtime/tool-launch-inputs`, using the same parent/child path resolver. It is not an output-cap or temporary-cleanup file.
- The reference fixes producing run/turn/step/call/model, kind, SHA-256, and byte length. Neither Messenger nor mutable batch snapshots contain the body.
- Fork files contain the producing messages and agents text. Subagent files contain agents text only.
- `ExecuteToolCallWorker` checks durable deferred registration before reading input. Pending execution redelivery re-emits registration; completed execution redelivery is a no-op. Unregistered work validates and resolves input before external execution. Missing, corrupt, unreadable, or mismatched input posts an error `ToolCallResult` without archive replay.
- Worker compaction and child reservation remain outside the owner lock.
- Shared deferred completion retains input until deferred registration exists, even when child projections are terminal. Once registered, cleanup is best effort after artifact outcomes are available; a content-free warning records deletion failure without blocking completion dispatch. Single, parallel, and interrupted handoffs rebuild from child products.
- Canonical tool-result cleanup also deletes failed synchronous launch input. Terminal parent cleanup removes remaining files, including files published before an unsuccessful transition. Pending work and approval waits retain their input. This does not add a crash-recovery journal or exactly-once guarantee.

## Events and commit

- `RunCommit::commit()` appends canonical `RunEvent` via `EventStoreInterface` (`append` / `appendMany`), then persists the narrow projection and active context before effect dispatch via `StepDispatcher` and after-turn hooks via `HookDispatcher`
- `RunCommit` releases collector-owned in-memory batches after persistence and state publication: the exact batch on `tool_batch_committed`, or all run batches on a terminal `agent_end`. Finalized collection alone does not release them. Durable collector reads do not retain deserialized batches; the App cleanup hook deletes snapshot files independently.
- `StartRunHandler` re-arms the initial `AdvanceRun` post-commit callback when Messenger redelivers after `run_started` already committed but before any AdvanceRun token was applied (`lastAppliedAdvanceKey` / `currentOperation` still null)
- `StartRunHandler` no-ops when status is already `Cancelled`/`Cancelling` and `model` is still null, so reserved child run ids cancelled before `StartRun` cannot revive
- `ToolCallResultFactory::fromExecuteToolCallAndToolResult()` maps envelope `error` only for cancelled tool results (`details.cancelled`); other tool errors keep `error: null` and rely on `isError` / `details`
- Extension lifecycle hooks use `HookSubscriberInterface` / after-turn context from committed events, aggregated in registration order by `HookDispatcher`

## Linear history / replay contracts

Ordered retained-history projection lives in **CodingAgent** (`CodingAgent\Session\History`). AgentCore emits canonical history events (`turn_advanced`, `history_position_set`, `history_tail_discarded`) and depends on:

- `RunStateRebuilderInterface` → App `SessionRunStateReplayService` (filter retained history before reducing `RunState`)
- `HistorySelectionServiceInterface` / `HistoryTailDiscardInterface` → App history services; `HistoryTailDiscardInterface` is the mutate-behind-tip choke point used by `RunMessageProcessor`

See `docs/session-storage.md` (linear history model).

## Observability (wiring only)

`RunOrchestrator` / `RunMessageProcessor` / `RunCommit` emit `RunTracer` spans; execution workers emit `llm.call` / `tool.call`. Details in those classes — do not duplicate tracing catalogs here.

## Maintenance

When routing, handlers, projector flow, or subscriber contracts change, update this file in the same change.
