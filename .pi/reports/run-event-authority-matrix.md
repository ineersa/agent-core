# Run-event authority matrix

**Scope:** current canonical `RunEvent` stream for both parent sessions and child artifact runs.
**Source revision:** `c6d66a77f6556620cb64b18ab48ab222c36fca9b` (2026-08-27).
**Date:** 2026-08-27.
**Privacy:** this report contains type names, field names, aggregate measurements, and source locations only. It intentionally contains no runtime event payload values, prompts, tool output, session/run identifiers, or artifact paths from a real session.

## Scope, methodology, and evidence limits

`RunEventTypeEnum` has **31 cases**. This report accounts for every one exactly once in the matrix below.

Method:

1. Read the event enum, root/domain/application event documentation, the active storage task, the storage audit, and session-storage documentation.
2. Trace current production writers using enum references **and** raw wire-string searches under `src/` and `.hatfield/extensions/`.
3. Trace direct consumers by category: `RunStateReducer`/replay, runtime/transcript, repair, compaction/context/history, and artifact/export/catalog. Indirect consumers which receive reconstructed `RunState` are called out as indirect, rather than misrepresented as event readers.
4. Treat the 2026-08-25 privacy-safe storage audit as measurement evidence, not as dynamic execution-frequency proof.

**Current writes vs legacy reads.** “No current producer found” means no source writer was found at this revision; it does **not** mean old JSONL records cannot exist. Existing `events.jsonl` must remain readable. New-write changes must retain support for historical shapes at `EventPayloadNormalizer` and `RunStateReducer` / replay boundaries, rather than spreading version branches through runtime, export, repair, or TUI consumers.

**Static vs dynamic evidence.** Static references establish a possible producer/consumer relationship, not production frequency, byte volume, extension configuration, or whether a generic reader displays an unknown type. The audit measures only selected aggregate types and fields. One raw writer was found outside enum references: `WorkerFailedEventSubscriber` writes `agent_end` as a wire string (`src/CodingAgent/Runtime/Messenger/WorkerFailedEventSubscriber.php:137`). Source evidence wins where earlier scout statements conflict; in particular, no current `model_changed` producer was found.

## Classification definitions

| Classification | Meaning | Not a removal claim |
|---|---|---|
| **KEEP** | Current event owns a durable fact that current replay, recovery, lifecycle ordering, or a direct product projection cannot safely reconstruct from another current event. | Its payload may still contain redundant fields. |
| **CONSOLIDATE** | The event or part of its payload participates in a current contract, but its fact is duplicated or split. One existing canonical representation must be selected and all consumers migrated before writes can stop. | Does not authorize dropping either representation today. |
| **STOP NEW WRITES** | Current source evidence indicates a new event is redundant for current state/runtime behavior. Continue decoding historical records. | Requires focused proof of ordering, repair, and observability prerequisites listed in the matrix. |
| **LEGACY-ONLY** | No current production writer was found, while decoder/replay/extension compatibility may recognize historical records. | Do not delete enum/reader support merely because new code does not write it. |
| **TRANSIENT-ONLY** | A fact is suitable for active runtime delivery but not durable canonical persistence. Where one enum serves both terminal and nonterminal forms, the row states the split explicitly. | Does not mean terminal snapshots can be removed. |

## Executive conclusions

### Minimal evidence-backed canonical set

The current evidence supports retaining the following durable event *facts* for both parent and child runs: launch identity/context (`run_started`), command application/rejection/queued mailbox history, turn identity (`turn_advanced`), LLM completion/failure/abort, tool scheduled/completed/batch boundary, human-input state, terminal outcome, model notification, compaction request/start/result/failure, and history position/tail-discard records. `agent_end`, `context_compaction_requested`, `context_compacted`, and history records are especially not safely inferable from absence, current state, or a later result.

The same `RunEvent` schema and reducer are used for parent and child run IDs. Child files are routed by store location, not by a child-only event schema. Authority optimization must therefore be uniform across scopes; a child-only meaning branch would create incompatible replay/repair/export behavior.

### High-confidence candidates and limits

