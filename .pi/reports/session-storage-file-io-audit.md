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
| `sessions/<parent>/diagnostics/prompt-cache.jsonl` and `.../artifacts/agents/<artifact>/diagnostics/prompt-cache.jsonl` | prompt-cache diagnostics/store | Opt-in (`HATFIELD_WRITE_PROMPT_CACHE_DIAGNOSTICS=1`; default off) derived structural diagnostics, not provider cache or canonical replay; legacy files remain measurable/inert when disabled |
| `sessions/<parent>/artifacts/agents/registry.json` | `AgentArtifactRegistry` | Parent-owned child-artifact index |
| `.../artifacts/agents/<artifact>/events.jsonl` | `AgentChildRunEventStore` | Canonical child history |
| same child directory: `state.json`, `sequence.cursor` | `AgentChildRunStore`, `FileRunSequenceAllocator` | Child hot state / allocation state |
| same child directory: `metadata.json`, `handoff.md`, lifetime/diagnostic files | artifact registry/lifecycle/handoff services | Child metadata and rendered handoff artifacts; derived from child lifecycle/history where applicable |
| session attachment/MCP catalog artifacts (when present) | attachment and MCP catalog services | Session-scoped derived/indexed artifacts; inventory only, no measured present-size breakdown |
| `.hatfield/state.sqlite` | Doctrine repositories/migrations | Durable relational state: session catalog, tool questions, deferred completions/batches/children, background-process records; not a transcript replacement |
| `.hatfield/tmp/bg/<prefix>.{log,pid,status}` | background-process subsystem | Exact flat per-record sidecars for process output/status; ephemeral/runtime retention policy is separate from session replay |
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
| `prompt-cache.jsonl` diagnostics | 234 legacy files; 129.1 MiB; median 218.7 KiB; p95 2.1 MiB; max 7.2 MiB; new runs do not write these by default |
| `idempotency.jsonl` | 219 files; 2.8 MiB; median 9.2 KiB; p95 31.4 KiB; max 106.7 KiB |
| `metadata.json` / `handoff.md` | 235 each; 2.1 MiB / 1.9 MiB |
| cursors | 234 files; 920 B total |
| `.hatfield/tmp/bg` | 19,945 files; historical snapshot 281.4 MiB; current generic `*.log` aggregate is 329.1 MiB because it includes other log locations |
| `.hatfield/logs` | historical snapshot: 2 files; 50.5 MiB |
| `.hatfield/tmp/output-cap` | historical snapshot: 103 files; 44.8 MiB |

### Background-process provisional cleanup

`BackgroundProcessManager::start()` creates a private foreground-supervision row and exact `.pid` / `.status` / `.log` sidecars before a Bash background prompt can be accepted. `backgrounded_at IS NULL` is therefore not user-visible background work: `bg_status` lists, tails, and stops only rows that were accepted (`backgrounded_at IS NOT NULL`) within its current run. A Symfony Scheduler task runs every five minutes, refreshes unfinished records, and removes only finished private rows whose `finished_at` is at least one interval old, plus their exact row-owned sidecars. The grace interval preserves BashTool's foreground output read. Accepted rows are instead removed at controller shutdown, with the same exact-sidecar cleanup repeated at controller startup to repair crash leftovers.

Largest anonymized parent `811786ad1a`: 10,326 events / 41.1 MiB, 67 child dirs and seven compactions. It has 5,998 `tool_execution_update` records, 538 tool executions, and 343 turn transitions; its largest turn bucket is 244 events / 1.8 MiB. A short representative `71ee45a3c0` has 772 events / 2.3 MiB, 1.2 MiB state, and four child dirs. Therefore per-turn growth is highly workload-shaped, not a constant: history/progress payloads dominate the observed large turn.

**Historical:** session 45 was earlier measured at roughly 43 MiB / 10.3k events; the current 41.1 MiB maximum is a later read-only snapshot. The difference is expected from copied/live artifacts and measurement timing, not evidence of compaction or pruning.

## Event-log composition and bug-adjusted footprint

A second privacy-safe pass classified all 107,741 encoded JSONL records by event type without printing IDs or payload content. It found 387,713,189 bytes (369.8 MiB): 107,670,759 parent bytes and 280,042,430 child bytes, with zero malformed/non-object records.

