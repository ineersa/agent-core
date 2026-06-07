# Hatfield Session Storage

**One directory = one session = one agent run = one future fork tree node.**

## Goals and invariants

- **`session_id === run_id`.** The TUI session and the AgentCore run share a single
  identity. One DB-issued auto-increment ID (numeric string) names the session
  directory and is used as the AgentCore `RunState::$runId` and every
  `RunEvent::$runId`.
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
```

Session metadata (identity, prompt, model, reasoning, fork tree links)
lives in the `hatfield_session` database table, not in a metadata.yaml file.

### File purposes

| File | Canonical? | Written by | Read by | Format |
|------|-----------|------------|---------|--------|
| `state.json` | No — materialized snapshot/checkpoint | `SessionRunStore::compareAndSwap()` | `SessionRunStore::get()`, resume flow | JSON (Symfony Serializer) |
| `events.jsonl` | **Yes — canonical domain event stream** | `SessionRunEventStore::append()` | `SessionRunEventStore::allFor()`, `InProcessAgentSessionClient::events()`, TUI tick callback | JSONL (EventPayloadNormalizer) |

### Session metadata (database)

Session identity and metadata are stored in the `hatfield_session` DB table
(authoritative) and exposed through `HatfieldSessionStore::loadMetadata()`
and `updateMetadata()`.  The returned array shape for callers:

```php
[
    'session_id' => '42',    // DB auto-increment id as string; always === run_id
    'run_id'     => '42',
    'parent_id'  => null,    // Future fork tree parent
    'root_id'    => null,    // Future fork tree root
    'created_at' => '...',
    'updated_at' => '...',
    'cwd'        => '/path/to/project',
    'prompt'     => 'Write a README',  // nullable
    'model'      => 'deepseek/deepseek-v4-pro',  // nullable
    'model_provider' => 'deepseek',              // nullable
    'model_name'     => 'deepseek-v4-pro',       // nullable
    'reasoning'  => 'medium',                    // nullable
]
```

Non-null keys are always present; nullable fields are only included when
non-null. There is no separate public_id column — the auto-increment
integer primary key is cast to string for all external identifiers.

Future forking will add parent_id/root_id support (see [Future fork tree](#future-fork-tree)).

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
Current-state snapshot / checkpoint
┌──────────────────────────────────────────────────────────┐
│ state.json                                               │
│ serialized RunState used for fast resume + CAS version   │
│ operationally required today; not auto-rebuilt yet       │
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

Although `state.json` is conceptually derivable from the canonical event stream,
the current resume flow reads it directly. There is not yet an automatic
"rebuild state from `events.jsonl` when `state.json` is missing or corrupt"
fallback, so it should be treated as a required materialized snapshot today, not
as a disposable cache.

### events.jsonl

One JSON object per line, produced by `EventPayloadNormalizer::normalizeRunEvent()`:

```jsonl
{"schema_version":"1.0","run_id":"42","seq":1,"turn_no":1,"type":"run_started","payload":{"run_id":"42"},"ts":"2026-05-13T12:00:00+00:00"}
{"schema_version":"1.0","run_id":"42","seq":2,"turn_no":1,"type":"turn_start","payload":{},"ts":"2026-05-13T12:00:01+00:00"}
```

Lines are appended under a Symfony Lock (`FlockStore`). `allFor()` reads all
lines, validates embedded `run_id` against the directory name, and sorts by
`seq` before returning.

One JSON object per line, produced by `TranscriptEntry::toArray()`:

```jsonl
{"role":"user","text":"Write a README","meta":{"session_id":"42"},"created_at":"2026-05-13T12:00:05+00:00"}
{"role":"assistant","text":"I'll create a README.md","meta":{"run_id":"42","seq":4},"created_at":"2026-05-13T12:00:06+00:00"}
```

Roles include `user`, `assistant`, `tool`, `system`, and `error`.


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

 ──────────────────────────────▶│ • persist runtime  │  (plain model, no theme)
 (JsonlProcessAgentSessionClt)  │ • map to transcript│
                                └────────┬───────────┘
                                         │
                                         ▼
                                  TuiSessionState
                                  $state->transcript[]
                                         │
                                         ▼
                                  ChatScreen::appendTranscript()
                                         │
                                         ▼
                                  TranscriptWidget → live display
                                  (role prefixes + theme applied
                                   by TranscriptEntry::render())
```

Key: runtime events flow through `RuntimeEventPoller` which produces plain
`TranscriptEntry` objects. Theming and role-based display prefixes (❯ ◇ ●)
are applied at render time by `TranscriptEntry::render()` in the TUI widget
layer — not during persistence.

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
3. If metadata contains a `run_id`, `InteractiveMode::startOrResumeRun()` calls `AgentSessionClient::resume($runId)`.
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
| Session rename / alias | Low | Session IDs are auto-increment numeric strings — not human-memorable. Aliases or titles would help. |
| Runtime event streaming | Medium | Current polling is synchronous full-scan. Incremental delivery would improve large sessions. |
| Rebuild `state.json` from `events.jsonl` | Medium | Would make the materialized snapshot fully recoverable and justify treating it as a disposable cache. |
## Related documents

- [Hatfield Settings](settings.md) — configuring session path and theme
- [TUI Architecture](tui-architecture.md) — TUI widget layout and extension system
- [Architecture Rollout Plan](../.pi/plans/architecture_rollout_plan.md) — overall project architecture history