* **High-confidence stop-new-write candidate:** `tool_call_result_received`; the reducer no-ops it and the runtime translator drops it, while `tool_execution_end` carries the same identifiers/status.
* **Current no-writer / legacy candidates:** `agent_start`, `turn_start`, `message_update`, `turn_end`, `model_changed`, and `agent_command_superseded`.
* **Nonterminal subagent progress:** `tool_execution_update` is already transient-only for nonterminal snapshots in the current appender; terminal snapshots remain canonical for artifact/recovery behavior.
* **Not safe to remove yet:** `message_start`, `message_end`, `tool_execution_end`, `run_started`, and `llm_step_completed`. The first three participate in tool-result replay/repair; the latter two contain large but currently authoritative launch/assistant data. Their immediate opportunity is ownership/payload consolidation, not event deletion.

### Facts still requiring one authority

Tool completion needs one durable owner for ordered tool identity, result content, raw structured details, error/cancellation state, attachment references, and model notifications. A child launch needs one portable exact owner for its initial model-visible context. A completed LLM step needs its assistant message/tool calls and usage. Current duplicate representations do not make those facts optional.

## Exhaustive current authority matrix

Abbreviations: **RS** = `RunStateReducer` / state replay; **RT/T** = runtime translator or transcript projection; **Rpr** = repair; **Ctx/Hist** = compaction, context budget, or history; **Art/Exp/Cat** = child artifact, export, or catalog. “Both” means the shared schema can be written in either parent or child logs; parent-only describes current product flow, not a decoder restriction.

