# Application

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
| `RefreshRunContext` | Owner-local `RunMessageProcessor` call from attach, no bus route | `RefreshRunContextHandler` replaces generated messages and commits `context_refreshed` without advancing a turn |

## Async workers (`agent.execution.bus`)

| Message | Transport (messenger.yaml) | Worker |
|---|---|---|
| `ExecuteLlmStep` | `llm` | `ExecuteLlmStepWorker` |
| `ExecuteToolCall` | `tool` (subagent/MCP middleware may re-stamp) | `ExecuteToolCallWorker` |
| `ExecuteShellToolCall` | `tool` | `Ineersa\CodingAgent\Runtime\Controller\CommandHandler\ExecuteShellToolCallWorker` |
| `ExecuteCompactionStep` | `llm` | `ExecuteCompactionStepWorker` |

Requests carry their immutable inputs through the ordinary execution bus. Subagent and MCP routing inspect `ExecuteToolCall` directly. Child launch bodies remain separate immutable files referenced by the request.

Workers post results (`LlmStepResult`, `ToolCallResult`, `CompactionStepResult`) back onto `agent.command.bus` → `run_control`. Provider/request/stream retries for one LLM invocation are owned by `LlmRequestRetryExecutor` inside `LlmPlatformAdapter` (settings: `ai.http.max_retries`, default five). After that budget is exhausted the result is terminal (`retryable: false`). A failed result send is logged as intentional degradation and requires explicit repair. No generic worker claims or sealed result store remain. Failed `run_control` deliveries reset the default Doctrine connection and manager after Messenger decides retry eligibility but before permanent-failure terminalization, so redelivery and `agent_end` persistence never reuse failed transaction state. `WorkerFailedEventSubscriber` handles permanent owner failures under `RunLockManager` and commits the terminal transition through `RunCommit`, including normal sequence/state publication and post-commit cleanup hooks. A failed load, lock, append, or publication is logged without an alternate canonical writer.

## Dispatch ownership (producers)

- `StartRun` — `AgentRunner::start()`
- `ApplyCommand` — `AgentRunner` steer/followUp/cancel/answerHuman via `applyCoreCommand()`
- `ApplyShellCommand` — `AgentRunner::shell()`, controller shell path, in-process shell send
- `AdvanceRun` — post-commit kickoffs (`StartRunHandler`, apply/LLM/shell follow-up actions), stale-run resume command
- `AdvanceRun` / `CompactRun` — state-transition effects through `RunMessageProcessor` / `RunCommit` → `agent.command.bus` → `run_control`
- `ExecuteLlmStep` / `ExecuteToolCall` / `ExecuteCompactionStep` — external-I/O effects through `RunMessageProcessor` / `RunCommit` → `agent.execution.bus`
- `CompactRun` — auto-compaction hooks, manual `/compact`, pre-LLM compaction guard / overflow recovery paths

`AdvanceRunHandler` evaluates automatic compaction before the next LLM request, including after a completed tool batch. Unresolved tools and human-input waits stop advancement before this boundary. Parent input usage at or above the independent compaction threshold holds the request with `continueAfterCompaction=true`; result handling resumes it on compacted history. Children never compact. The after-turn hook retains maintenance scheduling and does not race post-tool advancement.

Continuation compaction revalidates cancellation when the owner consumes `CompactRun`. Hook-provided `context_compacted` events persist continuation intent so cold replay matches the live lifecycle. Explicit terminal-session maintenance remains available without continuation.

## Subagent progress ownership

`SubagentProgressEventAppender` submits `CommitSubagentProgress` for canonical progress. `RunOrchestrator` routes consumption through the locked `RunMessageProcessor` and App handler. The handler validates durable lifecycle, parent invocation, and revision identities before returning a `tool_execution_update` transition. `RunCommit` publishes the new owner sequence without invalidation or parent replay.

Controller nonterminal progress remains transient at sequence zero. Terminal snapshots and in-process progress remain canonical. Queued commands do not advance `deliveredProgressRevision` or the existing `interruptionProgressEnqueuedAt` marker. Owner coordination handlers advance those existing consumption markers using a freshly read projection version and schedule lifecycle delivery. For a current destination, this happens after canonical commit. When a newer parent turn or resolved call supersedes the destination, consumption retires the outstanding progress obligation without appending or publishing progress. A retired normal destination consumes the current aggregate revision; a retired forced destination consumes its interruption obligation. These markers therefore mean consumed or discarded, not proof of canonical append. Natural and forced-interruption completion wait for consumption, including explicit discard. Duplicate or stale commands do not append again.

