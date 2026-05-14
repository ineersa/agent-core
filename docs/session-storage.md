# Hatfield Session Storage

**One directory = one session = one agent run = one future fork tree node.**

## Goals and invariants

- **`session_id === run_id`.** The TUI session and the AgentCore run share a single
  identity. One 12-character hex ID names the session directory and is used as
  the AgentCore `RunState::$runId` and every `RunEvent::$runId`.
- **Self-contained.** Every session directory holds everything needed to resume the
  conversation or fork it in the future — no global `.hatfield/runs/` registry,
  no database.
- **Canonical directory name.** The directory name under `.hatfield/sessions/` is
  the authoritative identity. Embedded IDs inside files are validated on read and
  must match the directory name. A mismatch indicates data corruption.
- **Append-only event stream.** `events.jsonl` is the canonical record of
  everything that happened in a run. `state.json` is a write-through cache.
  `transcript.jsonl` and `runtime-events.jsonl` are projections/debug logs that
  can be rebuilt from the canonical stream if necessary.

## Directory layout

```text
.hatfield/sessions/<id>/
  metadata.yaml            Identity, tree, and session metadata
  state.json               AgentCore RunState hot state / cache
  events.jsonl             AgentCore RunEvent canonical event stream
  transcript.jsonl         TUI/user-facing transcript projection
  runtime-events.jsonl     Runtime protocol event log (projection/debug)
  attachments/             (future) pasted files, images, diffs
```

### File purposes

| File | Canonical? | Written by | Read by | Format |
|------|-----------|------------|---------|--------|
| `metadata.yaml` | Yes (identity/tree) | `HatfieldSessionStore` | `HatfieldSessionStore`, TUI, future fork/lister | YAML |
| `state.json` | No — cache of canonical events | `SessionRunStore::compareAndSwap()` | `SessionRunStore::get()`, resume flow | JSON (Symfony Serializer) |
| `events.jsonl` | **Yes — canonical domain event stream** | `SessionRunEventStore::append()` | `SessionRunEventStore::allFor()`, `InProcessAgentSessionClient::events()`, TUI tick callback | JSONL (EventPayloadNormalizer) |
| `transcript.jsonl` | No — TUI projection | `HatfieldSessionStore::appendTranscriptEntry()` | `HatfieldSessionStore::getTranscript()`, resume display | JSONL (TranscriptEntry DTO) |
| `runtime-events.jsonl` | No — protocol projection/debug | TUI tick callback | Debugging, future replay | JSONL (RuntimeEvent DTO) |

### metadata.yaml

```yaml
session_id: a1b2c3d4e5f6
run_id: a1b2c3d4e5f6       # Always === session_id
parent_id: null             # Future fork tree parent; null for root sessions
root_id: null               # Future tree root ID; null if this session is root
created_at: '2026-05-13T12:00:00+00:00'
updated_at: '2026-05-13T12:05:00+00:00'
cwd: /home/user/projects/my-app
prompt: 'Write a README'
```

