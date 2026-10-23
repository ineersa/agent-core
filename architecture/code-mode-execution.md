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

The run-control owner validates the frozen call, current batch reference and
pending caller before acknowledging it. `tool_call_acknowledged` settles that
batch slot through the normal collector. It does not emit `tool_execution_end`.
Its captured metadata action and batch finalization join the same local
transaction after verified canonical append. Recovery applies those captured
changes rather than recomputing scheduling. Later calls use ordinary admission on
available workers, including another code-mode invocation.

The original worker applies terminal processors once. Its local
`ProcessedToolResultDTO` includes both the original execution envelope and the
processed result. CodingAgent validates both against the frozen invocation before
accepting publication responsibility. The first terminal result reference wins.
A foreground completion uses the ordinary result handler. A detached completion
uses the existing background-completion mailbox and includes paths to retained
processed and original structured results. Repeated owner messages do not produce
another caller result or another background completion.

## Evidence and cleanup

Native call and result artifacts use `SessionToolPayloadStore` under the owned
session's `runtime/code-mode-results` directory. They retain the existing strict
representation, identity, byte-length and SHA-256 checks. They are separate from
the disposable batch transport files.

Before workspace cleanup, the host seals the original structured value and
stdout/stderr diagnostics, or a failed result with its diagnostic and failure
metadata. Failure does not become a successful null result. If artifact
registration fails, the host leaves its workspace and `terminal.json` intact and
returns a non-retryable failure with the evidence path. Normal settlement stops
the subprocess, closes the socket and removes the workspace once.

## Exposure boundary

The retained-host backend has no controller command, TUI candidate or model-facing
backgrounding option. It does not enable code-mode backgrounding for users.
Invocation-sticky cancellation, controller shutdown, restart fencing, history
discard and session-deletion integration must be completed and verified before
capability exposure. Worker retention does not imply durable execution across
controller or worker failure, and recovery must not replay ambiguous side effects.
