# Retained code-mode execution

## Ownership

`CodeModeExecutionService` registers a native owner only inside a validated,
model-emitted top-level `code_mode` handler. It loads the original call and
reference from the existing batch store. A UUID identifies the native owner.
The deferred correlation and native row are inserted in one application database
transaction under the run lock. The original call is sealed outside disposable
batch storage, so later batch cleanup cannot erase its identity.

Ordinary deferred registration retains that same full batch invocation
reference. Shared `DeferredToolInvocationFence` compares the complete
`ExecuteToolCall` envelope, including `parentModel`, `humanInputAnswer`, and
`launchContext`. Code-mode entry reuses that fence before native ownership
begins.

`CodeModeHostBridge` keeps its PHP subprocess, socket and synchronous host stack.
Readiness follows the IPC connection, before the host serves nested requests.
Nested tools retain separate call identities and return their real values to the
same PHP script. Nested deferred operations, human input and child operations
remain unsupported. No nested invocation becomes an independent background target.

The original deadline and memory limit remain unchanged. A blocked nested handler
can exceed its cooperative budget until it returns. Caller acknowledgement does
not free the occupied worker, create another consumer or release explicit locks.

## Caller resolution and terminal delivery

`AgentSessionClient::foregroundCodeMode()` supplies read-only candidates for the
visible run. Both runtime clients use native readiness, unresolved deferred
ownership and the current canonical invocation boundary. Ctrl+B sends
`background_code_mode` with the immutable identity and tool-call ID. The controller
validates that snapshot and dispatches `CodeModeOwnerDTO` to `run_control`.
`CodeModeAcknowledgementHandler` revalidates readiness and shared invocation
ownership under the owner lock. The picker cannot authorize acknowledgement.

`BackgroundWorkSnapshotProvider` includes acknowledged hosts owned by the root
or its child runs. It reads live rows and only the exact terminal row requested
by a pending UI action, rather than loading finished host history. `/bg` confirms
and revalidates a selection before sending `stop_code_mode`. The owner captures
one `InterruptCodeModeDTO` through the existing prepared metadata transaction.
This fences the selected invocation without cancelling its run or another host.
The UI reports `stopping` until native terminal evidence exists or definitive
controller ownership loss retires the abandoned invocation.

Late code-mode result consumption captures the original run and tool-call
provenance. Runtime translation emits `model.notification`, not human activity.
The notice includes the immutable invocation identity and retained artifact paths.
Bash and child completion protocol behavior is unchanged.

Shared deferred acknowledgement validates the retained full invocation, current
batch reference and unresolved caller. `ToolCallResultHandler::acknowledge()`
settles that batch slot with `tool_call_acknowledged`, not `tool_execution_end`.
The shared `acknowledged_at` fact is captured with batch finalization in the same
local metadata transaction. The deferred registration stays `pending` until
terminal settlement or explicit abandonment retirement. Later calls use ordinary
admission on available workers, including another code-mode invocation.

Failed-batch repair preserves an acknowledgement for its exact run, turn and step.
Replay retains that caller result until batch commit, even if a sibling fails
permanently first. Repair supplies only missing sibling results; it does not
replace the acknowledgement or rerun either execution.

The original worker applies terminal processors once. Before subscriber dispatch
or result sending, it retains the processed terminal through the shared
`ToolPayloadStoreInterface`. A short Symfony publication lock protects the first
result-reference anchor, bound to the complete immutable invocation digest.
Repair discovery reads bounded reference metadata, not every pending result body.
Storage is resolved before execution, independently of later owner admission. Pending
execution redelivery reads that evidence before considering execution; completed
deferred redelivery returns before recreating cleaned files. Human-input waits
are not terminal evidence.

The local `ProcessedToolResultDTO` includes both the original execution envelope
and the processed result. CodingAgent validates both against the frozen invocation,
then freezes the first accepted result on the shared deferred record and dispatches
`CompleteDeferredToolCall`. That common path routes a still-waiting caller through
the ordinary result handler, or an acknowledged completion through the existing
background-completion mailbox with paths to retained processed and original
structured results. Child terminal delivery uses the same path. Failed sends and
acknowledged deliveries without durable mailbox admission retain the first
accepted reference for explicit `/repair`. If publication fails before acceptance,
repair finds the processed evidence through the pending registration's immutable
call reference and repeats publication validation, not execution or processors.
This evidence is not a second acceptance authority. The shared deferred record
still owns first acceptance and routing. Repair captures a bounded
`RedriveDeferredToolCall` through the existing owner transition. It reuses the
retained result without repeating execution or terminal processing, even when
the root completed normally. Preview and refused repair do not send or mutate.
Detached producer and receiver admission validate the retained invocation after
the parent turn advances.

Mailbox admission commits the background receipt and deferred source status in
the same metadata transaction. A forwarding obligation alone does not settle the
source. After verified owner finalization, the background-completion facade
deletes the original batch through guarded cleanup. Duplicate admission retries
cleanup without admitting or consuming the notice twice. Rollback retains the
first accepted result and its invocation files.

## Invocation interruption

Host readiness gates caller acknowledgement, not terminal failure. A failure
between native registration and IPC readiness settles through the ordinary
deferred result path instead of leaving the original caller pending.