| Case / wire value | Current production producer(s) | Fact/payload currently owned | Replay and direct consumers | Scope and derivability | Classification and exact prerequisite / risk | Evidence |
|---|---|---|---|---|---|---|
| `AgentStart` / `agent_start` | **None found.** | No current payload contract. | RS: no-op. RT/T: no mapping. Generic historical readers may retain/display it. | Both legacy logs; any “start” meaning is not an established replacement contract. | **LEGACY-ONLY.** Preserve decode compatibility; do not infer no historical consumer from no writer. | `RunEventTypeEnum.php:16`; `RunStateReducer.php:87-94` |
| `TurnStart` / `turn_start` | **None found.** | No current payload contract. | RS: no-op. File-rewind extension recognizes legacy lifecycle boundaries. | Both legacy logs; current turn fact is `turn_advanced`. | **LEGACY-ONLY.** Preserve old boundary handling; do not replace `turn_advanced` with this obsolete marker. | `RunEventTypeEnum.php:17`; `RunStateReducer.php:88-94`; `.hatfield/extensions/file-rewind/src/FileRewindAfterTurnCommitHook.php:40-52` |
| `MessageStart` / `message_start` | `ToolCallResultHandler::handle` and `::appendToolMessageEvents`; `SessionRepairService` synthetic/missing-message paths. | Tool-message frame: role and tool-call identity; no message body. | RS: no-op. RT/T: no direct translation. Rpr writes/observes framing around repaired tool messages. | Both; derivable from an eventual canonical tool-message representation. | **CONSOLIDATE.** Stop only after one tool-result representation lets replay/repair reconstruct message ordering and extension framing is proven insensitive. | `ToolCallResultHandler.php:290-295,572-577`; `SessionRepairService.php:541-548,633-640`; `RunStateReducer.php:82` |
| `MessageUpdate` / `message_update` | **None found** by enum and wire-string source searches. | No current durable payload contract; stream deltas use the runtime protocol instead. | RS: no-op. RT/T: no mapping found. | Both legacy logs. | **LEGACY-ONLY** (and any future progress should be **TRANSIENT-ONLY**). Keep legacy decode; do not persist a new progress format under this case without a new requirement. | `RunEventTypeEnum.php:19`; `RunStateReducer.php:89-94` |
| `MessageEnd` / `message_end` | `ToolCallResultHandler::handle` and `::appendToolMessageEvents`; `SessionRepairService` repairs/synthetic cancellation. | Full serialized tool `AgentMessage`, currently including content and details. | RS appends tool message; Rpr detects/reconstructs missing messages; export/extensions may read history. RT drops it. | Both; model-visible tool message is currently not fully derivable from text-only `tool_execution_end.result`. | **CONSOLIDATE.** First make one existing event own complete result/details/attachments/notifications and prove RS, hot prompt, repair, export, and legacy replay equivalence. | `ToolCallResultHandler.php:298-303,579-585`; `RunStateReducer.php:459-475`; `SessionRepairService.php:541-550,633-640` |
| `ToolExecutionStart` / `tool_execution_start` | `LlmStepResultHandler` per LLM tool call; `ExecuteShellToolCallWorker` for direct shell. | Scheduled/started tool identity, name, order, mode; direct shell can include arguments. | RS marks pending; RT/T starts tool projection; Rpr finds open calls; Ctx detects active tool; Art/Exp summarizes. | Both; LLM tool arguments may derive from assistant tool calls, but standalone shell identity/arguments cannot be assumed elsewhere. | **KEEP.** Any consolidation must preserve standalone shell and pending-tool replay before removing fields. | `LlmStepResultHandler.php:411-419`; `ExecuteShellToolCallWorker.php:91-99`; `RunStateReducer.php:414-422`; `RuntimeEventTranslator.php:281-303` |
| `ToolExecutionUpdate` / `tool_execution_update` | `SubagentProgressEventAppender::append`. Nonterminal snapshots go to transient sink; terminal snapshots use committed append. | Progress snapshot correlated to parent tool call. | RS: no-op. RT/T maps progress; Art summarizes metadata. | Parent-side child supervision today; historical parent logs contain old persisted nonterminal records; measured child bug count is zero. | **KEEP terminal snapshots / TRANSIENT-ONLY nonterminal snapshots.** Do not delete the case: terminal artifact recovery remains a current contract. Retain legacy reads. | `SubagentProgressEventAppender.php:35-79`; `RuntimeEventTranslator.php:305-332`; audit `session-storage-file-io-audit.md` “Event-log composition” |
| `ToolExecutionEnd` / `tool_execution_end` | `ToolCallResultHandler` ordinary/cancel paths; `ExecuteShellToolCallWorker`; `SessionRepairService` synthetic cancellation. | Completion identity/order/error and display result; currently only text plus selected extras, not the full structured result. | RS resolves pending/shell state; RT/T finalizes tool display; Rpr reads latest end/rebuilds messages; Art/Exp/deferred child projectors summarize. | Both; completion/status is not safely inferred from a missing pending entry. | **KEEP; payload CONSOLIDATE.** It is the best candidate sole tool-result owner, but must first carry structured details, attachment refs, notification semantics, cancellation/error fields, and portable repair data exactly once. | `ToolCallResultHandler.php:255-261,544-552`; `ExecuteShellToolCallWorker.php:134-145`; `RunStateReducer.php:438-452`; `SessionRepairService.php:566-620` |
| `TurnEnd` / `turn_end` | **None found.** | No active payload contract. | RS: no-op; file-rewind recognizes it as a historical stable boundary. | Both legacy logs. | **LEGACY-ONLY.** Keep reader compatibility; current completion boundaries are other event types. | `RunEventTypeEnum.php:24`; `RunStateReducer.php:91-94`; `.hatfield/extensions/file-rewind/src/FileRewindAfterTurnCommitHook.php:40-52` |
| `AgentEnd` / `agent_end` | `AdvanceRunHandler`, `ApplyCommandHandler`, `LlmStepResultHandler`, `ToolCallResultHandler`, compaction result handlers, direct-shell worker, `SessionRepairService`; raw writer `WorkerFailedEventSubscriber`. | Terminal reason and optional safe error/cancellation context. | RS sets terminal state and clears active work; RT/T maps terminal state; Rpr prevents duplicate terminalization; Ctx/Hist cleanup and boundary hooks; Art/Exp/deferred child status. | Both; terminal reason/order is not inferable from no pending work. | **KEEP.** Preserve ordering and reason/error semantics; removing risks incorrect resume status, duplicate repair, and cancellation races. Normalize raw writer if enum enforcement is tightened. | `AdvanceRunHandler.php:88-100`; `LlmStepResultHandler.php:126-145,367-380`; `CompactionStepResultHandler.php:150-158`; `RunStateReducer.php:86`; `WorkerFailedEventSubscriber.php:137` |
| `RunStarted` / `run_started` | `StartRunHandler::handle` only; child launch builds the same `StartRun` input. | Exact normalized launch payload: initial messages, metadata/model/reasoning, system/launch context, child parent/artifact metadata. | RS seeds messages/model; RT/T projects start/user messages; Ctx reads model/context; Hist gets initial prompt; Art/Exp/Cat/subagent metadata recover launch facts. | Both; `state.json` is derived; later events cannot reconstruct exact launch context. | **KEEP; payload CONSOLIDATE only after independent launch-context design.** Preserve exact portable start/resume/replay semantics and localized legacy nested-payload decode. | `StartRunHandler.php:44-78,112-130`; `RunStateReducer.php:116-166`; `SessionCatalogRecoveryService.php:215-250`; `SubagentRunMetadataReader.php` |
| `ModelChanged` / `model_changed` | **None found** in current production source. | Historical model transition (`model`, optional prior model). | RS changes model; RT/T maps model change; catalog recovery uses latest historical model. | Mainly parent legacy; child model is launch-pinned today. | **LEGACY-ONLY.** Do not remove reader support or derive historical model from current settings. | `RunEventTypeEnum.php:28`; `RunStateReducer.php:168-181`; `SessionCatalogRecoveryService.php:228-238` |
| `TurnAdvanced` / `turn_advanced` | `AdvanceRunHandler`; `ApplyShellCommandHandler` for standalone shell turn. | Turn/step and exact operation/advance identity. | RS sets turn/active operation; RT/T starts turn; Rpr detects open operation; Ctx/Hist anchors and filters; deferred child projector status. | Both; not derivable from LLM completion because shell, retry, compaction and idempotency boundaries exist. | **KEEP.** Preserve identity fields and ordering; they guard redelivery and history selection. | `AdvanceRunHandler.php:378-399`; `ApplyShellCommandHandler.php:127-135`; `RunStateReducer.php:185-208`; `HistoryProjector.php:74-96` |
| `LlmStepCompleted` / `llm_step_completed` | `LlmStepResultHandler::handle`. | Assistant message/tool calls, stop reason, actual model/reasoning, usage, plus duplicate presentation/diagnostic fields. | RS appends assistant/creates pending calls; hot prompt indirectly uses RS; RT/T, Rpr, Ctx usage, Hist, Art/Exp/deferred child use it. | Both; assistant/tool calls and usage are not reconstructible from transient deltas. `text` and tool-call count are derivable from assistant message. | **KEEP; field CONSOLIDATE.** Retain assistant message/tool calls and usage; prove runtime/export/legacy normalization before stopping duplicate `text` or count writes. | `LlmStepResultHandler.php:314-326,411-419`; `RunStateReducer.php:347-386`; `ProviderContextUsageResolver.php:70-145`; `RuntimeEventTranslator.php:163-230` |
| `LlmStepFailed` / `llm_step_failed` | `LlmStepResultHandler::handle`. | Sanitized failure, retry decision/attempt/exhaustion, actual model/reasoning context. | RS records retryable failure; RT/T shows safe failure; Rpr/Art/deferred child distinguish retryable vs terminal; export. | Both; retry semantics cannot be inferred from `agent_end`. | **KEEP; field CONSOLIDATE.** Preserve sanitized message, retryability, attempt/exhaustion; normalize any duplicate nested/top-level retry representation centrally. | `LlmStepResultHandler.php:224-249`; `RunStateReducer.php:389-409`; `RuntimeEventTranslator.php:234-265` |
| `LlmStepAborted` / `llm_step_aborted` | `LlmStepResultHandler`; `SessionRepairService` synthetic cancellation. | Aborted operation identity, stop/cancel fact, usage, bounded hashed/length summary of partial assistant output. | RS clears current operation without appending partial content; RT/T cancellation; Rpr closes stranded work; Ctx counts usage; export. | Both; not equivalent to terminal cancellation because operation/usage differ. | **KEEP.** Preserve no-partial-message invariant and usage; do not expand diagnostic summary into raw output. | `LlmStepResultHandler.php:116-145`; `SessionRepairService.php:171-178`; `RunStateReducer.php:78`; `ProviderContextUsageResolver.php:101-145` |
| `WaitingHuman` / `waiting_human` | `ToolCallResultHandler` interruption and answer/resume paths. | Complete pending human request and continuation correlation. | RS restores typed pending request/status; RT/T emits request; human answer validation, Rpr/cancel; deferred child/Art status. | Primarily parent interactive flow, but shared decoder. Status alone cannot reconstruct prompt/schema/continuation. | **KEEP.** Preserve request and continuation atomically; extension-facing generic fields are a compatibility surface. | `ToolCallResultHandler.php:330-350,417-429`; `RunStateReducer.php:491-510`; `RuntimeEventTranslator.php:394-422`; `PendingHumanInputRequestDTO.php:65-125` |
| `AgentCommandApplied` / `agent_command_applied` | `ApplyCommandHandler`, `CommandMailboxPolicy`, `ApplyShellCommandHandler`. | Accepted command; for user commands, model-visible message; for shell, raw input/standalone/operation identity; for human input, answer routing. | RS mutates messages/status/shell state; RT/T command events; Rpr/cancel; Ctx guards; Hist reconstructs command/turn relation; export. | Both; operational command store does not retain immutable historical user/shell input after cleanup. | **KEEP.** Any payload consolidation must retain exact user-message/shell/HITL data and ordering. | `ApplyCommandHandler.php:259-330,499-750`; `CommandMailboxPolicy.php:125-153,243-255`; `ApplyShellCommandHandler.php:106-145`; `RunStateReducer.php:213-310` |
| `AgentCommandRejected` / `agent_command_rejected` | `ApplyCommandHandler`; `CommandMailboxPolicy`. | Rejected command kind, reason, identity. | RS records command rejection effects; RT/T status; command mailbox operational handling; export/history diagnostics. | Both; current mailbox state cannot reconstruct a historical rejection after it is resolved. | **KEEP.** Preserve reason and ordering; distinguish rejected from never-applied command. | `ApplyCommandHandler.php:214-220,269-276`; `CommandMailboxPolicy.php:208-214`; `RunStateReducer.php:155-158` |
| `AgentCommandQueued` / `agent_command_queued` | `ApplyCommandHandler` enqueue paths. | Durable mailbox enqueue order, command identity/options and pending message/text. | RS: no direct mutation; RT/T pending UI; Ctx/Hist boundary/filter; mailbox policy drains later. | Both; current command store may represent pending work, but not historical order or consumed/rejected command history. | **KEEP (candidate payload CONSOLIDATE).** Do not stop writes until command-store durability/retention and pending UI/history reconstruction are proven. | `ApplyCommandHandler.php:159-169,455-461,933-939`; `HistoryReplayFilter.php:224-225`; `RuntimeEventTranslator.php:507-535` |
| `AgentCommandSuperseded` / `agent_command_superseded` | **None found.** | No current payload authority. | RS: no-op; RT/T drops it. | Both legacy logs. | **LEGACY-ONLY.** Keep decoder support; no current source proves a replacement for historical audit meaning. | `RunEventTypeEnum.php:38`; `RunStateReducer.php:92-94`; `RuntimeEventTranslator.php:75` |
| `StaleResultIgnored` / `stale_result_ignored` | `ToolCallResultHandler` cancellation/unaccepted-result path. | Diagnostic record that a result was intentionally ignored. | RS: no-op; RT/T maps status update; metrics/diagnostics may observe committed event. | Both; current state outcome is derivable, but precise ignored-result audit fact is not. | **CONSOLIDATE.** A future stop-new-write decision needs explicit observability/metrics replacement and a product decision on user-visible status; do not silently remove it. | `ToolCallResultHandler.php:91-97`; `RunStateReducer.php:92-94`; `RuntimeEventTranslator.php:66` |
| `ToolCallResultReceived` / `tool_call_result_received` | `ToolCallResultHandler`; `SessionRepairService` synthetic cancellation. | Redundant receipt marker: tool ID/order/error. | RS: no-op; RT/T drops; Rpr uses tool end/message/state rather than this marker. | Both; fields duplicate `tool_execution_end`. | **STOP NEW WRITES; LEGACY read support.** Prove result ordering/idempotency and repair behavior without the receipt marker before removing producers; retain normalizer/replay acceptance for old streams. | `ToolCallResultHandler.php:247-254,536-543`; `SessionRepairService.php:610-617`; `RunStateReducer.php:81`; `RuntimeEventTranslator.php:72` |
| `ToolBatchCommitted` / `tool_batch_committed` | `ToolCallResultHandler`; `SessionRepairService` terminal repair. | Ordered completion boundary for a tool batch. | RS clears pending tools; Ctx compaction boundary; Rpr; tool-batch snapshot cleanup. RT/T drops it. | Both; individual ends could theoretically be counted, but explicit boundary is a current replay/control fact. | **KEEP.** Removing requires coordinated reducer, compaction, cleanup, repair, and deferred-child proofs; fields are small. | `ToolCallResultHandler.php:190-198,316-326`; `SessionRepairService.php:207-216`; `RunStateReducer.php:84`; `ToolBatchSnapshotCleanupHookSubscriber.php:41-106` |
| `ModelNotification` / `model_notification` | `ModelNotificationCodec::toEventSpecs`, called from LLM and tool-result handlers. | User/system-visible notification payload with identity/order/metadata. | RT/T passes through; transcript projection and export render it; output-cap hooks recognize source semantics. | Both; LLM-origin notifications have no other canonical source. Tool-origin list can duplicate tool details. | **KEEP; source-field CONSOLIDATE.** Preserve notification order/IDs across success/failure/abort/tool paths; decide one owner for duplicated tool-detail list only after projection equivalence. | `ModelNotificationCodec.php:44-72`; `ToolCallResultHandler.php:306-314`; `RuntimeEventTranslator.php:379-391`; `ModelNotificationProjectionSubscriber.php:15-44` |
| `ContextCompactionRequested` / `context_compaction_requested` | `AdvanceRunHandler` pre-LLM guard. | A consumed advance requested compaction: request/advance identities, turn, trigger. | RS records request advance key; advance duplicate guard; Rpr/compaction lifecycle context. RT/T no direct mapping. | Both; effect can be lost after commit, so state/effect absence cannot prove request consumption. | **KEEP.** Removing can replay an already-consumed advance and drain later mailbox work incorrectly. | `AdvanceRunHandler.php:332-347`; `RunStateReducer.php:95,104-108`; `Domain/Event/AGENTS.md:17-21` |
| `ContextCompactionStarted` / `context_compaction_started` | `CompactRunHandler`, normal and replacement-summary paths. | In-flight compaction operation, status and prepared worker/replacement context. | RS restores Compacting/current operation; Rpr redrives durable request; Ctx usage/auto guard; RT/T status. | Both; later success/failure cannot reconstruct a crash-mid-compaction operation. | **KEEP; field trimming only after repair/provider-input proof.** Do not drop prepared request/identity while repair supports current compactions. | `CompactRunHandler.php:314-347,383-403`; `RunStateReducer.php:553-587`; `SessionRepairService.php`; `ProviderContextUsageResolver.php` |
| `ContextCompacted` / `context_compacted` | `CompactionStepResultHandler`; `CompactRunHandler` replacement path. | Exact post-compaction message checkpoint and retained-history/usage transition. | RS replaces messages; RT/T compaction projection; Ctx; resume/export. | Both; re-running a compactor is not deterministic nor guaranteed available. | **KEEP.** This is a canonical checkpoint, not telemetry; payload reference/consolidation would require atomic portable publication, which is not proposed here. | `CompactionStepResultHandler.php:365-379`; `CompactRunHandler.php:421-430`; `RunStateReducer.php:589-644` |
| `ContextCompactionFailed` / `context_compaction_failed` | `CompactRunHandler` structural failures; `CompactionStepResultHandler` result/worker failures. | Safe failure reason, operation identity, and pre-/post-start distinction. | RS resolves compaction state differently by failure shape; Ctx usage/guard; Rpr; RT/T status. | Both; absence of success cannot distinguish structural, worker, cancellation, or stale-result failure. | **KEEP.** Do not collapse structural and post-start forms; preserve exact failure lifecycle. | `CompactRunHandler.php:165-226`; `CompactionStepResultHandler.php:135-203,308-365`; `RunStateReducer.php:645-700` |
| `HistoryPositionSet` / `history_position_set` | `HistorySelectionService`; `AdvanceRunHandler`; `ApplyShellCommandHandler`. | Selected retained tip, prior tip, and reason. | Hist projector/filter/replay selection; RT intentionally drops canonical event in favor of separate selection UI event; export. | Both; not derivable from `RunState.turnNo`, which lacks full selection history/reason. | **KEEP.** Preserve order/reason relative to turn/command boundaries. | `HistorySelectionService.php:82-95`; `AdvanceRunHandler.php:389-398`; `ApplyShellCommandHandler.php:137-145`; `HistoryProjector.php:99-108` |
| `HistoryTailDiscarded` / `history_tail_discarded` | `HistoryTailDiscardService`. | Logical discard boundary and reason; discarded bytes remain append-only audit history. | Hist projector/filter/replay; RT drops it. | Both; current tip cannot distinguish discarded forward history from merely inactive history. | **KEEP.** Required for deterministic append-only history semantics; physical pruning is a separate retention design. | `HistoryTailDiscardService.php:89-97`; `HistoryProjector.php:111-125`; `HistoryReplayFilter.php:253-254` |

