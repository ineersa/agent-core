# Database queries: where we need fresh data

[Architecture index](README.md)

**Decision: refresh mutable entities when reading them or before changing them. Do not clear the entire EntityManager before every query.**

The tables record decisions against source at `0ee03b085`, inspected on 2026-09-16. The Refresh decisions were implemented in `0ad6c26fb` and `1a4ae2c41`. Column notes saying "currently" or "today" describe the audited baseline, not the implementation. Container tests simulate external writes through DBAL while Doctrine retains cached entities. No query-cost benchmark was run.

Model-selection fixes followed in `f29026b19` and `b59cc976c`. Commit `69248bfb3` removed `DeferredSubagentChildRepository::findEntityByChildRunId`; its replacement is `findByChildRunId`. The background-process section describes the current scalar snapshot and metadata-owner boundary, which supersedes its original ORM refresh decisions.

## How to read the tables

- **Refresh** means get current database values before using an entity that Doctrine may have cached. For an update, do this **before assigning new values**, never afterwards.
- **No refresh** means the operation does not need the entity's current fields, or already reads SQL values without cached entities.
- **Already fresh** means the current code clears or refreshes before reading. Do not add another refresh.
- **Check first** marks a decision that needs more evidence.

For a list, “refresh” does not mean adding one extra query per row. A query that replaces cached field values can load the list in one operation. The table specifies which values must be fresh, not a particular implementation.

## 1. Session model, reasoning, and session list

Source: [HatfieldSessionStore](../src/CodingAgent/Session/HatfieldSessionStore.php) and [HatfieldSessionRepository](../src/CodingAgent/Entity/HatfieldSessionRepository.php).

Used by the TUI, runtime startup, and model selection in workers. Both the TUI and runtime write these rows.

| Method | Database work | Decision | Why / current gap |
|---|---|---|---|
| `findSession` | SELECT one session through `find()` | **Refresh** before returning its fields | Can otherwise return the model cached before another process changed it. Currently no refresh on main. |
| `updateMetadata` | SELECT, assign fields, UPDATE | **Refresh** before assignments | Doctrine can skip a requested update if the selected value equals its stale cached value. Currently no refresh. |
| `listSessions` → `findForCatalog` | SELECT session list | **Refresh** returned field values | The query can return up-to-date row IDs but old model/name fields from cached entities. Currently no refresh. |
| `exists` | SELECT through `find()` | **Fresh existence query**, not entity refresh | A cached object can survive deletion by another process. Only existence is needed, not all fields. |
| `createSession` | INSERT new row | **No refresh** | No existing entity to refresh. Keep it managed until insertion completes. |
| `deleteSession` | Find row, DELETE | **No field refresh** for deletion | Deleting by ID does not need the latest model/reasoning. Missing-row handling needs a real existence check, not trust in a cached object. |

**Example of the update bug:** TUI remembers model A, another process writes B, user selects A. Assigning A to the already-A cached object can produce no SQL UPDATE. The screen shows A while the database keeps B.

The footer branch adds refresh to the shared point-lookup helper. That covers model reads and writes, but **not the session-list query**. It also refreshes callers that only need existence/deletion, where a field refresh is unnecessary.

## 2. Background processes

Sources: [ProcessStore](../src/CodingAgent/Tool/BackgroundProcess/ProcessStore.php), [BackgroundProcessManager](../src/CodingAgent/Tool/BackgroundProcessManager.php) and [BackgroundCompletionHandler](../src/CodingAgent/Application/Pipeline/BackgroundCompletionHandler.php).

Runtime process reads use DBAL rows hydrated into immutable snapshots, not managed entity aliases. `run_control` owns registration, background acceptance, stop/timeout decisions, settlement and deletion. Serialized in-process execution and exclusive startup cleanup use the same owner-local decisions directly.

| Method or operation | Database work | Freshness boundary | Invariant |
|---|---|---|---|
| `snapshot`, `latestSnapshot`, `acceptedRecordId` | SELECT by immutable record ID or PID | **Scalar SQL read** | PID lookup selects a record; later owner decisions recheck record and run identity. |
| `fetchForegroundTools`, `backgroundSnapshots`, `unfinishedSnapshots`, `completionSnapshots`, `fetchFinishedProvisionalBefore` | SELECT candidate rows | **Scalar SQL read** | Candidates are observations, not permission to mutate or signal. |
| `existsByPid`, `existsByRecordId` | SELECT existence | **No entity refresh** | Existence does not depend on the identity map. |
| `insertRecord` | ORM INSERT during registration | **Owner transaction** | Registration commits the process and its stable receipt atomically. No managed record crosses a worker wait. |
| `requestBackground`, `acceptLocal`, `decide` | Conditioned DBAL reads and updates | **Owner decision** | Stop, timeout, settlement and deletion recheck current identity and state rather than writing back a candidate snapshot. |
| `BackgroundCompletionHandler` → `markCompletionNotified` | Recheck authority, admit retained receipt, update notification marker | **Owner admission** | Enqueuing a notification candidate is not acknowledgement. Explicit-stop suppression and duplicate delivery use current owner state. |

