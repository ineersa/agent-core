---
builtin: true
description: Session identity, storage layout, events, resume, locking, and history operations.
---

# Hatfield Sessions

**One directory = one session = one agent run.** `session_id === run_id`.

## Invariants

- The TUI session and AgentCore run share one identity (DB-issued numeric string).
- Each session row also stores immutable `provider_cache_key` (UUIDv7). ChatGPT maps it to `prompt_cache_key`; generic providers omit Hatfield correlation fields, while Grok maps the session id to its `prompt_cache_key`.
- Resume uses the session directory and application database, including active tool batches and pending commands. There is no global `.hatfield/runs/` registry.
- Canonical conversation source is append-only `events.jsonl`. Transcript projection rebuilds from events on resume.
- There is **no** `metadata.yaml` in the session directory.

## Refreshed instructions

On resume, Hatfield rebuilds its system prompt, project instructions, skills catalog,
and agent definitions. The run-control worker replaces these generated messages
after recovering the session and appends `context_refreshed` to `events.jsonl`.
Conversation messages and compaction summaries are preserved. Refresh does not start
a model turn or change existing child sessions.

Changing instructions or tool definitions can reduce provider prompt-cache reuse.
The effect depends on the provider and the first changed part of the request.
Conversation history and the session's provider cache key are not deleted.

## Directory layout

Base path: `sessions.path` setting (default under project `.hatfield/sessions/`).

```text
.hatfield/sessions/<session_id>/
  events.jsonl          # canonical event log
  sequence.cursor       # event sequence allocation
  artifacts/            # child agent artifacts (when used)
  ...                   # other runtime sidecars as created
```

### Database metadata

Table `hatfield_session` stores id, display name, timestamps, provider cache key, and related session metadata. Directory name is canonical; embedded IDs are validated on read.

The nullable `reasoning_baseline` JSON column stores the provider-qualified model
and fixed effort for Astra reasoning updates, plus the last emitted effort and
history-bound transition markers keyed by surviving request segments. The selected
`reasoning` remains independent. Resume, async compaction success, hook
replacement-summary compaction, and history-tail discard on rewind/edit clear the
baseline so discarded switches are not replayed; the next request establishes it
from the current selection. Model changes also clear it. Worker recreation and
socket reconnection do not clear it.

### Naming

Sessions may be renamed via `/rename`. Display names are metadata only — they do not change `session_id`.

## Events and operational state

- `events.jsonl` is the canonical conversation history used for resume.
- Parent and child event stores stream JSONL line-by-line. They record scalar
  physical-read diagnostics (`archive_bytes_read`, decoded count, early-exit vs
  EOF). `allFor()` still returns the full decoded event list for current callers,
  but it no longer duplicates the whole file text in memory first.
- The runtime rebuilds its working state and disposable database projections from
  canonical events. Those projections do not contain a second copy of prompt history.
- New and resumed runs do not read or write `state.json`. Older event schemas are
  not supported through a compatibility reader.
- `sequence.cursor` allocates event sequence numbers. It is not itself conversation history.
- Transient streamed text is separate from durable events. Resume rebuilds from
  committed history, not from an unfinished stream.

Active `tool_batch_schedule` rows retain calls, collected results, scheduling order,
and human-input waits. `run_command` retains pending command bodies only. Applying
or rejecting a command deletes its whole row, so a completed command ID can be
submitted again. Redelivery after completion can repeat user input.

There is no import of legacy batch files or cached commands. Rebuilding the runtime
database does not recover their pending payloads from conversation events.
Permanent session deletion removes pending command and batch rows for the owner
and all descendants found through artifact registries and deferred reservations.
Ordinary shutdown retains those rows. Startup and idle recovery use the same
ownership traversal to finish interrupted canonical transitions, not to resend
completed transitions.

Provider or model switches convert that canonical history at request time. See
[history-conversion.md](history-conversion.md).

## Child artifacts

Foreground subagent runs store parent-scoped artifacts under the parent session (handoff text, metadata, bounded event/history summaries). Retrieve with `agent_retrieve` (see [agents.md](agents.md)).

Deferred subagent supervision (single and parallel) uses durable batch records and timeouts configured by `agents.subagent_tool_timeout_seconds` (default 24h, minimum 60s). Recovery reads child event logs backward from the durable tail until its stored event sequence cursor, then restores chronological order; it does not treat `sequence.cursor` as event-tail truth because allocation may leave valid sequence holes.