Future forking will add optional keys (see [Future fork tree](#future-fork-tree)).

### state.json

Serialized `Ineersa\AgentCore\Domain\Run\RunState` via Symfony Serializer
(`ObjectNormalizer` + `BackedEnumNormalizer` + `DateTimeNormalizer`):

```json
{
    "runId": "a1b2c3d4e5f6",
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

### events.jsonl

One JSON object per line, produced by `EventPayloadNormalizer::normalizeRunEvent()`:

```jsonl
{"schema_version":"1.0","run_id":"a1b2c3d4e5f6","seq":1,"turn_no":1,"type":"run_started","payload":{"run_id":"a1b2c3d4e5f6"},"ts":"2026-05-13T12:00:00+00:00"}
{"schema_version":"1.0","run_id":"a1b2c3d4e5f6","seq":2,"turn_no":1,"type":"turn_start","payload":{},"ts":"2026-05-13T12:00:01+00:00"}
```

Lines are appended under a Symfony Lock (`FlockStore`). `allFor()` reads all
lines, validates embedded `run_id` against the directory name, and sorts by
`seq` before returning.

### transcript.jsonl

One JSON object per line, produced by `TranscriptEntry::toArray()`:

```jsonl
{"role":"user","text":"Write a README","meta":{"session_id":"a1b2c3d4e5f6"},"created_at":"2026-05-13T12:00:05+00:00"}
{"role":"assistant","text":"I'll create a README.md","meta":{"run_id":"a1b2c3d4e5f6","seq":4},"created_at":"2026-05-13T12:00:06+00:00"}
```

Roles include `user`, `assistant`, `tool`, `system`, and `error`.

### runtime-events.jsonl

One JSON object per line, produced by `RuntimeEvent::toArray()`:

```jsonl
{"v":"1.0","type":"run_started","run_id":"a1b2c3d4e5f6","seq":1,"payload":{"run_id":"a1b2c3d4e5f6","status":"running"}}
```

This file is primarily a debug/log projection. The canonical source of events is
`events.jsonl`.

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
   - `metadata.yaml` — `session_id`, `run_id`; set `parent_id` and `root_id`
   - `transcript.jsonl` — `meta.run_id` and `meta.session_id` where present
   - `runtime-events.jsonl` — `run_id` field in every line

## Resume flow

```text
php bin/console agent --resume a1b2c3d4e5f6
```

1. `AgentCommand` validates the session directory exists via `HatfieldSessionStore::exists()`.
2. `InteractiveMode::run()` loads metadata, transcript, and runtime events from disk.
3. If metadata contains a `run_id`, calls `AgentSessionClient::resume($runId)`.
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

1. `InteractiveMode::run()` calls `$sessionStore->generateId()` → 12-char hex.
2. `$sessionStore->createSession(cwd, prompt, sessionId)` creates the
   self-contained directory with empty files.
3. A `StartRunRequest` is created with `runId: sessionId`.
4. `AgentSessionClient::start($request)` passes the ID to AgentCore's
   `AgentRunner::start()`, which uses it directly (no UUID generation).
5. `RunOrchestrator` processes `StartRun` → `RunMessageProcessor` calls
   `SessionRunStore::compareAndSwap()` and `SessionRunEventStore::append()`,
   creating `state.json` and `events.jsonl` entries.

## Concurrency and locking

All writes to session files are protected by Symfony Lock with `FlockStore`:

| Operation | Lock key | Scope |
|-----------|----------|-------|
| `SessionRunStore::compareAndSwap()` | `hatfield-run-<id>` | Read-check-write cycle |
| `SessionRunEventStore::append()` | `hatfield-run-<id>` | Single event append |
| `HatfieldSessionStore::createSession()` | `hatfield-session-<id>` | Directory creation + metadata write |
| `HatfieldSessionStore::updateMetadata()` | `hatfield-session-<id>` | Metadata read-merge-write |
| `HatfieldSessionStore::appendTranscriptEntry()` | `hatfield-session-<id>` | Transcript append |

Locks use the same in-process key space across session and run operations
(`hatfield-run-<id>` for agent-core stores, `hatfield-session-<id>` for
Hatfield session store). Both scopes lock the same ID.

`FlockStore` creates lock files in the system temp directory (not in the session
directories). Locks are automatically released when the lock object is
destroyed, or explicitly via `$lock->release()` in `finally` blocks.

## Why no `.hatfield/runs/` directory

Storing run data separately from session data would require a run→session
resolver, complicate forking (two directories to copy), and make sessions
non-self-contained. By making `session_id === run_id` and storing everything
under the session directory, each session/run is a single directory that can be
copied, archived, shared, or forked as a unit.

## Why no SQLite yet

A filesystem with JSONL/YAML is simpler for v1:

- Zero schema management.
- Human-readable and debuggable with `cat`, `grep`, `jq`.
- No migration scripts needed.
- Each session is a self-contained directory.

SQLite may become valuable later for:

- Cross-session queries and indexing.
- Efficient random-access reads for large sessions.
- Transactional integrity across multiple writes.

But for the current append-only, single-session-at-a-time workload, filesystem
JSONL with FlockStore locking is sufficient and less complex.

## Future fork tree

Hatfield aims to support Pi-style conversation trees where sessions can be
forked to create branches. The storage model is designed to support this without
a database.

### Fork metadata

After a fork, `metadata.yaml` gains optional keys:

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
   - `metadata.yaml`
   - `state.json`
   - `events.jsonl`
   - `transcript.jsonl`
   - `runtime-events.jsonl`
4. Set `parent_id`, `root_id`, and `fork` block in the new metadata.
5. Resume: `php bin/console agent --resume bbb222`.

### Tree listing (future)

Listing all sessions and building a tree can be done by scanning
`.hatfield/sessions/*/metadata.yaml` and reading `parent_id` / `root_id`.

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

## Open gaps and future work

| Gap | Priority | Notes |
|-----|----------|-------|
| Messenger synchronous bus wiring | High | `config/packages/messenger.yaml` buses have empty middleware arrays. `RunOrchestrator` handlers are registered but messages may not be dispatched. Required for actual run persistence end-to-end. |
| File-backed CommandStore | Medium | `InMemoryCommandStore` loses pending commands on restart. Step IDs use `hrtime()` so duplicates are unlikely, but file backing would improve durability. |
| File-backed MessageIdempotencyService | Low | In-memory idempotency state is lost on restart. Not critical because step IDs are time-based and won't repeat. |
| Session listing (`listSessions()`) | Medium | Need `HatfieldSessionStore::listSessions()` + `HatfieldSessionStore::tree()` before building `/sessions` UI. |
| Session pruning/cleanup | Low | No auto-expiry or `session:prune` command. Orphaned sessions accumulate. |
| Fork command (`session:fork`) | Medium | Planned; storage model is ready. Needs rewrite logic + CLI command. |
| Attachments storage (`attachments/`) | Low | Directory created in layout docs but not yet used. Will store pasted files, images, diffs. |
| Session rename / alias | Low | Session IDs are 12-char hex — not human-memorable. Aliases or titles would help. |
| Runtime event streaming | Medium | Current polling is synchronous full-scan. Incremental delivery would improve large sessions. |

## Related documents

- [Hatfield Settings](settings.md) — configuring session path and theme
- [TUI Architecture](tui-architecture.md) — TUI widget layout and extension system
- [Architecture Rollout Plan](../.pi/plans/architecture_rollout_plan.md) — overall project architecture history