### Classification count (31 rows)

| Classification bucket | Rows |
|---|---:|
| KEEP (including field-level consolidation notes) | 20 |
| CONSOLIDATE | 3 |
| STOP NEW WRITES, with legacy reader | 1 |
| LEGACY-ONLY | 6 |
| Split: KEEP terminal / TRANSIENT-ONLY nonterminal | 1 |
| **Total** | **31** |

## Cross-event ownership analysis

### Tool-result sequence and duplication

Current accepted tool completion has the following relevant durable sequence (notification and batch/human branches vary):

```text
ToolExecutionStart
  -> ToolCallResultReceived
  -> ToolExecutionEnd
  -> MessageStart
  -> MessageEnd
  -> ModelNotification*
  -> ToolBatchCommitted / WaitingHuman / AgentEnd
```

`ToolCallResultHandler` creates `tool_execution_end.payload.result` from extracted text and separately normalizes a complete tool `AgentMessage` for `message_end`. The latter includes message content and full result details. The 2026-08-25 privacy-safe audit measured, across 11,117 child tool results:

| Retained representation | Aggregate encoded nested bytes |
|---|---:|
| `tool_execution_end.payload.result` | 44,573,403 |
| `message_end.payload.message.content` | 44,916,502 |
| `message_end.payload.message.details` | 96,413,545 |

