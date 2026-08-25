# Session-storage file I/O audit

**Scope:** read-only assessment of the current Hatfield session store. No runtime data was changed. Measurements below are a 2026-08-25 snapshot of `/home/ineersa/projects/agent-core/.hatfield`; IDs are either counts or SHA-256 prefixes. This report deliberately excludes prompts, tool output, and event payloads.

**Evidence labels:** **Measured** = privacy-safe snapshot; **Source-derived** = current source/static call graph; **Historical** = the pre-fix session-45 observation recorded in the active task. File size/count values can change while Hatfield is running.

## I/O map: topology, ownership, and classification

`HatfieldSessionStore` resolves `.hatfield/sessions`. The five numeric top-level directories (`41`–`45`) are the **canonical parent-session candidates**: they match the five `hatfield_session` SQLite rows and contain parent `events.jsonl`/`state.json`. The other 214 top-level UUID directories contain only `idempotency.jsonl`; they exist because `JsonlIdempotencyStore::markHandled()` independently creates `sessions/<runId>/` for any run scope. They are not session transcripts. **Measured:** 205 are referenced by at least one DB record; nine have no present DB reference and are **orphan candidates**, not proven safe to delete.

| Location/class | Owner and operation | Classification |
|---|---|---|
| `sessions/<parent>/events.jsonl` | `SessionRunEventStore` / `JsonlRunEventLog`; canonical `RunEvent` append, replay, history/TUI reads | Canonical parent history |
| `sessions/<parent>/state.json` | `SessionRunStore`; current `RunState` read and CAS replacement | Derived hot state, rebuildable from events |
| `sessions/<parent>/sequence.cursor` | `FileRunSequenceAllocator`; next sequence high-water | Allocation state; **not** durable-tail truth |
| `sessions/<parent>/idempotency.jsonl` | `JsonlIdempotencyStore`; handled-key lookup/append | Durable idempotency index, append-only |
| `sessions/<parent>/prompt-cache.jsonl` | prompt-cache diagnostics/store | Derived diagnostic/cache data, not canonical replay |
| `sessions/<parent>/artifacts/agents/registry.json` | `AgentArtifactRegistry` | Parent-owned child-artifact index |
| `.../artifacts/agents/<artifact>/events.jsonl` | `AgentChildRunEventStore` | Canonical child history |
| same child directory: `state.json`, `sequence.cursor` | `AgentChildRunStore`, `FileRunSequenceAllocator` | Child hot state / allocation state |
| same child directory: `metadata.json`, `handoff.md`, lifetime/diagnostic files | artifact registry/lifecycle/handoff services | Child metadata and rendered handoff artifacts; derived from child lifecycle/history where applicable |
| session attachment/MCP catalog artifacts (when present) | attachment and MCP catalog services | Session-scoped derived/indexed artifacts; inventory only, no measured present-size breakdown |
| `.hatfield/state.sqlite` | Doctrine repositories/migrations | Durable relational state: session catalog, tool questions, deferred completions/batches/children, background-process records; not a transcript replacement |
| `.hatfield/tmp/bg/<id>/` (`*.log`, `*.pid`, `*.status`) | background-process subsystem | Adjacent process output/status; ephemeral/runtime retention policy is separate from session replay |
| `.hatfield/tmp/output-cap/` | output-cap subsystem | Adjacent capped-output artifacts |
| `.hatfield/logs/` | Monolog rotating handler | Adjacent operational logs |
| `.hatfield/cache/`, `.hatfield/tmp/`, `.hatfield/locks/`, Messenger SQLite files | framework/runtime services | Ephemeral cache, temporary, lock, and transport state; not canonical sessions |

The child artifact topology explains why a parent has many nested UUID directories: one artifact directory per child/fork/subagent run, colocated under its parent rather than promoted to `sessions/`. The **top-level** UUID directories are a different phenomenon: idempotency-only directories created by the per-run file index.

## Measured footprint and growth

| Scope | Count / bytes |
|---|---:|
| Entire `.hatfield` | 10,419 directories; 90,534 files; 1.40 GiB |
| `sessions/` | 219 top-level directories; 719.8 MiB |
| parent event logs | 5 files; 27,291 records; 102.7 MiB |
| child event logs | 229 files; 80,450 records; 267.1 MiB |
| all event logs | 234 files; 369.8 MiB; median 1.0 MiB; p95 2.6 MiB; max 41.1 MiB |
| `state.json` | 234 files; 211.7 MiB; median 789.9 KiB; p95 1.9 MiB; max 2.7 MiB |
| `prompt-cache.jsonl` | 234 files; 129.1 MiB; median 218.7 KiB; p95 2.1 MiB; max 7.2 MiB |
| `idempotency.jsonl` | 219 files; 2.8 MiB; median 9.2 KiB; p95 31.4 KiB; max 106.7 KiB |
| `metadata.json` / `handoff.md` | 235 each; 2.1 MiB / 1.9 MiB |
| cursors | 234 files; 920 B total |
| `.hatfield/tmp/bg` | 19,945 files; historical snapshot 281.4 MiB; current generic `*.log` aggregate is 329.1 MiB because it includes other log locations |
| `.hatfield/logs` | historical snapshot: 2 files; 50.5 MiB |
| `.hatfield/tmp/output-cap` | historical snapshot: 103 files; 44.8 MiB |