| Event type | Records | Encoded bytes | Share | Parent bytes | Child bytes |
|---|---:|---:|---:|---:|---:|
| `message_end` | 12,790 | 169,719,375 | 43.77% | 20,924,188 | 148,795,187 |
| `tool_execution_update` | 13,489 | 61,664,966 | 15.90% | 61,664,966 | 0 |
| `tool_execution_end` | 12,790 | 55,847,066 | 14.40% | 7,349,910 | 48,497,156 |
| `run_started` | 234 | 43,359,918 | 11.18% | 218,109 | 43,141,809 |
| `llm_step_completed` | 7,143 | 30,579,599 | 7.89% | 4,914,892 | 25,664,707 |
| `context_compacted` | 23 | 9,805,889 | 2.53% | 9,805,889 | 0 |
| all remaining types | — | 16,736,376 | 4.32% | — | — |

The known progress-persistence bug is structurally identifiable without inspecting content: `tool_execution_update` records whose `payload.subagent_progress.status` is `running`. **Measured:** 13,290 such records / 60,828,487 bytes across five parent logs, and none in child logs. Current source emits nonterminal process-mode progress transiently and retains only terminal snapshots canonically.

The exact counterfactual after excluding only those confidently bug-generated records is:

- all logs: 94,451 records / 326,884,702 bytes (311.7 MiB), retaining 84.31% of the original bytes;
- parent logs: 14,001 records / 46,842,272 bytes (44.7 MiB), down from 102.7 MiB;
- child logs: unchanged at 80,450 records / 280,042,430 bytes (267.1 MiB).

A broader sensitivity calculation removing every `tool_execution_update`, including terminal/other update-shaped records, yields 94,252 records / 326,048,223 bytes; it differs by only 836,479 bytes but is not the correctness-preserving bug-only attribution.

The critical conclusion is that the historical transient-progress bug explains most excess **parent** bytes but does not explain the child footprint. After confident bug removal, `message_end` alone is 51.92% of retained bytes. Child logs remain dominated by canonical `message_end` (148,795,187 bytes), `tool_execution_end` (48,497,156), `run_started` (43,141,809), and `llm_step_completed` (25,664,707). Any child-space reduction therefore requires a separate schema/payload-retention analysis; deleting event types merely because they are large is not authorized.

### Child payload attribution

A third privacy-safe pass attributed nested field sizes without printing any payload values. Across 11,117 child tool results, the same logical result is retained at least three times: `tool_execution_end.payload.result` totals 44,573,403 bytes; `message_end.payload.message.content` totals 44,916,502 bytes; and `message_end.payload.message.details` totals 96,413,545 bytes, including nested result content of about 44.9 MiB and nested raw details of about 45.7 MiB. This is measured duplication, not an inference from event-type size.

Across all 229 child `run_started` lines, encoded records total 43,141,809 bytes. Their initial `messages` field is 39,947,463 bytes (92.6%); `system_prompt` is 2,017,805 bytes; `metadata` is 404,010 bytes; and the largest one line is 668,474 bytes. Any startup-payload reduction must preserve the exact launch/resume context rather than optimizing the comparatively small prompt while retaining the duplicated initial messages.

Canonical child payload ownership and deduplication are deliberately deferred to the ASAP follow-up `TODO/storage-optimize-child-canonical-event-payloads.md`. That task must establish one authoritative durable representation and prove replay/projection equivalence; this audit task does not change event payload schemas.

## Active event-log read paths and intended invariant

The desired invariant is achievable: canonical parent/child `events.jsonl` should be append-only persistence during normal execution and should be fully read only for resume/recovery, repair, history selection, explicit inspect/export, or the first snapshot when entering a child live view. Process-mode live-child polling already follows this design: `ChildRunTranscriptSnapshotProvider` performs one initial child replay, while `SubagentLiveChildViewPoller` consumes runtime-pipe events afterward rather than polling the canonical child file.

Remaining source-derived violations and bounded reads are:

