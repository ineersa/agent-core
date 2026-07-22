---
description: Session identity, storage layout, replay, locking, and resume/fork design.
---

# Hatfield Session Storage

**One directory = one session = one agent run = one future fork tree node.**

## Goals and invariants

- **`session_id === run_id`.** The TUI session and the AgentCore run share a single
  identity. One DB-issued auto-increment ID (numeric string) names the session
  directory and is used as the AgentCore `RunState::$runId` and every
  `RunEvent::$runId`.
- **`provider_cache_key`.** Each persisted session row also stores an immutable
  UUIDv7 (`hatfield_session.provider_cache_key`) generated once at creation (and
  backfilled for existing rows; SQLite keeps the column nullable at DDL while
  repair migration `Version20260715120000` assigns distinct UUIDv7 values for any
  NULL or empty rows left after earlier backfill). Model resolution exposes it as
  an internal
  invocation option for provider adapters. Codex uses it for `prompt_cache_key`
  and correlation headers. DeepSeek, Z.AI, and generic OpenAI-compatible
  providers do not receive this field on the wire today.
- **Self-contained.** Every session directory holds everything needed to resume the
  conversation or fork it in the future — no global `.hatfield/runs/` registry.
- **Canonical directory name.** The directory name under `.hatfield/sessions/` is
  the authoritative identity. Embedded IDs inside files are validated on read and
  must match the directory name. A mismatch indicates data corruption.
- **Canonical event stream.** `events.jsonl` is the single source of truth for
  everything that happened in a run. `state.json` is a materialized RunState
  snapshot and concurrency checkpoint. The TUI transcript projection is rebuilt
  from the canonical event stream on resume and during live polling.

## Directory layout

```text
.hatfield/sessions/<id>/
  state.json               AgentCore RunState materialized snapshot/checkpoint
  events.jsonl             AgentCore RunEvent canonical event stream
  attachments/             (future) pasted files, images, diffs
  artifacts/agents/        parent-scoped child agent artifacts
    registry.json          canonical artifact entry list
    <artifactId>/
      metadata.json        per-child identity/status/timestamps
      handoff.md           human-readable child agent handoff
      events.jsonl         child RunEvent stream (AgentChildRunEventStore)
      state.json           child RunState cache (AgentChildRunStore)
```

Session metadata (identity, prompt, model, reasoning, fork tree links)
lives in the `hatfield_session` database table, not in a metadata.yaml file.

### File purposes

| File | Canonical? | Written by | Read by | Format |
|------|-----------|------------|---------|--------|
| `state.json` | No — materialized snapshot/checkpoint | `SessionRunStore::compareAndSwap()` | `SessionRunStore::get()`, resume flow | JSON (Symfony Serializer) |
| `events.jsonl` | **Yes — canonical domain event stream** | `SessionRunEventStore::append()` | `SessionRunEventStore::allFor()`, `InProcessAgentSessionClient::events()`, TUI tick callback | JSONL (EventPayloadNormalizer) |
| `artifacts/agents/registry.json` | **Yes — child artifact list** | `AgentArtifactRegistry::create()` / `update()` | `AgentArtifactRegistry::list()` / `get()` | JSON (Symfony Serializer) |

### Child agent artifacts

Parent sessions that launch child subagents store child run data under
`artifacts/agents/<artifactId>/`.  Key design points:

- **No top-level child session directories.** Child runs are scoped entirely
  under the parent's `.hatfield/sessions/<parentRunId>/artifacts/agents/`,
  so `listSessions()` only returns parent sessions.
- **registry.json is the canonical source** for artifact discovery within a
  parent scope.  `metadata.json` is an inspectable sidecar; it is never read
  by production load paths.
- **Child events and state** use the same Canonical JSONL and CAS patterns as
  parent runs, stored under the parent directory via `AgentChildRunEventStore`
  and `AgentChildRunStore`.

### Deferred subagent supervision (Single and Parallel)

Parent subagent tool calls (single or parallel) use the normalized deferred batch model:

- Durable tables `deferred_subagent_batch` and `deferred_subagent_child` store one batch per parent tool call. `execution_mode` distinguishes explicit Single vs Parallel. Parent tool correlation (`parent_turn_no`, `parent_tool_call_id`, `parent_order_index`) is stored durably on the batch row.
- `SubagentExecutionService` delegates both `execute()` and `executeParallel()` to `DeferredSubagentBatchLaunchService`, which reserves the batch, starts children in `batch_index` order, and returns `DeferredToolCompletionOutcome` with deterministic `lifecycle_id` (batch UUID v5 from parent run + tool call).
- Child `events.jsonl` and `state.json` under the parent artifact remain **durable replay, recovery, and diagnostics only**. Steady-state supervision does **not** poll these files or parent/child `RunStore`.
- After each child `RunCommit`, `AfterTurnCommitHookContext` carries persisted committed event summaries (allocated `seq`). `DeferredSubagentBatchChildTurnHookSubscriber` dispatches `ObserveDeferredSubagentBatchChildTurnMessage` to `run_control` for tracked Launched children only.
- The run_control observe handler incrementally reduces summaries into a compact per-child JSON projection (`child_lifecycle_projection`, `child_event_cursor`) with batch-level `aggregate_progress_revision` and delivery markers. Parent `subagent_progress` uses mode-aware payloads (flat single vs aggregate parallel) and stored parent correlation via `SubagentProgressEventAppender` (not `StackToolExecutionContextAccessor`).
- `DeferredToolCompletionRegisteredEvent` wakes batch lifecycle delivery after generic deferred registration. Natural terminal completion and interruption completion are mode-aware on the normalized batch stack.
- Timeout uses `InterruptDeferredSubagentBatchMessage` on `run_control` with `DelayStamp` from persisted `deadline_at`. Parent cancellation uses `DeferredSubagentBatchParentCancelHookSubscriber` on parent `AfterTurnCommit` when status is `cancelling` or `cancelled`. First-wins interruption intent is durable under optimistic locking; `AgentRunner::cancel` runs before delivery when appropriate.
- Gap observation enqueues `RecoverDeferredSubagentBatchLifecycleMessage` without advancing the cursor from the gap batch. Recovery tails each child `events.jsonl` only via `AgentChildRunEventStore::readAfterSeq(cursor)`, reconciles all child rows in `batch_index` order, then enqueues `DeliverDeferredSubagentBatchLifecycleMessage` (even when tails are empty).
- `run_control` `WorkerStartedEvent` recovery is scoped to `%env(HATFIELD_SESSION_ID)%` and reconciles unfinished `Reserved` or `Launched` batch rows for that parent session; persisted interruption intents and pending timeouts are re-enqueued from durable markers.

### Session metadata (database)

Session identity and metadata are stored in the `hatfield_session` DB table
(authoritative) and exposed through `HatfieldSessionStore::findSession()`
and `SessionMetadataStore::findSession()`, which return
`?HatfieldSession`. `updateMetadata()` and other store write APIs own
mutations and flush; callers must treat entities from `findSession()` as
read-only.

Public `session_id` and AgentCore `run_id` are both the string cast of
`HatfieldSession::$id` (auto-increment integer). There is no separate
`public_id` column.

Typed fields on `HatfieldSession` (Doctrine entity):

| Property | Type | Notes |
| --- | --- | --- |
| `id` | `int` | Cast to string for external session_id / run_id |
| `cwd` | `string` | Project working directory at creation |
| `prompt` | `?string` | Initial user prompt when set |
| `parentId` | `?string` | Future fork tree parent |
| `rootId` | `?string` | Future fork tree root |
| `model` | `?string` | Full model ref, e.g. `deepseek/deepseek-v4-pro` |
| `modelProvider` | `?string` | Denormalized provider id |
| `modelName` | `?string` | Denormalized model name |
| `reasoning` | `?string` | off/minimal/low/medium/high/xhigh/max when set |
| `name` | `string` | Non-empty display name (default from first message) |
| `providerCacheKey` | `?string` | UUIDv7 for provider cache/correlation; healthy persisted rows are non-null (assigned at creation or repaired at agent startup) |
| `createdAt` | `\DateTimeImmutable` | Row creation time |
| `updatedAt` | `\DateTimeImmutable` | Last metadata update |

Nullable columns are `null` on the entity when unset (not omitted keys).
`name` is always a non-empty string, initialized from the first user
message (trimmed, collapsed to one line, capped at 200 chars) during
session creation and later renameable via `/rename`.