The event types themselves are not interchangeable today:

* `ToolExecutionEnd` resolves pending tool/shell state and feeds runtime, repair, artifact, and deferred-child outcome logic.
* `MessageEnd` is the current sole replay input for the model-visible tool message, including structured details.
* `MessageStart` is framing only and the reducer no-ops it.
* `ToolCallResultReceived` is a receipt marker only; reducer and runtime translator both no-op/drop it.

**Required order before stopping writes:**

1. Select an existing canonical event representation for the *complete* tool result, not merely its display text.
2. Make reducer replay construct the same `AgentMessage` (including attachment references and notifications) from that authority.
3. Migrate repair, export, artifact/deferred-child projection, runtime/transcript projection, compaction input, and hot prompt behavior to the same authority.
4. Add localized legacy replay for historical full `message_end` and historical text-only `tool_execution_end` shapes.
5. Prove equivalence for normal, error, cancellation, deferred, attachment, and notification paths.
6. Only then stop new `MessageStart`, `MessageEnd`, and `ToolCallResultReceived` writes. The report does **not** assert that all three can be stopped in one unverified change.

### `RunStarted` and `LlmStepCompleted`: payload slimming is not event removal

`run_started` is the sole current launch record and seeds exact initial messages/model metadata. Child `run_started` records measured 43,141,809 encoded bytes; initial messages account for 39,947,463 bytes (92.6%), while system-prompt and metadata portions are much smaller. That proves a field-level ownership problem worth analysis, not that a launch event is redundant. Removing or moving it without an exact portable replacement would break first replay, catalog recovery, child metadata discovery, and resume.