| Path | Current active behavior | Assessment |
|---|---|---|
| `ContextBudgetReminderHookSubscriber` → child `reverseFor()` | `AgentChildRunEventStore::reverseFor()` now consumes `JsonlRunEventLog::reverseLines()` and decodes/yields newest durable events lazily | Resolved on this branch; an early consumer does not decode older records. `reverseLines()` reads backward in chunks, so this is not a claim that no prefix bytes can enter an I/O chunk. |
| `SessionRunStateReplayService::rebuildIfStale()` → child `latestSequenceFor()` | Child `latestSequenceFor()` now returns the first valid lazy reverse event instead of calling `allFor()` | Resolved on this branch; durable JSONL tail is authoritative and `sequence.cursor` remains unsuitable. |
| `InProcessAgentSessionClient::events(runId, afterSeq)` | Reverse-iterates `EventStoreInterface::reverseFor()`, stops at the observer-owned durable watermark, then restores chronological append order | Resolved on this branch. `RuntimeEventEmitter`, `RuntimeEventPoller`, and `SubagentLiveChildViewPoller` pass their last successfully applied/forwarded canonical sequence; transients remain unfiltered and first. |
| `SubagentRunMetadataReader` / tool-set resolution | Uses head-streaming `firstFor()` and a 64-entry positive cache | Already bounded; no full replay needed |
| parent compaction/context-budget lookups | Use `reverseFor()` and normally stop near the newest matching event | Bounded parent behavior already improved; retain historical semantics |
| live-child entry | One child `allFor()` snapshot, then runtime events | Appropriate, provided snapshot sequence watermark and later events deduplicate |
| resume, history mutation/selection, repair, catalog recovery, explicit diagnostics/export | Full replay by design | Retain; these are not active steady-state reads |

The completed concrete-store optimizations use the existing durable reverse-line reader without a cache/index/sidecar: child `latestSequenceFor()`/`reverseFor()` read newest-first lazily, and child `readAfterSeq(cursor)` reads newest tail records until the first valid `seq <= cursor`, returning the collected suffix in chronological order. In-process polling likewise stops at its observer-owned `afterSeq` watermark instead of calling `allFor()`. The watermark advances only after the controller/TUI observer successfully forwards/applies canonical events, so a failed observer retry asks the client for the same suffix again; no client-owned cursor can advance before delivery. Keep canonical full reads at the explicit lifecycle boundaries above. `sequence.cursor` remains unusable as durable-tail truth because allocation can precede a failed append and leave valid gaps.

Static call graphs cannot quantify production opens, bytes read, decode time, cache-hit rate, or cross-process amplification. Deterministic bounded-read fixtures now provide before/after evidence without recording run IDs or payloads: an in-memory 2,000-record in-process history at `afterSeq=1997` returns exactly three chronological visible events; the old `allFor()` path would materialize/decode all 2,000 records, while the new reverse path yields/inspects four records (three unseen plus the decoded cursor boundary) and makes zero `allFor()` calls. A child JSONL fixture has 2,000 normalized records, an intentionally malformed older marker after more than 256 KiB, and a cursor at 1997; the boundary-through-EOF span is at most the existing 8,192-byte reverse-reader chunk. The bounded read decodes four valid tail records, returns three chronological events, and never reaches the old malformed marker; the former byte-zero forward scan would traverse more than 256 KiB and decode 1,000 valid records before failing at that marker. Dynamic production frequency, cache, and physical-I/O measurements remain deferred: a later measurement pass should count method, parent/child class, operation context, bytes physically read, cache hit/miss, decoded records, and duration without recording run IDs or payloads. Optimization priority should be validated against those counters rather than inferred from file size alone.

## Parent event/state path and replay semantics

`JsonlRunEventLog::appendMany()` takes the per-run Symfony lock, reserves a cursor block, appends normalized JSONL events, and invalidates the parent store's process-local snapshot after each physical write. `SessionRunEventStore::allFor()` does `file_get_contents()`, `explode("\n")`, decode/denormalize, sort, then caches decoded events by `(size,mtime)` only if the signature remained stable across the read. Any successful append invalidates that process-local cache. It does not share cache state across Messenger workers/controller/TUI processes; each process has its own cache miss/read behavior. A concurrent append is allowed to return an uncached read rather than lock/retry.