Valid launch reservations always contain child rows: `DeferredSubagentBatchLaunchService` rejects empty task lists, and `DeferredSubagentBatchRepository::reserveBatch()` inserts the batch and planned children in one transaction. Missing child rows are an invariant failure, not a reason to wait for an unsubmitted command. Parallel timeout completion requires no forced snapshot and never waits for the interruption-progress marker.

The pending transition captures lifecycle consumption and delivery actions. Recovery repeats their idempotent coordination before removing the intent. Transport delivery can still repeat; canonical progress and its stable consumption obligation are not regenerated.

There is **no** `CollectToolBatch` message type in `src/` (stale historical name — do not reintroduce docs for it).

## Direct shell lifecycle ownership

`ApplyShellCommandHandler` commits `agent_command_applied` plus canonical
`tool_execution_start` (with flat bash `arguments.command`) under the owner lock,
then returns the `ExecuteShellToolCall` effect. Direct-shell redelivery checks
canonical `agent_command_applied` identities even after execution ends. This is
separate from the pending-only `ApplyCommand` mailbox. The shell worker has no
EventStore dependency: it only executes bash and posts `ToolCallResult`.
`tool_execution_start` is lifecycle acceptance before external work, not measured
subprocess start; duration remains on the later result metadata.

## File-backed child launch input

`LlmStepResultHandler` writes child input from the owner's current messages through `ToolLaunchInputStoreInterface`, one message at a time. It attaches only `ToolLaunchInputReferenceDTO` to fork/subagent `ExecuteToolCall` effects. Ordinary tools keep `launchContext=null`.

- The private immutable file lives beside tool batches in `runtime/tool-launch-inputs`, using the same parent/child path resolver. It is not an output-cap or temporary-cleanup file.
- The reference fixes producing run/turn/step/call/model, kind, SHA-256, and byte length. Neither Messenger nor SQL batch rows contain the body.
- Fork files contain the producing messages and agents text. Subagent files contain agents text only.
- `ExecuteToolCallWorker` checks durable deferred registration before reading input. Pending execution redelivery re-emits registration; completed execution redelivery is a no-op. Unregistered work validates and resolves input before external execution. Missing, corrupt, unreadable, or mismatched input posts an error `ToolCallResult` without archive replay.
- Worker compaction and child reservation remain outside the owner lock.
- Shared deferred completion retains input until deferred registration exists, even when child projections are terminal. Once registered, cleanup is best effort after artifact outcomes are available; a content-free warning records deletion failure without blocking completion dispatch. Single, parallel, and interrupted handoffs rebuild from child products.
- Canonical tool-result cleanup also deletes failed synchronous launch input. Terminal parent cleanup removes remaining files, including files published before an unsuccessful transition. Pending work and approval waits retain their input. This does not add a crash-recovery journal or exactly-once guarantee.

## Events and commit

Tool-result text must be valid UTF-8 throughout content, metadata, structured keys, and nested return values. `ToolResultText` enforces this independently of storage format. Malformed output becomes a failed, non-retryable tool result with the fixed message `Tool call failed: its result contained malformed UTF-8.` The unchecked payload is discarded, not replaced or retained in `raw_result`.
An inspection-depth refusal also becomes a non-retryable failed result, with a separate safe-inspection message. It does not throw from finalization or claim that valid text is malformed.

`ToolResultText::MAX_DATA_DEPTH` and the standalone `CodeModeValueCodec::MAX_DATA_DEPTH` are both 64. Each checked value starts at depth zero; array keys and values, including string leaves, increment depth. A visit beyond 64 is refused before inspecting its type. This is a representation budget, not a serializer setting. Enclosing fragments and frames are also checked, so wrapping a previously accepted value can cause an earlier refusal. It does not promise 64 levels inside `raw_result` regardless of surrounding fields.

The conservative limit reserves headroom for tool-result data inside event and IPC envelopes. These fixed wrappers add only a few levels to a checked value, far below the existing 512-level encoding limit. Storage remains strict; its depth is not raised.

Terminal data consists only of arrays, UTF-8 strings and keys, finite numbers, booleans, and null. Opaque objects and resources are rejected without inspecting properties or calling serialization hooks. The executor converts supported top-level enums and Stringable values once and retains that concrete value. CodeModeTool converts its host diagnostics wrapper to plain data at its own boundary. Typed deferred and human-input outcomes remain separate worker coordination values.

Processor exceptions become remembered, non-retryable failures without rerunning processors or tool execution. Owner admission checks the actual normalized representation before collection and model projection. Code-mode IPC uses strict framing, validates nested requests before execution, and sends safe error diagnostics without substitution. Live process-log reads withhold legal incomplete trailing characters; final reads reject them. Both read a fixed byte window and verify left-boundary alignment.

