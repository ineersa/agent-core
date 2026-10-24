# Retained code-mode execution

## Ownership

`CodeModeExecutionService` registers a native owner for a validated top-level
`code_mode` call. The deferred correlation and native row share one metadata
transaction. Shared `DeferredToolInvocationFence` compares the full
`ExecuteToolCall` envelope; code-mode entry reuses that fence.

`CodeModeHostBridge` owns the PHP subprocess, socket and synchronous nested
stack. Nested tools keep separate call identities and return real values to the
same script. Nested deferred, human-input and child operations stay unsupported.
Caller acknowledgement does not free the occupied worker or explicit locks.

## Acknowledgement and terminal delivery

Ctrl+B sends `background_code_mode` with the immutable identity and tool-call ID.
`CodeModeAcknowledgementHandler` revalidates readiness and shared invocation
ownership. `/bg` lists root-owned and owned-child hosts and confirms
`stop_code_mode` for one invocation without cancelling the parent or another host.

Shared deferred acknowledgement settles the batch slot with
`tool_call_acknowledged`, not `tool_execution_end`. Runtime projection freezes the
original card as backgrounded. Late results arrive as `model.notification`.

The worker retains one processed terminal in `SessionToolPayloadStore`, freezes
first acceptance on the shared deferred record, and dispatches
`CompleteDeferredToolCall`. Waiting callers use ordinary result admission;
acknowledged work uses background-completion mailbox admission. Failed sends and
parked controller-loss recoveries keep the first accepted reference for explicit
`/repair`. Repair reuses that retained result and never reruns execution or
processors.

## Interruption and recovery

Host readiness gates acknowledgement, not terminal failure. Cancellation and
history discard capture exact native identities through essential after-turn
metadata. Captured fences survive parent revival. Controller loss parks delivery
with shared `continuation_required` until explicit repair; cold start and
shutdown create no automatic model successor.

A fenced host cannot acknowledge. Abandoned cancelled or history-discarded hosts
retire without fabricating terminal evidence. Permanent session deletion removes
native rows and retained artifacts under the shared storage-before-SQLite order.

## Evidence

Native raw values and oversized diagnostics live under
`runtime/code-mode-results`. Disposable OutputCap cleanup cannot delete those
bytes. Successful and failed results keep the same diagnostic paths through
repair.

## Worker capacity

`ControllerReplayBackgroundCodeModeTest` proves actual controller, broker and
two-consumer behavior: independent retained hosts, free-worker progress while one
host is blocked, pool saturation, and queued admission after one host releases.