`firstFor()` streams from the head and `rangeFor()` streams until the first valid sequence above its end; `latestSequenceFor()` uses reverse-line reading. `SubagentRunMetadataReader::readRunStartedMetadata()` now uses `firstFor()` and a positive-only FIFO cache of 64 immutable `RunStartedMetadataDTO`s. Its four public methods (`isAgentChild`, `readParentRunId`, `readAllowedTools`, `readAllowedExtensions`) all funnel through that method. Within one process/run, the first successful lookup is one head scan and later calls are cache hits; cache misses/eviction and every other process independently scan again. Missing/malformed metadata is intentionally not cached.

Static multiplicity is not dynamic frequency. Current direct production `allFor()` invocations are: `AgentArtifactRetrievalService` (2), `ChildAwareEventStore` (2 dispatch branches), `SessionPromptCacheInspectionService` (2), and one each in `InProcessAgentSessionClient`, `StreamingCommittedRuntimeEventStore` (decorator forwarding), `ChildRunTranscriptSnapshotProvider`, `HistorySelectionService`, `HistoryTailDiscardService`, `SessionRepairService`, `SessionHotPromptReplayService::verifyIntegrity`, `SessionRunStateReplayService` (two explicit replay paths), `SessionCatalogRecoveryService`, `SessionHistoryProvider`, `SessionTranscriptProvider`, and `Tui\\Application\\SessionInitializer`. `AgentChildRunEventStore::latestSequenceFor()` and `reverseFor()` are no longer internal `allFor()` callers. This is static call-site multiplicity, not calls per turn. `SessionRunStateReplayService::rebuildIfStale()` first compares durable `latestSequenceFor()` with `state.lastSeq`, avoiding full replay when current; it calls `allFor()` only for stale recovery. `SessionHotPromptReplayService` rebuilds hot state from `RunState`; its `allFor()` remains in explicit `verifyIntegrity()`, not normal hot-prompt rebuild.

Static writer multiplicity is similarly structural: `SessionRunEventStore::append`/`appendMany` and child equivalents each delegate to one locked `JsonlRunEventLog::appendMany`; `SessionRunStore::compareAndSwap` is the sole parent-state writer; `FileRunSequenceAllocator::allocateBlock` is the cursor writer; `JsonlIdempotencyStore::markHandled` is the idempotency writer. Their caller frequency is not observable from this snapshot. Readers are these stores plus the full-history consumers listed above; `SessionRunStore::get` is used both directly and inside CAS/stale scanning.

`state.json` is read whole and denormalized by `SessionRunStore::get()`. Every successful CAS normalizes the full `RunState`, pretty-encodes it, and `Filesystem::dumpFile()` writes a temp file then renames it, so readers see old-or-complete-new state rather than truncate/write partial content. This is whole-document rewrite amplification proportional to state size on each committed state transition. Final `state.json` size proves the payload can be large; it does **not** reveal historical CAS/rewrite count.

## Sequence, locking, partial writes, and recovery

`FileRunSequenceAllocator` opens `sequence.cursor` with `c+b`, takes `LOCK_EX`, reads/bootstraps, truncates/writes/flushes the high-water, then releases. Cursor allocation occurs before JSONL append under the run lock. A crash in between leaves valid sequence holes; cursor is therefore unsuitable as a durable-tail freshness source. Parent event order is append order under lock; replay permits gaps. Child `readAfterSeq()` also locks and streams from its child file. Corrupt JSONL/state JSON throws; forward-incompatible event schema records are logged and skipped by event stores. **Measured:** no empty, malformed, or partial session JSONL/state files in this snapshot; that is not a proof of crash recovery under power loss.

## Idempotency and top-level UUID leakage

`JsonlIdempotencyStore::isHandled()` opens `idempotency.jsonl`, takes `LOCK_SH`, linearly scans for `scope|runId|key`, and closes. `markHandled()` creates the top-level run directory if absent and `file_put_contents(..., FILE_APPEND|LOCK_EX)`. Thus every lookup is O(number of retained lines), every successful mark grows permanently, and cross-process correctness is lock-based. There is no measured retention/pruning frequency. The 214 UUID-only directories are explained by this behavior; DB references make most legitimate, while nine currently unreferenced directories require ownership/lifetime evidence before classification as deletable.