`llm_step_completed` owns the assistant message/tool calls and usage. The audit measured 25,664,707 child bytes for the type, but current consumers require the assistant message for replay and tool identities and usage for context accounting. Likely field candidates (`text`, `tool_calls_count`) are derivable from the assistant message; their removal requires field-level consumer mapping and legacy normalization. No source evidence supports deleting the event.

### Parent and child schema uniformity

`ChildAwareEventStore` routes child run IDs to nested child files, but it does not define a distinct event vocabulary. Child-specific consumers (`SubagentRunMetadataReader`, deferred child projector, artifact retrieval, child snapshots) rely on the same event meanings. Any event/payload authority change must preserve parent sessions, child runs, forked runs, deferred children, and direct-shell lifecycles under one compatibility approach.

## Ranked recommendations (no implementation proposed)

1. **First candidate — `tool_call_result_received`:** validate ordering/idempotency/repair without it, then stop new writes and retain legacy decode.
2. **Keep nonterminal progress transient:** audit whether any nonterminal `tool_execution_update` can still reach canonical append paths; terminal snapshots remain a keep.
3. **Consolidate tool authority before deleting tool-message events:** start from `ToolCallResultHandler`, then prove reducer/hot prompt/repair/projection equivalence. Do not replace one duplicate with an unowned sidecar, cache, setting, or generic repository.
4. **Field-level audit of `llm_step_completed`:** prove consumers of `text`, count, available-tools metadata, and duplicate retry fields before deleting any field. Retain assistant message/tool calls and usage absent contrary evidence.
5. **Independent launch-context analysis for `run_started`:** preserve exact portable child launch/resume data; do not optimize small system prompt metadata while leaving measured initial-message retention unexplained.
6. **Retain definite control/checkpoint records:** `agent_end`, `turn_advanced`, `tool_execution_start/end/batch`, `waiting_human`, all compaction, and both history records have state/order/recovery facts not safely inferred from state or absence.
7. **Handle no-writer events as legacy readers:** stop treating `agent_start`, `turn_start`, `message_update`, `turn_end`, `model_changed`, and `agent_command_superseded` as active write design targets. Keep their parser/replay tolerance until persisted-session support is explicitly retired.
8. **Do not remove `stale_result_ignored` yet:** it is state-redundant but may be observability/UI-significant. Decide required audit/status semantics before converting it to a metric or stopping writes.