Each child row in `deferred_subagent_child` stores an immutable UUIDv7 `provider_cache_key`, separate from its run ID.
ChatGPT uses that key across turns, worker recreation, and `agent_resume`. Resume rebinds the child to a new batch without changing the key.
The upgrade backfills provider keys without changing operational event cursors, lifecycle state, or per-resume usage counters.

Fork and subagent cards show cumulative cache reuse as `↻ N%` while running and after completion. The child live-view footer shows the same percentage.
The percentage divides the child's accumulated cache-read tokens by its accumulated input tokens, matching the main-session footer.
Reported zero hits appear as `↻ 0%`. Missing cache telemetry or zero input tokens hides the indicator.
Legacy checkpoints without lifetime cache counters keep the indicator hidden, including after resume. Partial post-upgrade usage cannot reconstruct their lifetime percentage.

## Resume and new session

| Flow | Behavior |
|---|---|
| `/resume` | Pick an existing session; rebuild transcript from events; continue with same `session_id` |
| `/new` | Start a new session identity |
| Lazy draft | New interactive session without an initial prompt may delay DB row creation until first message |
| Process restart | Controller/runtime recover from session dir + DB; event projection rebuilds |
| Catalog recovery | On startup after schema migrations, orphan numeric `sessions/<id>/events.jsonl` dirs without a `hatfield_session` row are reinserted into the catalog (same id) |

Resume, relaunch, and reload attach through the controller `resume` command into
`InProcessAgentSessionClient::attach()`. If the rebuilt run is WaitingHuman or still
has pending human-input requests, attach cancels those waits before
`context_refreshed`. The run becomes Cancelled rather than remaining WaitingHuman.
History events are kept; late answers to cancelled question ids do not reopen them.
See [human-input.md](human-input.md).

### Catalog recovery after state DB loss

If `.hatfield/state.sqlite` is deleted or loses `hatfield_session` rows while session directories remain, startup reconciles **canonical** positive-digit directories that contain `events.jsonl`:

- **Canonical IDs only:** directory name must be a positive decimal whose integer round-trip equals the original string (rejects `0`, `007`, non-digits, and integer-overflow aliases).
- **Preserved:** directory name as `session_id` / `run_id`; existing canonical `events.jsonl` bytes are never rewritten or truncated.
- **Recovered into the row when present in events:** initial user prompt (and default display name from it), current model (including later `model_changed`), reasoning from `run_started` metadata, child `parent_run_id` when present.
- **Not event-backed:** a fresh UUIDv7 `provider_cache_key` is generated; renames and other DB-only fields are not restored when absent from events.
- **Not recoverable from session events (SQLite-only):** deferred subagent batches/children, background processes, pending tool questions, messenger queues, and other app-state tables.

New session creation uses atomic exclusive `mkdir` of the leaf session path and fails closed before writing `events.jsonl` when any directory, file, or symlink already occupies that path (including malformed orphans). Concurrent recovery inserts are idempotent via `ON CONFLICT(id) DO NOTHING` on the primary key. Corrupt event logs are skipped with privacy-safe diagnostics; DB/storage infrastructure failures hard-fail startup.

## History (`/history`)

`/history` is **conversation-only** (user-prompt rows). It supports navigating retained history / undo-redo semantics for the active session’s linear history model. File restore is **not** mixed into `/history` (extension-owned `/rewind` is separate and package-local).

Selected history position and retained-history replay are derived from the canonical event stream; details of projection live in the TUI/runtime implementation.

## Concurrency and locking

Session access uses cooperative locking so two interactive controllers do not corrupt the same session directory. Contenders fail closed or wait according to runtime lock helpers — do not hand-edit `events.jsonl` while a session is live.

## Storage notes

