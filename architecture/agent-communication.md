# Agent communication source acceptance

The internal source path accepts short authored text for a current direct parent-child edge. It does not register model-facing tools or deliver text to a recipient. Recipient admission and safe-boundary application must exist before exposing `agent_steer` or `notify_parent`.

## Identity and authorization

`SubmissionFactory::defer()` uses the native tool context and deferred child metadata to resolve the edge. Steering resolves a child handle within its parent registry. Notices derive the parent from the child's current deferred batch. Neither direction accepts a destination supplied in the message text.

The submission identity hashes the source invocation, tool name, resolved parent and child, native batch lifecycle, and exact text. Artifact and run handles resolving to the same child produce the same identity. Rebinding a child for `agent_resume` changes its lifecycle and therefore changes the identity. Backgrounding does not change the identity.

Authorization checks the source's current canonical invocation, operational statuses, parent lifetime, native interruption and completion evidence, and the originating child call's retained ancestry. The disposable history index retains invocation locations after batch settlement so background notices can remain authorized across later parent turns. This lookup grants no execution permission and does not reconstruct a run.

Text must be nonblank, valid UTF-8, and at most 8,192 bytes. The input boundary uses Symfony Serializer and Validator. Text does not change approval, cancellation, deadline, or tool permissions.

## Owner acceptance

The execution worker registers the native deferred correlation before emitting `DeferredToolCompletionRegisteredEvent`. `SubmissionRegisteredSubscriber` submits `AcceptSubmissionDTO` to the source owner through `run_control`.

The owner enters normal transition recovery before handling acceptance. `SubmissionRunHandler` rechecks the source and resolves the current edge again. A changed lifecycle cannot match the frozen submission identity and receives a failed native tool completion instead of a receipt.

Acceptance appends a `tool_execution_update` containing `submission_receipt` to canonical `events.jsonl`. Its post-commit effect completes the original deferred tool call with `source_accepted`. That result promises source persistence only, not receiver delivery or attention. Acceptance does not settle an unrelated foreground child call or change a human wait.

## Recovery and duplicate handling

The existing prepared append owns interrupted acceptance. No separate submission journal, queue, or scheduler exists. `SubmissionReceiptRepository` reads the accepted event through a disposable identity-to-sequence index and a bounded canonical event read. Deleting that index does not delete acceptance evidence; rebuilding it restores the same receipt.

A repeated source request returns the original receipt without another acceptance event or a new authorization decision. This remains true after the source advances or a child resumes. Failed transport sends retain the existing explicit-repair semantics; source acceptance is not a new automatic publication loop.

Recipient delivery can use the accepted receipt as its immutable source. It must revalidate the captured lifecycle at admission and application, deduplicate delivery independently of the pending mailbox, preserve human continuations, and avoid waking cancelled or failed recipients. Those recipient behaviors are not implemented by the source path.