## Measurement gaps

The existing audit provides the following relevant measurements without exposing payload values:

| Measured scope/type | Records or bytes | What it establishes |
|---|---:|---|
| Parent event logs | 27,291 records; 102.7 MiB | Parent aggregate baseline |
| Child event logs | 80,450 records; 267.1 MiB | Child aggregate baseline |
| `message_end` | 12,790 records; 169,719,375 bytes total; 148,795,187 child bytes | Largest measured canonical type |
| `tool_execution_end` | 12,790 records; 55,847,066 bytes total; 48,497,156 child bytes | Tool completion payload is large |
| `run_started` | 234 records; 43,359,918 bytes total; 43,141,809 child bytes | Child launch payload dominates this type |
| `llm_step_completed` | 7,143 records; 30,579,599 bytes total; 25,664,707 child bytes | Assistant result payload is large |
| `tool_execution_update` | 13,489 records; 61,664,966 bytes, parent-only in snapshot | Historical persisted nonterminal-progress issue is parent-heavy |

Missing evidence must not be invented:

* Per-type parent/child counts and byte attribution for the remaining 26 enum cases.
* Current-schema versus historical-schema proportions and dynamic producer frequency.
* Nested field attribution for all retained event types, especially compaction, commands, notification, and LLM fields.
* Decode peak memory and time for state replay, hot prompt, repair, export, transcript, and child resume.
* Counterfactual bytes after each proposed authority choice.
* Frequency and product value of `stale_result_ignored`, legacy no-writer records, terminal progress snapshots, and generic extension consumers.
* Physical I/O/read amplification versus decoded event sizes.