- SQLite (and other DBs) back metadata/queues as configured by the app; session **conversation** remains file-based events for portability and replay.
- Attachments and large tool outputs may live under tool temp paths (for example output-cap storage) with references from events — not as free-form copies inside every event payload. Lifecycle detail: [Ephemeral output-cap artifacts](#ephemeral-output-cap-artifacts).
- The model-visible `fork` tool is **shipped** (isolated child with inherited parent context; see [agents.md](agents.md)). Linear history remains the supported user model: multi-branch session **trees**, `/tree` UI, and session-graph browsing are **not** shipped end-user workflows.

## Transition validity

Execution handlers reject completed or stale results where their lifecycle supports
that check. The pending-only `ApplyCommand` mailbox does not retain completed IDs
for deduplication. Direct-shell commands separately check canonical applied identities.
Tool execution is not an exactly-once guarantee: a retried operation can repeat external effects.

### Repair safety

`/repair` is explicit recovery, never automatic. The terminal command applies repairs.
The runtime protocol also supports a preview with `apply=false`. Restarting a session
does not make abandoned claimed queue messages available again.

Repair reuses the current operation identity. It does not mark unfinished work as
completed, roll back side effects, or clear abandoned claimed messages. Check whether
the original command or external tool already performed its action before redispatching.

Repair reconstructs model and shell requests from canonical state and evidence.
Ordinary tools reuse their current calls from the active SQL batch. Collected sibling
results can be delivered to the owner without executing those siblings again.
Queued calls stay behind the collector's capacity and sequential barriers. A batch
awaiting human input is not redriven, including independent in-flight siblings.

Repair preserves invocation identities where available, but canonical events do not
prove whether an external action already happened. There is no generic execution
claim, saved worker-result protocol, or receipt retirement. Explicit repair can
repeat external execution. Tools with critical side effects must provide their own
idempotency contract.

Repair responses report redispatch requests, not completed execution or queue
acceptance. Sends run after the owner lock releases. If a send fails, a transient
error notification reports accepted and failed sends; some work may still run.
There is no persistent sending buffer or automatic resend. Retry explicitly with
`/repair` after checking for prior external effects. The transition journal recovers
unfinished canonical appends and local coordination, not lost sends after finalization.

For a pending deferred child call, repair cancels unfinished children instead of
retrying their operations or launching replacements. It durably aborts abandoned
requests and settles the existing parent tool call, so queued parent input can continue.
Child run IDs, artifacts, worktrees, and provider cache keys remain intact. Completed
children keep their results. Late child responses cannot revive cancelled executions.
Active streaming and human-input waits retain their safety checks; existing batch
interruptions and deadlines retain their lifecycle owner. A preview does not cancel work.

Parent repair captures child-maintenance obligations before committing its decision.
Recovery finishes the captured plan without reevaluating newer children. Its captured
generation checks prevent that plan from changing newer work. A completed repair
command ID is not retained; resubmission can prepare a new plan for the current generation.

If a cancelled or failed terminal history has unmatched assistant tool calls, repair
appends synthetic error tool results and a batch commit. This restores valid model
history without repeating tool execution or appending another terminal event.

Calls waiting for human input are not redispatched. Compaction repair requires a
request from the canonical compaction-start event matching the current operation,
and refuses when that input cannot be reconstructed.
Do not edit queue rows or event logs to force recovery while a controller is live.

Existing `idempotency.jsonl` files are inert legacy data. Current runs do not create
them, and they are not a repair mechanism.

## Compaction interaction

Compaction rewrites the LLM-visible history while retaining a recent raw tail and recording compaction events. Resume after compaction replays the compacted view correctly — see [compaction.md](compaction.md).

## Ephemeral output-cap artifacts

Oversized tool output is saved under `tools.output_cap.path/run-<sha256(run_id)>/` only while a controller owns that session. Controller start/resume clears prior parent and child scopes; controller shutdown and explicit deletion dispatch the same cleanup before canonical session metadata is removed. Completion, cancellation, and failure remain resumable boundaries and do not clear these artifacts. Saved output-cap paths in canonical notices are metadata only: replay, repair, and projection never read the artifact, so historical notices can refer to deleted files. A 24-hour first-use stale cleanup remains the crash/orphan fallback.

Legacy date-prefixed root files are intentionally not lifecycle-deleted, but exact date-prefixed files are covered by the existing 24-hour automatic fallback and the separately authorized dry-run procedure. Operators may clean them only with quiesced controllers after verifying the configured root, dry-running an exact `^\d{8}-[a-f0-9]{16}\.txt$` name/date selection, reviewing names, and deleting each approved direct file non-recursively. Historical custom `session_prefix` root files match neither lifecycle nor automatic fallback patterns: they are inert, operator-owned artifacts requiring separate exact-name review and individual non-recursive authorization.

## Related

- Terminal commands: [terminal-usage.md](terminal-usage.md)
- Settings: [settings.md](settings.md)
- Agents: [agents.md](agents.md)
- Human input: [human-input.md](human-input.md)