Cancellation and `history_tail_discarded` capture exact native identities through
the existing essential after-turn metadata transaction. Cancellation fences all
unsettled invocations and unconsumed notices in that run. History discard fences
only invocations from turns after its retained boundary. Selecting an older
history entry alone does not interrupt a host. Captured identities cannot cancel
an invocation registered after the transition was prepared.
The actual discard commit prepares these essential actions before replacement
command handling, even though maintenance disables optional after-turn observers.
Auto-compaction does not schedule work from the discard commit.

The host and nested `ToolContext` share an invocation cancellation token. Its
durable interruption reason remains after the parent returns to `Running` or
starts another turn. Nested tools remain cooperative; a blocking remote handler
is not forcibly cancelled. Controller starting and ordinary shutdown fence the
root and owned child scopes with distinct restart and shutdown reasons, without
deleting retained evidence.

Controller ownership loss freezes a terminal result and the native interruption
in one application metadata transaction. It preserves the first accepted result
or existing processed evidence. Without either, it records a non-retryable
interrupted failure that warns that external effects may already have occurred.
Recovery does not rerun the script or result processors. Shared deferred
`continuation_required` parks delivery until explicit `/repair` apply captures
`RedriveDeferredToolCall`. Preview and refused repair do not release that fence.
Cold attachment and shutdown send no completion or model successor. Delayed old
completion envelopes leave the parked registration intact. Already admitted
notices defer mailbox consumption and block an old successor from starting a
model turn without a newly applied continuation command.

Explicit repair resolves a waiting caller through ordinary result admission and
its prepared transition. An acknowledged caller uses background-completion
admission or its existing mailbox row. The shared repair fence can clear without
clearing the native host's sticky interruption. A foreground send that had reached
the queue but not canonical collection reopens only while its active batch still
retains the exact invocation. The native row does not own acceptance or settlement.

A fenced host cannot become ready or acknowledge its original caller. After
cancellation or history discard, physical completion retires the shared deferred
marker without entering a newer turn. Notification admission, history mutation policy
and mailbox consumption also check the native fence. A late worker cannot replace
the recovery result or retire its pending delivery. Explicit cancellation or
history discard can supersede a controller-loss fence while its recovery notice
remains unconsumed; controller startup cannot weaken that abandonment.

If controller ownership is lost before an abandoned host returns, recovery retires
its deferred marker without creating a terminal result or completion notice. The
cancelled or history-discarded reason remains unchanged. Metadata commits before
guarded batch cleanup; duplicate controller events retry cleanup without reviving
the host. Later repair and late worker output cannot reopen that invocation.

## Evidence and cleanup

Native call and result artifacts use `SessionToolPayloadStore` under the owned
session's `runtime/code-mode-results` directory. They retain the existing strict
representation, identity, byte-length and SHA-256 checks. They are separate from
the disposable batch transport files.

Oversized diagnostic streams use the same bounded OutputCap capture and UTF-8
validation, but persist directly into invocation-owned diagnostic files beside
the native artifacts. Their paths include the raw stream's SHA-256 hash. Controller
cleanup removes disposable OutputCap files without deleting these retained bytes.
Successful and failed results keep the same diagnostic paths through explicit repair.

Before workspace cleanup, the host seals the original structured value and
stdout/stderr diagnostics, or a failed result with its diagnostic and failure
metadata. Failure does not become a successful null result. If artifact
registration fails, the host leaves its workspace and `terminal.json` intact and
returns a non-retryable failure with the evidence path. Normal settlement stops
the subprocess, closes the socket and removes the workspace once.

Permanent session deletion includes native rows and owned code-mode artifact
directories in the existing SQL transaction and post-commit filesystem removal.
Rolling back that transaction preserves the rows and files. Ordinary controller
shutdown and restart retain evidence.

Permanent deletion and payload publication share a short Symfony storage lock for
the owning numeric session. The path resolver retains original ownership through
nested artifact ancestry, including parent-scoped directories outside the root
session tree. Cached payload stores retain paths, not permission to recreate them.
Each publication checks the owning session row while holding that lock. Generic
payloads, processed-result bodies and their reference anchors, and native artifacts
use the same boundary. Deletion holds it through the SQL transaction and filesystem
cleanup. Rollback preserves write authority; committed deletion refuses late writes.
Registration and controller recovery acquire storage admission before opening their
metadata transaction and keep it through commit. Nested correlation and result
publication reuse that admission. Ordinary deferred registration uses the same
order, so deletion cannot hold storage while waiting for their SQLite writer.
The boundary is independent of run-owner admission and never covers a host's
physical execution or reserves worker-pool capacity.

## Worker capacity proof

`ControllerReplayBackgroundCodeModeTest` runs the actual controller, broker and
two tool consumers. FIFO-blocked nested calls retain independent worker, PHP and
socket identities. Ordinary work finishes on the free worker after the first ACK.
After the second ACK, broker reservations prove two occupied workers and one
queued ordinary call. Releasing the first host admits that call while the second
host stays blocked. Each host then delivers its original value and diagnostics
once. This does not claim worker release or durable native execution across
controller failure.