Future forking will add parent_id/root_id support (see [Future fork tree](#future-fork-tree)).

### Session naming and display

The `name` column is a non-empty display name guaranteed to be present
for every session. It is initialized from the first user message/prompt
on creation (trimmed, internal whitespace collapsed to single spaces,
and capped at 200 characters via Symfony String).  Empty or
whitespace-only prompts receive the deterministic default `"Session"`.
Persisted in `hatfield_session.name` and returned unconditionally by
`findSession()` and `listSessions()`.

A stored non-empty `name` is always the `displayTitle` in picker output.
The `promptPreview` field remains a separate computed value (first 60
characters of the prompt, with `...` ellipsis when truncated) for rich
picker display, but does not determine the display title.

`updateMetadata(['name' => ...])` supports renaming (e.g. via `/rename`).
Empty, whitespace-only, null, or non-string values are normalized to the
same deterministic default `"Session"` — the name column is never null
and never empty after an update.

### Storage model at a glance

```text
Canonical history
┌──────────────────────────────────────────────────────────┐
│ events.jsonl                                             │
│ append-only AgentCore RunEvent stream                    │
│ source for replay, audit, projections, and future forks  │
└───────────────┬──────────────────────────────────────────┘
                │ materializes current run state
                ▼
Current-state snapshot / checkpoint (rebuildable)
┌──────────────────────────────────────────────────────────┐
│ state.json                                               │
│ serialized RunState used for fast resume + CAS version   │
│ auto-rebuilt from events.jsonl when missing or stale     │
└───────────────┬──────────────────────────────────────────┘
                │ projects user/protocol views
                ▼
TUI transcript is rebuilt from events.jsonl via
RuntimeEventMapper + TranscriptProjector during resume and live polling.
```
In the current local filesystem implementation, both the canonical stream and
materialized snapshot live under `.hatfield/sessions/<id>/`. In a future
web/server deployment, the same concepts may be backed by database rows/tables
for hot state and events, with JSONL files acting as cold archives or exported
projections. The local Hatfield path intentionally keeps those roles explicit
without requiring database infrastructure.

### state.json

Serialized `Ineersa\AgentCore\Domain\Run\RunState` via Symfony Serializer
(`ObjectNormalizer` + `BackedEnumNormalizer` + `DateTimeNormalizer`):

```json
{
    "runId": "42",
    "status": "running",
    "version": 3,
    "turnNo": 1,
    "lastSeq": 5,
    "isStreaming": false,
    "streamingMessage": null,
    "pendingToolCalls": [],
    "errorMessage": null,
    "messages": [],
    "activeStepId": null,
    "retryableFailure": false
}
```

Written atomically by `SessionRunStore::compareAndSwap()` under a Symfony Lock
(`FlockStore`). `compareAndSwap()` reads the current version, compares it to the
expected version, and writes only if they match — preventing race conditions
in concurrent message processing.

**`state.json` is a rebuildable hot checkpoint/projection**, not a required
source of truth.  When `state.json` is missing or stale (`lastSeq` behind the
max canonical event sequence), the pipeline automatically rebuilds
the RunState from `events.jsonl` via `RunStateReplayService` before advancing
the run.  The canonical event stream in `events.jsonl` is the single source
of truth; `state.json` is a materialized cache that can be discarded and
recreated at any time.

### events.jsonl

One JSON object per line, produced by `EventPayloadNormalizer::normalizeRunEvent()`:

```jsonl
{"schema_version":"1.0","run_id":"42","seq":1,"turn_no":1,"type":"run_started","payload":{"run_id":"42"},"ts":"2026-05-13T12:00:00+00:00"}
{"schema_version":"1.0","run_id":"42","seq":2,"turn_no":1,"type":"turn_start","payload":{},"ts":"2026-05-13T12:00:01+00:00"}
```

Lines are appended under a Symfony Lock (`FlockStore`). `allFor()` reads all
lines, validates embedded `run_id` against the directory name, and sorts by
`seq` before returning.



### Event sequence allocation (`sequence.cursor`)

Each run (parent session directory or child artifact directory) owns a monotonic
event sequence allocator beside its canonical log:

- **Location:** `.hatfield/sessions/<runId>/sequence.cursor` next to
  `events.jsonl`, or
  `.hatfield/sessions/<parentRunId>/artifacts/agents/<artifactId>/sequence.cursor`
  for child runs.
- **Locking:** `FileRunSequenceAllocator` performs read/bootstrap/advance/write
  under a single exclusive `flock(LOCK_EX)` on the cursor file handle (one
  atomic read-modify-write transaction). Separate unlocked reads followed by
  `LOCK_EX` writes are not used.
- **Bootstrap:** When the cursor file is missing or empty, allocation bootstraps
  once from the maximum `seq` value in the existing `events.jsonl` (physical
  line order may be out of seq; max wins). After the cursor exists, normal
  allocation does not scan `events.jsonl`.
- **Crash gaps:** The cursor high-water mark is advanced before JSONL append.
  A crash between those steps may leave a **sequence gap** in the log. Replay
  tolerates gaps.
- **Corruption:** Duplicate `seq` values in the canonical stream remain a typed
  `RunStateReplayException` corruption failure.
- **Writers:** Canonical production writers allocate through
  `EventStoreInterface` (`append` / `appendMany`).
  Parent session, child artifact, and `ChildAwareEventStore` routing share the
  same contract.


### Runtime event → transcript projection

The TUI layer reads runtime events and projects them into the user-visible transcript via `RuntimeEventMapper` + `TranscriptProjector`:

```
events.jsonl          RuntimeEventMapper          TranscriptProjector         TuiSessionState::transcript
(canonical)           (translates RunEvent        (builds TranscriptBlock     (in-memory block list
                      → RuntimeEvent)             via EventDispatcher         used for display)
                                                   subscribers)
────────────────────────────────────────────────────────────────────────────────────────────────────
```

On resume, `SessionInitializer::buildInitialTranscript()` replays events from
`events.jsonl` through the mapper and projector to rebuild the full transcript
history. The poller's `lastSeq` cursor prevents duplicate processing of already-
projected events.

No separate transcript.jsonl file is written. Transcript blocks are a derived
projection of the canonical event stream and are never persisted independently.

### Compaction replay and resume semantics

Context compaction stores a checkpoint event (`context_compacted`) that carries
the full replacement message list in `payload.messages`.  Replay treats this as
a complete replacement snapshot:

1.  Events before `context_compacted` build the original message list.
2.  `context_compacted` **replaces** the message list with `payload.messages`
    — the full new compacted list (summary message + retained tail).
3.  Later events append new messages normally on top of the compacted list.

The summary message carries `metadata: {"compact_summary": true}` so the
compacted origin is identifiable in the message list.  Repeated compaction
works because the prior summary message becomes part of the current list and
is incorporated into the new summary.

Failed compaction events (`context_compaction_failed`) do **not** replace
messages.  The payload uses `messages_replaced: false` and the original
message list is preserved unchanged.  `state.json` is rebuildable from
`events.jsonl` at any time — after a compaction, replay reconstructs the
compacted state exactly.

## ID rules and integrity checks

1. **Directory name is canonical.** The directory under `.hatfield/sessions/`
   defines the identity. All embedded `run_id` / `session_id` values in state,
   events, metadata, and transcript must match the directory name.

2. **Validation on read.** `SessionRunStore::get()` and
   `SessionRunEventStore::allFor()` throw `RuntimeException` if an embedded
   `runId` does not match the directory name. This catches disk corruption or
   bugs in fork/rewrite logic.

3. **Validation on write.** The stores write the `runId` from the in-memory
   domain object, which should already match. No separate check on write because
   `RunState::$runId` is the canonical source at that point.

4. **Future fork rewrite.** When a session directory is copied to a new ID, the
   fork logic must rewrite the embedded `runId` in:
   - `state.json` — top-level `runId` key
   - `events.jsonl` — `run_id` field in every line
   - `hatfield_session` DB row — `session_id`, `run_id`; set `parent_id` and `root_id`

## Resume flow

```text
php bin/console agent --resume a1b2c3d4e5f6
```

1. `AgentCommand` validates the session directory exists via `HatfieldSessionStore::exists()`.
2. `SessionInitializer::initialize()` loads metadata, transcript, and runtime events from disk, populating a `TuiSessionState` (in `src/Tui/Runtime/`).
3. If metadata contains a `run_id`, `InteractiveMode::startOrResumeRun()` calls `AgentSessionClient::attach($runId)`.
4. The AgentCore `SessionRunStore::get($runId)` loads `state.json` and the pipeline
   continues from the persisted state.
5. The TUI tick callback polls `AgentSessionClient::events($runId)`, which reads
   `events.jsonl` via `SessionRunEventStore::allFor($runId)`.

Because `session_id === run_id`, the CLI `--resume` option takes the same ID
for both the TUI session context and the AgentCore run.

## New session flow

```text
php bin/console agent --prompt="Hello"
```

1. `SessionInitializer::initialize()` calls `$sessionStore->createSession($prompt)` → DB-issued auto-increment numeric string ID, creates a `TuiSessionState` (in `src/Tui/Runtime/`).
2. `$sessionStore->createSession(cwd, prompt, sessionId)` creates the
   self-contained directory with empty files.
3. A `StartRunRequest` is created with `runId: sessionId`.
4. `AgentSessionClient::start($request)` passes the ID to AgentCore's
   `AgentRunner::start()`, which uses it directly (no UUID generation).
5. `RunOrchestrator` processes `StartRun` → `RunMessageProcessor` calls
   `SessionRunStore::compareAndSwap()` and `SessionRunEventStore::append()`,
   creating `state.json` and `events.jsonl` entries.

### Lazy draft sessions (no initial prompt)

When the TUI starts without a prompt (`bin/console agent` with no `--prompt`
and no `--resume`), the default behavior is a **lazy draft**:

1. `SessionInitializer::initializeDraft()` creates a `TuiSessionState` with
   an empty session ID — no DB row and no session directory are created.
2. When the user submits their first message, `SubmitListener` detects the
   empty session ID, calls `$sessionStore->createSession($text)` to create
   the DB row and session directory, then starts a new run normally.
3. If the user exits without typing a message, no orphan DB rows or session
   directories are left behind.

Session switch lifecycle events (`/new`, `/resume`) use the same lazy draft
path via `TuiSessionSwitchService::requestNewDraft()` — the draft is promoted
on first submitted message regardless of how the draft was initiated.

`/resume` switches to an existing session directly (with a session ID argument)
or opens an interactive picker (no argument), relying on the canonical
`events.jsonl` replay path from RTVS-08 to rebuild the transcript on resume.
No orphan rows or session directories are created when draft sessions are
discarded without submitting a message.

## Sessions base path resolution

All stores must agree on the sessions base directory. This is resolved in
layers:

```text
InteractiveMode::run()
  │
  ├─► HatfieldSessionStore::resolveSessionsBasePath(cwd)
  │     ├─ Uses AppConfigResolver::resolve(cwd) to load Hatfield settings
  │     ├─ Reads sessions.path from config (defaults to .hatfield/sessions)
  │     └─ Returns absolute path (resolved relative to project cwd)
  │
  ├─► AgentSessionClient::initializeSessionsBasePath(path)
  │     ├─ InProcessAgentSessionClient: delegates to SessionRunStore::setSessionsBasePath()
  │     │   and SessionRunEventStore::setSessionsBasePath()
  │     └─ JsonlProcessAgentSessionClient: no-op (TODO: pass via subprocess env)
  │
  └─ Now all stores (HatfieldSessionStore, SessionRunStore, SessionRunEventStore)
     read/write from the same resolved sessions base directory.
```

**Default behavior:** When no `sessions.path` is configured in Hatfield
settings, both `HatfieldSessionStore` and the AgentCore stores default to
`<projectCwd>/.hatfield/sessions`.

**PHAR distribution:** The AgentCore stores default to
`%kernel.project_dir%/.hatfield/sessions` at construction time, but
`initializeSessionsBasePath()` overrides this before any run operations
begin, ensuring sessions are stored under the user's project directory
regardless of where the PHAR binary was extracted.

## Concurrency and locking

All writes to session files are protected by Symfony Lock with `FlockStore`:

| Operation | Lock key | Scope |
|-----------|----------|-------|
| `SessionRunStore::compareAndSwap()` | `hatfield-run-<id>` | Read-check-write cycle |
| `SessionRunEventStore::append()` | `hatfield-run-<id>` | Single event append |
| `HatfieldSessionStore::createSession()` | `hatfield-session-<id>` | Directory creation + metadata write |
| `HatfieldSessionStore::updateMetadata()` | `hatfield-session-<id>` | Metadata read-merge-write |

Locks use the same in-process key space across session and run operations
(`hatfield-run-<id>` for agent-core stores, `hatfield-session-<id>` for
Hatfield session store). Both scopes lock the same ID.

`FlockStore` creates lock files in the system temp directory (not in the session
directories). Locks are automatically released when the lock object is
destroyed, or explicitly via `$lock->release()` in `finally` blocks.

These locks are short-lived critical sections: they protect individual
reads/writes and release immediately after. There is **no long-lived "a TUI is
actively attached to this session" lock**. Consequently, running two Hatfield
TUI instances against the same session concurrently is unsupported and produces
confusing results: each instance spawns its own controller subprocess and event
poller, both write to the same `events.jsonl` / `state.json`, and the two
conversations interleave in one event stream. A message sent in one instance is
picked up by the other instance's poller, and both agents may respond into the
same session. Individual writes stay atomic (no structural file corruption),
but the turn tree, transcript projection, and run state become semantically
incoherent.

Do not attach a second TUI to a session another TUI is actively using. Resume a
session only from the instance that owns it, or after the owner has exited.

## Why no `.hatfield/runs/` directory

Storing run data separately from session data would require a run→session
resolver, complicate forking (two directories to copy), and make sessions
non-self-contained. By making `session_id === run_id` and storing everything
under the session directory, each session/run is a single directory that can be
copied, archived, shared, or forked as a unit.

## Session ID allocation

Session IDs are DB-issued auto-increment integers converted to strings.
The `hatfield_session` table acts as an authoritative ID registry;
`createSession()` inserts a row, obtains the ID, then creates the
session directory under `.hatfield/sessions/<id>/`.

- Provides non-colliding IDs without random-generation loops.
- Drives the invariant `session_id === run_id` at creation time.
- Session directories remain self-contained; the DB row serves as
  the identity registry and metadata store.

## Why no SQLite yet

A filesystem with JSONL/YAML is simpler for v1:

- Zero schema management.
- Human-readable and debuggable with `cat`, `grep`, `jq`.
- No migration scripts needed.
- Each session is a self-contained directory.

A SQLite-backed `hatfield_session` table is used for session ID allocation
(auto-increment primary key, no collision risk), replacing the original
random 12-char hex loop. Session content (metadata, events, transcript,
state) remains in the filesystem directory for the reasons above.

## Future fork tree

Hatfield aims to support Pi-style conversation trees where sessions can be
forked to create branches. The storage model is designed to support this without
a database.

### Fork metadata

After a fork, the `hatfield_session` DB row gains optional keys:

```yaml
session_id: bbb222
run_id: bbb222
parent_id: aaa111
root_id: aaa111
fork:
  source_id: aaa111
  source_seq: 42
  source_message_id: null
  mode: exact_copy
created_at: '2026-05-13T13:00:00+00:00'
```

| Key | Meaning |
|-----|---------|
| `parent_id` | The session this was forked from |
| `root_id` | The root of the conversation tree |
| `fork.source_id` | The immediate source of the fork (usually === `parent_id`) |
| `fork.source_seq` | The event sequence number at the fork point |
| `fork.source_message_id` | The message ID at the fork point, if applicable |
| `fork.mode` | `exact_copy` (full state), `context_only` (transcript only), or future modes |

### Fork flow (future)

```bash
hatfield session:fork aaa111 --new-id bbb222
```

Implementation outline:

1. Copy `.hatfield/sessions/aaa111` to `.hatfield/sessions/bbb222`.
2. Generate new ID `bbb222` if not specified.
3. Rewrite all embedded IDs from `aaa111` to `bbb222` in:
   - `hatfield_session` DB row (set parent_id, root_id)
   - `state.json`
   - `events.jsonl`
4. Set `parent_id`, `root_id`, and `fork` block in DB metadata.
5. Resume: `php bin/console agent --resume bbb222`.

### Tree listing (future)

Listing all sessions and building a tree can be done by scanning
the `hatfield_session` DB table and reading `parent_id` / `root_id`.

No database index is required for session counts in the range of hundreds to
low thousands.

## Backward compatibility

Sessions created by earlier versions of the code may have separate `session_id`
and `run_id` in metadata, or lack `parent_id`/`root_id` fields, or store events
in a different location.

The current code treats missing fields as `null` (for parent/root) and validates
embedded IDs on read. If an older session is loaded:

- Missing `parent_id`/`root_id` → treated as `null` (root session). Safe.
- Different `session_id` vs `run_id` in metadata → the `--resume` path uses the
  directory name as both, and `run_id` in metadata is updated on the next
  `updateMetadata()` call. The old duality is tolerated but not preferred.
- Events with `run_id` matching the directory → read normally.
- Events with `run_id` NOT matching the directory → throws `RuntimeException`.

No automated migration script exists yet. Older sessions that happen to have the
same ID everywhere will work. Sessions with mismatched IDs will fail on
validation and can be fixed manually by editing the directory name or rewriting
embedded IDs.

## Turn tree model

Within a single session timeline, the turn tree model enables branching conversation
paths — rewinding to an earlier turn and continuing in a new direction without
destroying the original history.

### Canonical event stream (append-only)

Turn tree metadata lives in `events.jsonl` alongside all other domain events.
The stream remains **append-only** — branching never truncates or rewrites
existing events. Two new event types carry tree structure:

| Event type | Purpose |
|------------|---------|
| `turn_advanced` (extended) | Carries `parent_turn_no` (nullable int; null for root turn) to link turn nodes. The existing `turn_no` field remains the stable turn identifier. |
| `leaf_set` | Marks the current active leaf/head of the conversation. Payload includes `turn_no` (target leaf), `previous_turn_no` (prior leaf, nullable), `parent_turn_no`, and `reason` (e.g. `"continue"`, `"rewind"`, `"fork"`). |
| `turn_branched` (reserved) | Reserved for explicit branch metadata in future rewind/branch commands. Not emitted by the continue path. |

Each normal turn advance emits a `turn_advanced` event followed immediately by a
`leaf_set` event confirming the new leaf position. The pair is atomic within the
same handler execution.

### Leaf pointer

`leaf_set` is the canonical current leaf marker. On replay:
1. Process all `leaf_set` events in seq order — the last one wins.
2. If no `leaf_set` events exist (old linear stream), the highest turn number
   is the implicit current leaf.

### Read model: TurnTreeDTO

**Implementation (SESSION-07A):** turn-tree projection and branch replay filtering live under
`Ineersa\CodingAgent\Session\TurnTree` and `Ineersa\CodingAgent\Session\Replay`.
AgentCore replay/rewind handlers consume narrow contracts under
`Ineersa\AgentCore\Contract\TurnTree` (not the full session DTO shapes).

`TurnTreeProjector` builds a `TurnTreeDTO` from the canonical event stream:
- `nodesByTurnNo` — map from turn number to `TurnTreeNodeDTO` (turnNo, parentTurnNo,
  childTurnNos, anchorSeq, title, promptPreview, isCurrentLeaf).
- `activePathTurnNos` — ordered list of turn numbers from root to current leaf.
- `rootTurnNos` — turn numbers with no parent.
- `currentLeafTurnNo` — the current active leaf.

Titles and previews are best-effort from safe message fields: initial user messages
in `run_started`, steer/follow-up text in `agent_command_applied`, assistant text in
`llm_step_completed`. Raw system prompts are excluded.

### Branch-aware replay

`TurnTreeReplayFilter` uses the projector to filter the event stream to only events
on the active branch path:
- Includes run-level events (`turnNo === 0`, e.g. `run_started`).
- Includes events whose `turnNo` is in the active path.
- Includes tree metadata events (`leaf_set`, `turn_branched`).
- **Excludes** abandoned sibling branch events (message/tool/assistant content
  for turns not on the active path).

**Integrity checks** (duplicate sequences, missing-sequence contiguity) are always
performed on the **full canonical sorted event stream**, not the filtered active-branch
stream. Filtered branch paths naturally have sequence gaps because abandoned sibling
events remain in `events.jsonl`.

`RunStateReplayService` and `ReplayService` both integrate `TurnTreeReplayFilter`:
- State/messages are rebuilt from filtered active-branch events.
- `lastSeq` in the rebuilt state is set to the full canonical max event sequence
  so state is current with respect to the append-only stream.
- `leaf_set` and `turn_branched` are no-op reducers during RunState replay;
  they do not change status, messages, or turn counters.

### Rewind-and-continue semantics

The `/tree` UI provides an actionable turn tree picker. When the user selects a
non-current leaf and presses Enter, the system performs a **rewind** — resetting
the conversation context to the selected turn and allowing the user to continue
in a new direction from that point.

**Leaf-pointer model:** The rewind is implemented as a `leaf_set` event appended
to the canonical stream. No events are truncated, deleted, or modified. The new
`leaf_set` changes the current leaf pointer, and all subsequent events are
appended normally. This is directly analogous to pi-mono's leaf-pointer rewind:
the leaf ID moves; the history is untouched.

**Turn allocation after rewind:** After a rewind (state.turnNo < globalMaxTurnNo),
the next `turn_no` allocated is `max(globalMaxTurnNo, state.turnNo) + 1`, NOT the
old `state.turnNo + 1`. This prevents turn-number collisions with the abandoned
branch's turns, which would corrupt `nodesByTurnNo`'s int-keyed map in
`TurnTreeDTO`. For linear sessions with no abandoned branches,
globalMaxTurnNo === state.turnNo and behavior is unchanged.

**Transcript rebuild:** `RuntimeEventPoller` and `SessionInitializer` call
`SessionTranscriptProviderInterface::transcriptForLeaf(runId, leafTurnNo)` to fetch
a snapshot with projected transcript blocks plus active-path replay runtime events.
TUI assigns transcript blocks wholesale and replays returned events through
`TuiRuntimeEventApplier` for non-transcript state (usage, queues, activity). The TUI
does not filter active-path raw runtime events or replay transcript locally for leaf
changes. Old abandoned-branch transcript blocks are removed.

**No file/workspace rollback:** The rewind affects the message context only.
It does not roll back file edits, tool side-effects, or any filesystem changes.
SESSION-08 (exact file rewind checkpoints) will address selective file restore.

**No branch_summary:** Abandoned branches do not receive an LLM-generated
summary. The abandoned turn subtree remains browsable in `/tree` and is
preserved in `events.jsonl` for future reference, but is not injected into
the model's context on the new branch. A future enhancement may add
`branch_summary` entries as first-class tree nodes.

### Old / no-tree streams

Sessions without `leaf_set`/`parent_turn_no` are treated as a linear single-branch
tree. The projector derives parent relationships as `turn_no - 1` and the current
leaf as the highest turn number. No migration is required.

### Future `/tree` UI

The `TurnTreeDTO` read model provides everything needed for a `/tree` picker
(render nodes, highlight current leaf, navigate branches). The tree data is
reusable without destructive truncation or separate tree files.

## Open gaps and future work

| Gap | Priority | Notes |
|-----|----------|-------|
| Messenger synchronous bus wiring | High | `config/packages/messenger.yaml` buses have empty middleware arrays. `RunOrchestrator` handlers are registered but messages may not be dispatched. Required for actual run persistence end-to-end. |
| File-backed CommandStore | Medium | `InMemoryCommandStore` loses pending commands on restart. Step IDs use `hrtime()` so duplicates are unlikely, but file backing would improve durability. |
| File-backed MessageIdempotencyService | Low | In-memory idempotency state is lost on restart. Not critical because step IDs are time-based and won't repeat. |
| Session listing (`listSessions()`) | **Done** | `HatfieldSessionStore::listSessions()` returns catalog rows with `displayTitle`. Turn tree read model (`TurnTreeDTO`, `TurnTreeProjector`) done (SESSION-05); `/tree` UI picker remains future (SESSION-06/07). |
| Session pruning/cleanup | Low | No auto-expiry or `session:prune` command. Orphaned sessions accumulate. |
| Fork command (`session:fork`) | Medium | Planned; storage model is ready. Needs rewrite logic + CLI command. |
| Attachments storage (`attachments/`) | Low | Directory created in layout docs but not yet used. Will store pasted files, images, diffs. |
| Session rename / alias | **Done** | SESSION-01 added `name` column (non-null, initialized from first user message) + listing; SESSION-04 `/rename` TUI command with picker insertion and session-id completions for `/resume` + `/rename`. |
| Runtime event streaming | Medium | Current polling is synchronous full-scan. Incremental delivery would improve large sessions. |
| Rebuild `state.json` from `events.jsonl` | **Done** | `RunStateReplayService` rebuilds RunState from canonical events when `state.json` is missing or stale. `state.json` is now a disposable cache. |
## Extension API foundations (OM-01)

Hatfield exposes three minimal Extension API capabilities for independently owned observational memory:

1. **After-turn commit hook** — existing `AfterTurnCommitHookInterface` receives the already-committed hot event batch (`seq`, `type`, optional `payload`, and each event's own `turnNo`/`createdAt` ISO-8601 provenance when present). Best-effort acceleration only; no EventStore historical reads on this path.
2. **Canonical session event reader** — `SessionEventReaderInterface::readRange()` for recovery/compaction catch-up only. Full-log scans are acceptable here; do not call on every turn/boundary.
3. **Agent runner** — `$api->agent()->run(AgentCallRequestDTO)` is publicly blocking. Internally Hatfield streams (`stream=true`) via the configured Symfony AI Platform + Agent + AgentProcessor so Codex WebSocket and HTTP streaming providers complete. Isolated tools only; no ambient Hatfield tools; exact `provider/model` string.

No custom conversation-boundary notifier/projector, no runtime lifecycle APIs in OM-01, and no branch-aware event projection for MVP.

## Related documents

- [Hatfield Settings](settings.md) — configuring session path and theme
- [TUI Architecture](tui-architecture.md) — TUI widget layout and extension system
- [Architecture Rollout Plan](../.pi/plans/architecture_rollout_plan.md) — overall project architecture history