Native launch, output reads, signalling and cooperative grace waits remain in the manager/controller paths, outside metadata transactions. The scheduler's passive refresh never signals. Immutable record/run identity and exact status-sidecar evidence protect against PID reuse. Stop and cleanup retain the sidecar lock through their owner decision and native action; those owner operations do not acquire that lock, avoiding a circular wait. Session shutdown keeps the owner available until cleanup mutations finish. Accepted background jobs survive worker exit, not session shutdown or restart.

There is no process-entity refresh or shared `flush` step in runtime supervision. Do not add per-message EntityManager clears beneath overlapping Fibers.

## 3. Run status and parent/child identity

Source: [RunOperationalProjectionRepository](../src/CodingAgent/Repository/RunOperationalProjectionRepository.php) and [RunRelationshipReader](../src/CodingAgent/Repository/RunRelationshipReader.php).

Run-control writes this projection. Workers read it for cancellation and child-run checks.

| Method | Database work | Decision | Why / current behavior |
|---|---|---|---|
| `findOperationalStatus` | SELECT, refresh, return status | **Already fresh** | Explicit refresh lets a running LLM worker see cancellation. Keep it. |
| `replace` | SELECT existing graph, INSERT/UPDATE from canonical run state | **No blanket refresh/clear** | Run-control owns these writes and supplies the replacement state. Keep objects managed until the transaction finishes. |
| `deleteForOwnerSession` | SELECT matching rows, DELETE | **No field refresh** | Deletes query results by identity, not mutable status. |
| `isAgentChild`, `readParentRunId`, `requireKnownTopLevel` → private `requireKnown` | SELECT parent identity through `find()` | **Check first** | Parent identity is normally fixed, so caching it can be fine. The methods also promise the row exists; cached objects can outlive deletion. Confirm that lifetime before declaring caching safe. |

## 4. Deferred tool completion

Source: [DeferredToolCompletionRepository](../src/CodingAgent/Entity/DeferredToolCompletionRepository.php).

Tool and run-control paths coordinate a tool result that arrives later.

| Method | Database work | Decision | Why / current gap |
|---|---|---|---|
| `findPendingByRunAndToolCall`, `status` | ORM SELECT, read completion status | **Refresh** | A different process can complete the tool. Current reads can return cached pending status. |
| `findByDeferredId` | ORM SELECT, return correlation data | **No status refresh required** | Returns registration data, not completion status. This is safe while the registration is immutable and the row remains present. |
| `registerPending`, private `resolveRegistrationConflict` | Read existing registration, or SQL INSERT and reload | **No refresh for immutable registration fields** | Database uniqueness handles duplicate registration. INSERT itself needs no refresh and does not stale an existing row's fields. |
| `markCompleted`, successful SQL path | Conditional SQL UPDATE, then detach matching cached entity | **No refresh before SQL**; keep targeted detach afterwards | SQL tests the actual stored status. Detach stops this process reusing the old cached status. |
| `markCompleted`, zero-row SQL fallback | ORM SELECT, check status, possibly UPDATE | **Refresh** before fallback decision | The cached entity may still say pending after another process completed it. Review this fallback separately; don't add a global clear. |

## 5. Deferred subagent batches

Source: [DeferredSubagentBatchRepository](../src/CodingAgent/Entity/DeferredSubagentBatchRepository.php).

Launch, progress, interruption, and child-lifecycle processing can update these rows from different workers.