Success and error results run the shared validated processor path once, before post-execution cancellation arbitration. This lets extension processors promote cancellation and timeout flags before arbitration. Processing or representation failure preserves its failed result even if cancellation races. Typed human-input and deferred coordination, pre-start cancellation, and dedupe reuse do not rerun terminal processors.

`ToolExecutor` finalizes before processors can hide invalid values and before remembering the result. Success and exception-derived results share processors and finalization. Typed human-input and deferred markers remain coordination values. `ToolCallResultFactory` and direct shell dispatch finalize envelopes; owner admission rechecks PHP-deserialized envelopes before collection and projection. Persistence and model delivery use the same finalized result. Process-log files and exit status are unchanged; malformed log views fail rather than return repaired output. Snapshot serialization stays strict.

- `RunCommit::commit()` appends canonical `RunEvent` through `PreparedTransitionEventStoreInterface::appendTransition()` with captured work, then persists the narrow projection and active context before effect dispatch via `StepDispatcher` and after-turn hooks via `HookDispatcher`
- History selection, tail discard, and repair pass `dispatchAfterTurnHooks: false`. They retain commit publication without scheduling after-turn work ahead of pending user commands. Normal terminal worker-failure commits retain hooks.
- `StartRunHandler` re-arms the initial `AdvanceRun` post-commit action when Messenger redelivers after `run_started` already committed but before any AdvanceRun token was applied (`lastAppliedAdvanceKey` / `currentOperation` still null)
- `StartRunHandler` no-ops when status is already `Cancelled`/`Cancelling` and `model` is still null, so reserved child run ids cancelled before `StartRun` cannot revive
- `ToolCallResultFactory::fromExecuteToolCallAndToolResult()` maps envelope `error` only for cancelled tool results (`details.cancelled`); other tool errors keep `error: null` and rely on `isError` / `details`
- Extension lifecycle hooks use `HookSubscriberInterface` / after-turn context from committed events, aggregated in registration order by `HookDispatcher`

## Linear history / replay contracts

Ordered retained-history projection lives in **CodingAgent** (`CodingAgent\Session\History`). AgentCore emits canonical history events (`turn_advanced`, `history_position_set`, `history_tail_discarded`) and depends on:

- `RunStateRebuilderInterface` → App `SessionRunStateReplayService` (filter retained history before reducing `RunState`)
- `HistorySelectionServiceInterface` / `HistoryTailDiscardInterface` → App history services; `HistoryTailDiscardInterface` is the mutate-behind-tip choke point used by `RunMessageProcessor`

`HistoryTailDiscardService` prepares an unsequenced discard event. `RunMessageProcessor` commits it separately before the normal handler, including no-op handlers, then clears the reasoning baseline after publication. Preparation cannot append or change metadata.

History selection validates its explicit rebuild before committing `history_position_set`. Repair retains preview/refusal/redrive rules and validates hypothetical repair before committing proposed events. Owner maintenance replies use existing `run.history_position_changed` and correlated `session.repair.completed` runtime events. In-process repair reads the narrow `RepairResult` from Messenger's `HandledStamp`.

Historical presentation preserves execution replay's accumulator behavior: terminal events do not clear its by-reference pending calls or staged completed results. A later batch commit can include results staged before termination. Do not change only presentation counts to hide this inherited behavior.

See `docs/session-storage.md` (linear history model).

## Observability (wiring only)

`RunOrchestrator` / `RunMessageProcessor` / `RunCommit` emit `RunTracer` spans; execution workers emit `llm.call` / `tool.call`. Details in those classes — do not duplicate tracing catalogs here.

## Maintenance

When routing, handlers, projector flow, or subscriber contracts change, update this file in the same change.

## Explicit owner registry initialization

`OwnerRunInitializationMiddleware` runs only on received `run_control` envelopes, including synchronous transport consumption. It validates actual envelope identity against durable parent sessions or child reservations, then explicitly creates a reserved-new state or loads canonical recovery. Registry lookup is memory-only. Publication failure releases local state and requires recovery at the next owner entry.

Only `StartRun`, first-shell `ApplyShellCommand`, and cancellation of a reserved child before start can create new state. Creation rejects prior operational sequence/status or non-pending child artifact evidence when canonical history is missing. It preserves child parent/owner identity. Ordinary follow-up and attach require canonical recovery, not queued-on-miss. Maintenance may return its existing empty-history refusal without admitting state. Recovery rejects absent products, zero-sequence products, and mismatched run identities.

Child launch enqueue leaves its artifact Pending. The owner promotes it to Running only after canonical StartRun acceptance. Launch redelivery uses the existing deterministic StartRun identity; Pending does not authorize ordinary commands to recreate missing history.

First-shell controller entry reserves a real parent session before submission when the supplied identity is an opaque process label. It reports the resulting numeric identity in the existing `run.started` event. Registered children and numeric parent identities are not replaced.

