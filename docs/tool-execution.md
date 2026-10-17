# Tool Execution

How Hatfield registers, schedules, and executes tools inside CodingAgent. See the
[tool design and execution diagrams](../architecture/tools-and-mcp.md) and the
[user-facing tool catalog](tools.md).

## Registration

Built-in tools implement `HatfieldToolProviderInterface` and are tagged into `ToolRegistry`.
Extensions register via `ExtensionApiInterface::registerTool(ToolRegistrationDTO)`.
MCP servers contribute tools after connection ([mcp.md](mcp.md)).

Each tool has: name, description, JSON Schema parameters, handler, execution mode
(`sequential` / `parallel`), and optional prompt summary/guidelines/timeout metadata.

## Scheduling and transport

`tools.execution.default_mode` and `max_parallelism` configure worker policy.
Per-tool mode is owned by registration (`ToolDefinitionDTO`), not arbitrary settings overrides.
File mutation tools are sequential.

Background acknowledgment (BG-01 C2 preparation only) resolves the original caller
wait with a `running_in_background` body and releases that call's logical Sequential
reservation for later batches. Physical worker occupancy and attached-batch
Sequential/`max_parallelism` limits remain. Native deferred completion stays pending
until a later checkpoint accepts the true result. Producers, Ctrl+B, mailbox delivery,
and capability exposure are not enabled here. Unanswered human waits are refused without
mutation. Acknowledgment preparation compares the deferred envelope with the stored
batch call and uses only durable stored aliases (or `deferredId`). C3 must route native
finals for detached invocations before producers are enabled.

C3 owner acceptance records a deterministic `completion_id` and result fingerprint
through prepared lifecycle projection. Detached results route before ordinary
stale-step rejection and remain admissible during Cancelling or already-terminal
owner states. Settlement records the accepted receipt without reviving the run,
replacing the original acknowledgment, or staging the detached result into a
later foreground batch. Attached deferred completions also settle on owner
acceptance, including preserve-incoming cancellation. When the original call can
no longer be projected (already Cancelled/stale-step, or Cancelling with the call
absent/already true), attached acceptance is evidence-only: it journals the
accepted receipt and lifecycle completion without adding a second original-call
model result or touching an unrelated current batch. Replay and presentation treat
`accepted_completion.detached=true` and `accepted_completion.evidence_only=true`
as settlement evidence only. Deferred `status=completed` means verified owner
acceptance, not successful transport send or caller detachment. After that
verified acceptance, original-batch cleanup uses the accepted receipt's origin
identity. Matching native `CompleteDeferredToolCall` redelivery is a no-op;
conflicting content or error after acceptance fails closed. Mailbox delivery,
wake fencing, producers, and Ctrl+B remain later checkpoints.

C4 retains an immutable versioned `tool_completion` envelope on the accepted
receipt (`message_id = completion_id`). Runtime origin/provenance and the
allocated `accepted_source_event_reference` are owner-authored, not taken from
tool output. The reference is bound from the durable event allocator when the
canonical receipt is sequenced, not guessed from in-memory `lastSeq`. Retained
body promotion and presentation read the nested worker outcome under
`tool_result.result` when the configured serializer emits the MESSAGE shape;
accepted-result fingerprints remain over the full outer `tool_result`. `controller_run_id` is the durable owning root session resolved from
child-artifact links, durable deferred batch parent edges, and session parent
links; nested evidence seals under that root, not a phantom
`sessions/<childRunId>` directory. Cold workers resolve depth-2+ UUID children
through deferred reservations when artifact cache/session-list scans cannot see
them. Envelope array/JSON mapping uses the
configured Symfony Serializer. Small validated bodies stay inline; oversized
bodies stream the already-resident encoded JSON into sealed storage under the
owning root at `runtime/retained-tool-completions/<message_id>.json` with
bytes/hash/media type. Host-authored native handoff generations keep full lists
in an owned streamed manifest when parallel batches exceed the inline attachment
budget. A single native generation under a non-root UUID parent is sealed as a
root-owned copy so authorized root deletion reclaims the promised body without
claiming external UUID artifact paths. Owned output-cap full bodies are promoted
before ordinary cleanup can reclaim temps. Native and output-cap source paths refuse leaf and ancestor symlink
redirection before open or copy. Native inspect stream-hashes generations,
fails closed as soon as actual bytes exceed the sealed length, and returns a
bounded preview only when the body fits the caller budget. The internal reader uses
canonical source locators or a bounded newest-first scan. Missing, corrupt,
unsupported, or foreign references yield typed inspection failures and never
re-execute tools. Mailbox delivery, wake fencing, producers, and Ctrl+B remain
later checkpoints.

There is **no** global `ToolExecutor` timeout that rewrites successful late results.
Bash and deferred subagent supervision own their deadlines. Registration can declare
a tool-specific budget. MCP calls receive the run cancellation token and the shorter
of their server timeout and tool budget. STDIO sends a cancellation notification;
HTTP closes the response body when control returns to the SDK. A still-connected
client is reused after interruption; a disconnected client is evicted so the next
call reconnects. HTTP protocol
versions through `2025-11-25` also receive a best-effort cancellation POST,
which can itself block. Blocking HTTP
requests and body reads delay cancellation and deadline checks, so the MCP budget
is not a hard HTTP wall-clock limit. Neither action guarantees that the server stops its work.
MCP connection timeouts are a separate concern.

Messenger default routing sends `ExecuteToolCall` to the `tool` transport. Overrides:

- `subagent` and `agent_resume` → `agent` transport (`SubagentExecuteToolCallRoutingMiddleware`)
- MCP-backed calls → `mcp` transport (`McpExecuteToolCallRoutingMiddleware`)
- `fork` stays on the default `tool` transport

See [async-runtime-architecture.md](async-runtime-architecture.md).

## Pipeline

1. Model emits tool calls (flat provider arguments: typed built-ins expose DTO fields at the top level; raw dynamic tools keep their flat runtime schema map).
2. Registry/toolbox validates names + arguments (typed built-ins via native Symfony AI resolution + Validator; raw dynamic tools pass through to their handler/server).
3. Tool-call hooks run (allow / block / replace / require approval).
4. Workers execute handlers with cancellation tokens where supported.
5. Tool-result hooks may adjust presentation.
6. Results return to `run_control`, append to the run, and feed the next model step.

Output capping persists oversized text under `tools.output_cap.*` and injects inspection notices ([settings.md](settings.md), [session-storage.md](session-storage.md)). Document-like tools (`hatfield_docs`, handoff-style tools) use the larger doc cap.
`code_mode` success and error results also use that document-report selection for the combined return value and diagnostics text.

## Result reuse versus exclusion

`ToolExecutor` can reuse a stored result for the same run/`tool_call_id` or matching tool idempotency key. That is **dedupe / idempotent result reuse**, not mutual exclusion and not an exactly-once execution guarantee.

At-least-once Messenger delivery can still re-enter a handler. Concurrent duplicate work is constrained by run-control identity checks, tool-batch snapshots, and transport claim behavior — not by “a stored result exists, therefore no other worker can run.” Treat reuse as recovery of an already-recorded outcome.

## Approvals and human input

- Approvals: [approvals.md](approvals.md)
- Questions: [human-input.md](human-input.md)

## Related

- Runtime topology: [async-runtime-architecture.md](async-runtime-architecture.md)
- Agents / fork / resume: [agents.md](agents.md)
- Extension authoring: Extension API docs under `.hatfield/extensions/extension-api/docs/`