| Method | Database work | Decision | Why / current gap |
|---|---|---|---|
| `findByParentRunAndToolCall`, `findEntityByLifecycleId`, `findByLifecycleId` | ORM SELECT one batch | **Refresh** before using mutable fields | Launch/progress/status may have changed. No refresh today. |
| `findUnfinishedByParentRunId` | ORM SELECT list | **Refresh** returned field values | Converting cached objects into result objects does not make their contents fresh. |
| `markDeliveredProgressRevision`, `markTerminalCompletionEnqueued` | Read batch, check marker, ORM UPDATE | **Refresh** before checking/assigning | Avoid decisions based on old progress or completion markers. Keep existing version checks. |
| `persistInterruptionIntent`, `markInterruptionProgressEnqueued` | Read/version check, ORM UPDATE | **Refresh** before checking/assigning | The stored version and interruption state can change elsewhere. Keep optimistic locking. |
| `reserveBatch` | Read existing reservation, SQL INSERT batch/children | **Refresh existing mutable state**; retain clear after completed transaction | Existing-batch reuse returns state to callers. New inserts need no refresh. Current method already clears after reservation. |
| `applyBatchChildLifecycleProjection` | Version-checked SQL UPDATEs | **No refresh before SQL**; retain clear after completed transaction | SQL rejects wrong versions. Clearing afterwards removes cached values made stale by these writes. |
| `applyLaunchFailurePreparation` | Conditional SQL UPDATEs | **No refresh before SQL**; retain clear after completed transaction | SQL checks current launch status. |
| `applyLaunchSuccessState` | SQL UPDATEs and scalar SQL status lookup | **No refresh before SQL**; retain clear after completed transaction | Reads and conditions operate on stored SQL values, not cached entities. |
| `applyLaunchFailureRuntime` | SQL UPDATE, ORM child list, more SQL UPDATEs | **No extra refresh for its index-only use**; retain clear after completed transaction | It uses child indices; SQL conditions check current status. The general child-list method still needs fresh fields for its other callers. |

These clears already exist. Keeping them at transaction completion does **not** authorize adding clears halfway through other operations. Refresh also does not replace SQL version/status checks.

## 6. Deferred subagent children

Source: [DeferredSubagentChildRepository](../src/CodingAgent/Entity/DeferredSubagentChildRepository.php).

| Method | Database work | Decision | Why / current gap |
|---|---|---|---|
| `findByChildRunId`, `findEntityByChildRunId`, `findEntityByBatchLifecycleAndIndex` | ORM SELECT one child | **Refresh** before using mutable fields | Status, event cursor, and batch assignment can change, including on resume. |
| `findOrderedByBatchLifecycleId` | ORM SELECT child list | **Refresh** returned field values | Callers needing progress/status must not get old cached fields. |
| `insertReservedChildren` | SQL INSERT; ORM lookup on duplicate conflict | **No refresh on insert**; **refresh conflict reads** | A child may have been rebound to a different batch. The outer reservation operation owns the final clear. |
| `rebindExistingChildToResumeBatch` | Read old cursor/projection, SQL UPDATE or INSERT | **Refresh before reading old values**; discard the affected cached child after SQL | Otherwise resume can preserve an old cursor. Verify every caller owns the final clear; do not clear unrelated entities inside this helper. |

`assertChildMatchesIntent` and `decodeChildLifecycleProjection` do not query the database. Their input must already have the freshness required by the caller.

## 7. Queries that do not use cached ORM entities

| Database use | Source | Decision |
|---|---|---|
| Shared command cache reads/writes | [CacheCommandStore](../src/AgentCore/Infrastructure/Storage/CacheCommandStore.php) | **No EM refresh/clear.** Uses the Symfony cache backend and per-run locks. Cache behavior is a separate concern. |
| Messenger queue SELECT/INSERT/UPDATE/DELETE | [Transport configuration](../config/packages/messenger.yaml) | **No EM refresh/clear.** Uses a separate DBAL connection and queue operations. |
| Observations and memory generations | [ObservationRepository](../.hatfield/extensions/observational-memory/src/Storage/ObservationRepository.php), [MemoryGenerationRepository](../.hatfield/extensions/observational-memory/src/Storage/MemoryGenerationRepository.php) | **No EM refresh/clear.** Direct SQL returns values, not managed entities. |
| OM background-status polling | [OmBackgroundStatusPoller](../.hatfield/extensions/observational-memory/src/Tui/OmBackgroundStatusPoller.php) | **No EM refresh/clear.** Reuses a DBAL connection, but not an ORM entity cache. |

Event logs and session snapshot files are not database queries and are outside this map.

## What to change first

1. **Session reads/writes and session list:** require fresh fields at the operations marked above. Do not add refresh to every method just because they share a helper.
2. **Model selection:** route picker, `/model`, and Ctrl+P through one operation that also updates the pending draft selection. This is a separate bug; database refresh cannot fix it.
3. **Deferred status reads:** address the historical rows marked Refresh. Background-process reads now use the snapshot boundary above. Keep existing SQL concurrency checks.
4. **Global clears:** audit helper callers before changing them. Never clear while another operation has unsaved changes.

Shared Fiber execution workers do not clear or reset services between messages. Their process reads use scalar snapshots and their metadata writes go to the owner. Other lifecycle boundaries do not make cached entities fresh during a long-running operation.

Open checks: deletion/lifetime assumptions marked above, callers that retain objects across a clear, actual compiled worker reset wiring, and query cost. This map makes no blanket claim that every SQLite query needs refresh.