## Child, fork, subagent, and live-view I/O

Child event/state/cursor/metadata/handoff files are stored at `sessions/<parent>/artifacts/agents/<artifact>`. `AgentChildRunEventStore::allFor()` still streams, materializes, and sorts the complete child log for explicit full-history consumers. On this branch, `latestSequenceFor()` obtains the first valid event from `reverseFor()`, and `reverseFor()` lazily decodes lines supplied by `JsonlRunEventLog::reverseLines()` in newest-first durable order; both preserve incompatible-schema skipping, malformed-line failure once reached, and child run-ID integrity checks. The reverse reader reads the file backward in fixed chunks, so early termination avoids decoding older records but does not guarantee that the operating system never reads bytes before the returned line. `firstFor()`/`rangeFor()` stream. `readAfterSeq(cursor)` now holds the existing child lock while it reverse-reads durable JSONL lines, collects only valid events with `seq > cursor`, stops at the first valid older/equal record, then restores chronological order. It intentionally does not decode malformed prefixes older than that stop record; malformed unseen tail records and child run-ID mismatches still fail loudly, and incompatible schema records remain skipped. `/agents-live` and artifact retrieval use child event/state/registry paths; the snapshot cannot establish how often a live view invokes recovery.

Database counts show the related lifecycle population: `deferred_subagent_child=235`, `deferred_subagent_batch=183`, `deferred_tool_completion=177`, `tool_question=115`, and `background_process=6,696`. The parent/child distinction matters: 229 child logs account for most event bytes in this snapshot, while historic persisted parent progress (`tool_execution_update`) explains the largest parent replay cost. New process-mode nonterminal subagent progress is transient-only; historical session logs retain prior canonical records and were intentionally not pruned.

## Exact unknowns requiring instrumentation

Filesystem size/mtime and static call sites cannot tell: per-turn/per-launch `open`, `read`, `stat`, decode, cache-hit, or write counts; bytes read versus cached; CAS attempts/retries and actual `state.json` rewrite count; cross-process cache-miss multiplicity; lock wait/hold times; `/agents-live` fallback frequency; idempotency hit/miss/line-scan distribution; background retention/deletion cadence; or partial-write/recovery incidence. Existing structured log memory fields help correlate process memory, but no counters provide these I/O facts.

## Reusable read-only measurement tool

`~/.hatfield/tools/session-storage-audit.py` is an untracked local, dependency-free utility. It requires an explicit existing directory named `.hatfield`, uses SQLite URI `mode=ro`, reads JSON only to aggregate event types/counts, hashes displayed directory IDs, and never writes below the audited root. It continues to measure legacy `prompt-cache.jsonl` files; those files are opt-in structural diagnostics (`HATFIELD_WRITE_PROMPT_CACHE_DIAGNOSTICS=1`, default off), not provider cache or replay state.

```bash
python3 ~/.hatfield/tools/session-storage-audit.py /absolute/path/to/.hatfield
```

Validated against `/home/ineersa/projects/agent-core/.hatfield`: it reproduced the 219 session directories, five numeric parent candidates, 214 UUID idempotency-only candidates, 234 event logs, 27,291 parent records, 80,450 child records, 234 state files, and the six SQLite table counts above. The live file total differed from the scout by six files (90,534 vs 90,528), consistent with concurrent runtime artifact creation; no audited files were modified.

## Decision questions / measurement gaps

1. Is the intended retention/lifetime owner for UUID idempotency-only directories and the nine unreferenced candidates known well enough to classify them, or must ownership be instrumented first?
2. Should a controlled replay/profile measure end-to-end `/resume` decode/projection time and peak memory for the large parent and representative child logs before ranking any change?
3. What is the acceptable state-transition rewrite budget, and should instrumentation separately count CAS attempts, successful rewrites, bytes, and lock wait time?
4. Does `/agents-live` materially invoke child zero-offset `readAfterSeq()`/`latestSequenceFor()` in real sessions, and at what child-log sizes?
5. What retention policy is desired for background logs/output-cap artifacts versus canonical session history?
6. Which exact I/O counters and privacy-safe aggregates are acceptable to add for a later measurement-only task? No optimization is proposed or implemented here.