Largest anonymized parent `811786ad1a`: 10,326 events / 41.1 MiB, 67 child dirs and seven compactions. It has 5,998 `tool_execution_update` records, 538 tool executions, and 343 turn transitions; its largest turn bucket is 244 events / 1.8 MiB. A short representative `71ee45a3c0` has 772 events / 2.3 MiB, 1.2 MiB state, and four child dirs. Therefore per-turn growth is highly workload-shaped, not a constant: history/progress payloads dominate the observed large turn.

**Historical:** session 45 was earlier measured at roughly 43 MiB / 10.3k events; the current 41.1 MiB maximum is a later read-only snapshot. The difference is expected from copied/live artifacts and measurement timing, not evidence of compaction or pruning.

## Parent event/state path and replay semantics

`JsonlRunEventLog::appendMany()` takes the per-run Symfony lock, reserves a cursor block, appends normalized JSONL events, and invalidates the parent store's process-local snapshot after each physical write. `SessionRunEventStore::allFor()` does `file_get_contents()`, `explode("\n")`, decode/denormalize, sort, then caches decoded events by `(size,mtime)` only if the signature remained stable across the read. Any successful append invalidates that process-local cache. It does not share cache state across Messenger workers/controller/TUI processes; each process has its own cache miss/read behavior. A concurrent append is allowed to return an uncached read rather than lock/retry.

`firstFor()` streams from the head and `rangeFor()` streams until the first valid sequence above its end; `latestSequenceFor()` uses reverse-line reading. `SubagentRunMetadataReader::readRunStartedMetadata()` now uses `firstFor()` and a positive-only FIFO cache of 64 immutable `RunStartedMetadataDTO`s. Its four public methods (`isAgentChild`, `readParentRunId`, `readAllowedTools`, `readAllowedExtensions`) all funnel through that method. Within one process/run, the first successful lookup is one head scan and later calls are cache hits; cache misses/eviction and every other process independently scan again. Missing/malformed metadata is intentionally not cached.

Static multiplicity is not dynamic frequency. Current direct production `allFor()` invocations are: `AgentArtifactRetrievalService` (2), `AgentChildRunEventStore` (2 internal), `ChildAwareEventStore` (2 dispatch branches), `SessionPromptCacheInspectionService` (2), and one each in `InProcessAgentSessionClient`, `StreamingCommittedRuntimeEventStore` (decorator forwarding), `ChildRunTranscriptSnapshotProvider`, `HistorySelectionService`, `HistoryTailDiscardService`, `SessionRepairService`, `SessionHotPromptReplayService::verifyIntegrity`, `SessionRunStateReplayService` (two explicit replay paths), `SessionCatalogRecoveryService`, `SessionHistoryProvider`, `SessionTranscriptProvider`, and `Tui\\Application\\SessionInitializer`. This is static call-site multiplicity, not calls per turn. `SessionRunStateReplayService::rebuildIfStale()` first compares durable `latestSequenceFor()` with `state.lastSeq`, avoiding full replay when current; it calls `allFor()` only for stale recovery. `SessionHotPromptReplayService` rebuilds hot state from `RunState`; its `allFor()` remains in explicit `verifyIntegrity()`, not normal hot-prompt rebuild.

Static writer multiplicity is similarly structural: `SessionRunEventStore::append`/`appendMany` and child equivalents each delegate to one locked `JsonlRunEventLog::appendMany`; `SessionRunStore::compareAndSwap` is the sole parent-state writer; `FileRunSequenceAllocator::allocateBlock` is the cursor writer; `JsonlIdempotencyStore::markHandled` is the idempotency writer. Their caller frequency is not observable from this snapshot. Readers are these stores plus the full-history consumers listed above; `SessionRunStore::get` is used both directly and inside CAS/stale scanning.

`state.json` is read whole and denormalized by `SessionRunStore::get()`. Every successful CAS normalizes the full `RunState`, pretty-encodes it, and `Filesystem::dumpFile()` writes a temp file then renames it, so readers see old-or-complete-new state rather than truncate/write partial content. This is whole-document rewrite amplification proportional to state size on each committed state transition. Final `state.json` size proves the payload can be large; it does **not** reveal historical CAS/rewrite count.