## Legacy migration/read strategy

Keep compatibility local:

1. `EventPayloadNormalizer` remains the serialized-envelope compatibility seam (schema/version and payload-envelope acceptance).
2. `RunStateReducer` remains the semantic replay seam: accept historical `message_end`, `tool_execution_end`, launch, compaction, and no-op lifecycle shapes and normalize them into the current in-memory state.
3. Repair/replay may use a small normalized internal representation after those seams, but runtime translator, export, history, TUI, and extensions should not branch on historical event versions.
4. New write removal is additive rollout behavior: old streams continue to decode; no migration, pruning, or deletion of existing JSONL is authorized by this report.
5. Corrupt or malformed new authoritative data must retain current fail-loud behavior. Compatibility is for recognized historical shapes, not silent loss of an unrecognized required fact.

## Validation recipe for report completeness

The following non-committed command compares enum-case names to the main-matrix row headings. It deliberately checks only the report/enum structure and reads no session data:

```bash
python3 - <<'PY'
import re
from pathlib import Path

enum = Path('src/AgentCore/Domain/Event/RunEventTypeEnum.php').read_text()
report = Path('.pi/reports/run-event-authority-matrix.md').read_text()
cases = re.findall(r'^\s*case\s+(\w+)\s*=\s*\'[^\']+\';', enum, re.M)
rows = re.findall(r'^\| `([A-Za-z]+)` / `[^`]+` \|', report, re.M)
missing = sorted(set(cases) - set(rows))
extra = sorted(set(rows) - set(cases))
duplicated = sorted(name for name in set(rows) if rows.count(name) != 1)
print(f'enum={len(cases)} rows={len(rows)} missing={missing} extra={extra} duplicated={duplicated}')
raise SystemExit(bool(missing or extra or duplicated or len(cases) != 31))
PY
```
