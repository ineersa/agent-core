# Runtime

- `AgentSessionClient` is the TUI/runtime boundary.
- `Runtime/Contract` and `Runtime/Protocol` define command/event DTOs for session/runtime protocol surfaces.
- Controller owns workers and process lifecycle. `run_control` owns `RunState` and durable transitions. `RuntimeEventTranslator` consumes `RunEvent` (not `RunState`) to build protocol DTOs.
- Live committed events travel on controller stdout. Transient stream deltas use sequence `0` and stay out of durable replay.
- Application LLM retries emit transient `llm.request_retrying` (seq=`0`) for TUI working-status progress. Retry observer wiring is independent of `streamObserverEnabled`; compaction keeps stream deltas suppressed.
- TUI may depend on CodingAgent directly for ordinary service ownership; do not add Runtime/Contract catalog interfaces solely to isolate TUI from CodingAgent modules. Follow `depfile.yaml`.
- CodingAgent must not depend on TUI.
- `Runtime/InProcess` calls AgentCore directly; `Runtime/Process` uses headless JSONL subprocess.
- `src/CodingAgent/CLI/AgentCommand.php` wires TUI via `Ineersa\Tui\Application\InteractiveMode`.
- Canonical source: `.hatfield/sessions/<id>/events.jsonl` via `EventStoreInterface`. Overview: `docs/async-runtime-architecture.md`.

## Application maintenance commands

`AttachRun`, `SelectHistoryPrompt`, and `RepairSession` live in `CodingAgent\Application\Message`. Controllers and the in-process client submit them on `agent.command.bus` to `run_control`. `SessionMaintenanceHandler` owns their application policy and runtime replies. Sharing a transport with framework commands does not make them AgentCore concepts.

Attach completes pending-question cancellation and context refresh through `RunMessageProcessor` before returning, without starting a model turn. History selection and repair use `RunCommit` under the owner lock. The controller neither reconstructs execution state nor writes canonical history.

Subagent progress consumption uses application-owned `ConsumeSubagentProgressDTO` and `SubagentProgressCoordinationHandler`. The handler rereads the current lifecycle projection, advances the existing consumption marker, and dispatches the existing lifecycle delivery message. The descriptor captures its timestamp before commit; it carries no repository, closure, or execution state. Lifecycle redelivery remains a separate supported post-commit action. The pending journal now recovers these application-owned actions before publishing its committed cut. Repeated delivery remains idempotent coordination, not permission to rerun external work.

Prepared parent and child appends use `JsonlAppendJournal` beside their existing canonical archives. Its bounded manifest references exact staged JSONL bytes and a separate serialized work file. Readers stop at the predecessor offset until explicit owner finalization. Recovery compares bounded chunks, preserves allocated sequence holes, and rejects changed predecessor identity, mismatching suffixes, and extra tails. Finalization and append use the existing per-run storage lock.

`StreamingCommittedRuntimeEventStore` publishes only after verified finalization. Normal commits emit the retained hot persisted batch and release it without archive rereads. Cold recovery reconstructs that verified batch through owner-only staged-byte access gated by the verified transition identity, then drops it. Public range readers continue to honor the unpublished cut. Unverified finalize callers are unsupported.

`PendingTransitionRecoverySubscriber` reconciles one owned unfinished canonical transition on each run_control startup or idle tick. Durable child reservations scope the root and nested children. Recovery uses the existing run lock and shared finalizer. It does not scan execution permissions, save worker results, or republish completed transitions.

Ordinary requests and results use the existing execution and command buses. Sends happen after owner-lock release. Failed sends log intentional degradation and require explicit `/repair`; no extra publication buffer retries them. Repair uses canonical state and active batch calls/results, preserving invocation identity where available. Events do not establish whether an external tool already acted. Critical tools own external idempotency.

Pending `ApplyCommand` IDs stop duplicate enqueue before history changes. Completed command rows are deleted and their IDs may be reused. Maintenance commands have no separate source-acceptance history. Essential after-turn coordination remains captured in the pending canonical transition.

Parent repair journals deferred child-maintenance obligations through the same journal. Recovery finishes those captured cancel, interrupt, and settle steps without regenerating child policy. Captured child-generation fences prevent recovery from changing newer children.

Owner entry verifies an unfinished physical suffix and finishes captured coordination before normal replay. Unsupported actions remain hidden and block mutation. Mailbox finalization uses captured identities and FIFO cutoff, not a newly read drain. Active SQL batches retain calls and collected results while human or deferred work remains unresolved. Immutable child launch input remains owned by its existing handoff and cleanup lifecycle. Journal flush checks establish a process-failure boundary, not power-loss durability.