## Sequence, locking, partial writes, and recovery

`FileRunSequenceAllocator` opens `sequence.cursor` with `c+b`, takes `LOCK_EX`, reads/bootstraps, truncates/writes/flushes the high-water, then releases. Cursor allocation occurs before JSONL append under the run lock. A crash in between leaves valid sequence holes; cursor is therefore unsuitable as a durable-tail freshness source. Parent event order is append order under lock; replay permits gaps. Child `readAfterSeq()` also locks and streams from its child file. Corrupt JSONL/state JSON throws; forward-incompatible event schema records are logged and skipped by event stores. **Measured:** no empty, malformed, or partial session JSONL/state files in this snapshot; that is not a proof of crash recovery under power loss.

## Idempotency and top-level UUID leakage

`JsonlIdempotencyStore::isHandled()` opens `idempotency.jsonl`, takes `LOCK_SH`, linearly scans for `scope|runId|key`, and closes. `markHandled()` creates the top-level run directory if absent and `file_put_contents(..., FILE_APPEND|LOCK_EX)`. Thus every lookup is O(number of retained lines), every successful mark grows permanently, and cross-process correctness is lock-based. There is no measured retention/pruning frequency. The 214 UUID-only directories are explained by this behavior; DB references make most legitimate, while nine currently unreferenced directories require ownership/lifetime evidence before classification as deletable.

## Child, fork, subagent, and live-view I/O

Child event/state/cursor/metadata/handoff files are stored at `sessions/<parent>/artifacts/agents/<artifact>`. `AgentChildRunEventStore::allFor()` streams and materializes/sorts the complete child log; `latestSequenceFor()` calls it, and `reverseFor()` calls it before reversing. `firstFor()`/`rangeFor()` stream. `readAfterSeq(cursor)` streams from offset zero under lock and filters `seq > cursor`, so recovery/poll re-entry may reread an entire child log; this is distinct from live transient pipe delivery. `/agents-live` and artifact retrieval use child event/state/registry paths; the snapshot cannot establish how often a live view falls back to zero-offset recovery.

Database counts show the related lifecycle population: `deferred_subagent_child=235`, `deferred_subagent_batch=183`, `deferred_tool_completion=177`, `tool_question=115`, and `background_process=6,696`. The parent/child distinction matters: 229 child logs account for most event bytes in this snapshot, while historic persisted parent progress (`tool_execution_update`) explains the largest parent replay cost. New process-mode nonterminal subagent progress is transient-only; historical session logs retain prior canonical records and were intentionally not pruned.

## Exact unknowns requiring instrumentation

Filesystem size/mtime and static call sites cannot tell: per-turn/per-launch `open`, `read`, `stat`, decode, cache-hit, or write counts; bytes read versus cached; CAS attempts/retries and actual `state.json` rewrite count; cross-process cache-miss multiplicity; lock wait/hold times; `/agents-live` fallback frequency; idempotency hit/miss/line-scan distribution; background retention/deletion cadence; or partial-write/recovery incidence. Existing structured log memory fields help correlate process memory, but no counters provide these I/O facts.

## Reusable read-only measurement tool

`tools/session-storage-audit.py` is the consolidated, dependency-free tool. It requires an explicit existing directory named `.hatfield`, uses SQLite URI `mode=ro`, reads JSON only to aggregate event types/counts, hashes displayed directory IDs, and never writes below the audited root.

```bash
python3 tools/session-storage-audit.py /absolute/path/to/.hatfield
```

Validated against `/home/ineersa/projects/agent-core/.hatfield`: it reproduced the 219 session directories, five numeric parent candidates, 214 UUID idempotency-only candidates, 234 event logs, 27,291 parent records, 80,450 child records, 234 state files, and the six SQLite table counts above. The live file total differed from the scout by six files (90,534 vs 90,528), consistent with concurrent runtime artifact creation; no audited files were modified.

## Decision questions / measurement gaps

1. Is the intended retention/lifetime owner for UUID idempotency-only directories and the nine unreferenced candidates known well enough to classify them, or must ownership be instrumented first?
2. Should a controlled replay/profile measure end-to-end `/resume` decode/projection time and peak memory for the large parent and representative child logs before ranking any change?
3. What is the acceptable state-transition rewrite budget, and should instrumentation separately count CAS attempts, successful rewrites, bytes, and lock wait time?
4. Does `/agents-live` materially invoke child zero-offset `readAfterSeq()`/`latestSequenceFor()` in real sessions, and at what child-log sizes?
5. What retention policy is desired for background logs/output-cap artifacts versus canonical session history?
6. Which exact I/O counters and privacy-safe aggregates are acceptable to add for a later measurement-only task? No optimization is proposed or implemented here.