Repair checks canonical sequence integrity before owner hydration. Integrity refusals do not admit registry state. Maintenance handlers perform required recovery inside their response boundary, so recovery failures still emit sanitized runtime replies. The middleware holds the owner lock throughout maintenance consumption.

Repair derives execution state and validates proposed messages through the retained-history filter. Sequence integrity and append watermarks still use the whole canonical archive. Automatic compaction skips Failed state while other after-turn subscribers retain failure cleanup.

## Post-commit coordination

`HandlerResult::postCommitActions` contains data descriptors, not callables. `RunCommit` journals them, including event-free coordination. Their unrouted handlers execute synchronously on the existing command bus. Mailbox decisions finalize before effects or continuation actions. A failure retains the pending plan for owner-entry recovery.

Core actions capture prepared `AdvanceRun` or `CompactRun`, mailbox decisions, and batch registration. `AdvanceRunCoordinationFactory` fixes the step ID and idempotency key before commit. Descriptors carry messages or scalar identities, never services or `RunState`.

Configured persistent stores implement `PreparedTransitionEventStoreInterface`. `RunCommit` stages exact event bytes and serialized captured work before physical append. The shared finalizer applies batch and pending-mailbox changes in one short metadata transaction, then publishes the canonical cut. Payload serialization finishes before that transaction. Normal sends run after owner-lock release through the existing buses. `StepDispatcher` logs failed sends and emits `EffectDispatchFailedEvent` with accepted and failed counts. CodingAgent renders it through the existing transient notification flow. Repair reports requests, not queue acceptance or execution completion. A failed send requires explicit `/repair`, not persistent republication.

Pending persistent transitions block owner admission and mutation until their exact staged bytes and captured coordination are reconciled. Supported local actions complete through the shared finalizer without regenerating identifiers. Unsupported actions fail closed. Unfinished canonical transitions remain eligible for startup and idle reconciliation.

Explicit repair derives current model and shell work from replay and compaction input from canonical start evidence. Ordinary tools use the batch's stored calls or collected results. Queued calls remain behind collector capacity and sequential barriers. Human waits and launched deferred children are not blindly relaunched. Repair preserves original invocation identity where available and warns that external effects may already have occurred. Critical tools provide their own external idempotency contracts.

Mailbox preparation does not enqueue, consume, or reject commands. Prepared events capture exact status decisions, and enqueue descriptors retain the original command payload and idempotency key. A safe-boundary drain captures the existing FIFO cutoff; recovery does not read later commands into that plan. Cancel preserves pending append messages and journals rejection of stale follow-up and steer commands.

`ToolBatchCollector` prepares registration, collection, suspension, answers, and redrive on a private clone. `FinalizeToolBatchDTO` captures original results or answers plus queue and membership decisions. One active SQL batch owns calls, collected sibling results, FIFO queue, in-flight membership, and human waits. Approval revisions retain their exact current call identities. Finalized cleanup retains unresolved human and deferred work.

`RunMessageProcessor` checks pending `ApplyCommand` IDs before history discard or handler preparation. The pending mailbox stores no completed identities or source-acceptance markers. Application or rejection deletes the whole command row. Submitting a completed ID again is allowed.

Attach completes cancellation and context refresh before follow-up handling. Maintenance commands use no separate accepted-source identity history. Selection and repair still commit through the owner and shared finalizer.

Repair prepares a typed decision before mutation and submits it once through `RunCommit`. Refused decisions execute no captured child actions. Source identities are not stored in the pending-only mailbox.

Parent repair also captures deferred child-maintenance obligations through application-owned `RepairDeferredChildrenDTO` before root coordination. Recovery completes that captured plan without reevaluating current pending children. Captured child-generation fences keep recovery from changing newer work. Completed maintenance IDs are not retained; resubmission can prepare a new plan for the current generation.

`EssentialAfterTurnHookInterface` prepares automatic compaction, child observation, parent cancellation, and context-budget commands before intent publication. The journal stores these descriptors separately from continuation actions. Required local coordination finishes before canonical publication. Captured transport messages send afterward, outside the owner lock. Recovery repeats the captured descriptors without reevaluating policy or generating identifiers.

`CanonicalSequenceBoundActionInterface` binds observation event ordinals to allocated staged sequences before the journal publishes its manifest. `VerifiedTransitionDTO` exposes that sequence list. Child observation carries the canonical predecessor, so allocation holes do not look like missing committed events. An observation whose predecessor is ahead of the durable child cursor still requests recovery.

Extension observers and transient snapshot cleanup run after required coordination and transition finalization. Observer failures remain best effort, and recovery does not replay arbitrary extension callbacks. This checkpoint does not claim power-loss durability.
