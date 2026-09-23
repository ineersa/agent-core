# Observational Memory (OM) extension

Extension-owned observational memory storage, asynchronous Observer pipeline,
threshold Reflector + Dropper generations, and CompactRun replacement summaries via
instant durable-memory projection.

## Architecture (OM-03 + OM-04 + OM-05)

Hatfield provides a **generic** async extension-agent job facility. OM uses the
existing single FIFO `extension_agent` transport/worker for Observer/Reflector/Dropper
model work. Compaction does **not** wait on that worker.

```text
AfterTurnCommit (any run_control/llm/tool worker)
  → ObserveBoundaryTerminalHook (hot batch only)
  → ExtensionApi::dispatchExtensionAgentJob(scalar payload)
  → Symfony Messenger transport `extension_agent`  (async Doctrine DSN required)
  → dedicated Hatfield messenger:consume extension_agent worker
      → ExtensionLoaderSubscriber loads extensions
      → ExtensionAgentJobWorker resolves handler by stable ID
      → ObserveBoundaryJobHandler / ObserverPipeline
          → open/migrate om.sqlite (per-job path-local DBAL connection)
          → SessionEventReader::readRange (async path)
          → deterministic configured context-window chunk/part packer
          → $api->agent()->run(... record_observations, maxToolCalls=6 ...)
          → transactionally persist observations + coverage parts
          → optional threshold dispatch observational_memory.reflect_generation

Threshold (after all observe chunks durable, tokens > 40000)
  → ReflectGenerationJobHandler
      → claim generation by exact threshold-generation-v1 id
      → delta Reflector (new reflections only, maxToolCalls=16, shared model)
      → if >=1 new reflection AND active observation pool > observations_max_tokens:
            bounded Dropper (propose ids, server ranks+caps, maxToolCalls=16)
      → promote om_active_generation once: prior+new reflections, active-minus-drops

CompactRun (run_control, under run lock) — Pi-style instant projection
  → public OmBeforeCompactionHook (paired coverage watermark 1..lastSeq)
      → ActiveMemoryProjector (listActiveReflections + listActiveCandidateObservations
        → ActiveMemoryRenderer::render, 12-char display ids)
      → non-empty → replaceSummary(text)
      → empty → continue() so core keep-recent / LLM summary path may run
  → no extension_agent dispatch, no model call, no session-event read, no poll/timeout

Snapshot / fork parent (CompactionService::compactMessages, trigger=fork)
  → same public OmBeforeCompactionHook (coverage watermark null/null; parent run_id)
      → same ActiveMemoryProjector as CompactRun
      → non-empty → replaceSummary into inherited child messages; no compaction model
      → empty / OM not registered → continue → ordinary model snapshot compaction
      → hook failure → fail closed (snapshot hard-fail, no silent model fallback)
  → structural below-threshold snapshots still no-op before hooks (unchanged)
  → child extension loading/exclusion is out of scope for OM
```

OM does **not** own a private Symfony Kernel, bin/console, Messenger bus, consumer
supervisor, or priority/multi-receiver queue.


### Live TUI status notices

While an Observer/Reflector/Dropper model stage is running, the async worker writes a
single ephemeral `om_current_activity` row (per session/run). The TUI polls that row
through the public `TuiExtensionContextInterface::onTick` bridge (self-throttled to
≥250ms) and sets status key `om-background` with Pi-style copy, e.g.
`Observational memory: reflector running (~2,500 tokens)`. The row is cleared in
handler `finally` (job-id guarded); crash leftovers older than 5 minutes are hidden.
Status writes never fail model work.

### Freshness tradeoff (accepted)

Compaction renders whatever durable memory is already present. Observer/Reflector/Dropper
work finishing later affects a **later** compaction. Canonical `events.jsonl`
remains the source of truth for recall and later Observer catch-up on turn
boundaries.

### FIFO / failures (async observe + threshold only)

- `extension_agent` uses Symfony Messenger native default `max_retries: 3` (4 attempts total) and **no** failure transport.
- Exhausted jobs emit sanitized transient runtime event `extension_agent.job_failed`
  (seq=0) and a TUI Error block when `payload.run_id` is present.

### Async transport requirement

`ExtensionAgentJobDispatcher` is **fail-closed for `sync://`**.

| Mode | `HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN` |
|---|---|
| Process controller (production async) | `doctrine://messenger_transport?queue_name=extension_agent_*` |
| Unit tests | `in-memory://…` or mock bus with non-sync DSN |
| Default `.env` `sync://` | **refused** at dispatch |

## Activation

OM is **not enabled by default**. Tracked project `.hatfield/settings.yaml` omits the
extension class from `extensions.enabled` and ships an inert nested
`observational_memory` settings example. Activate by listing the class under
`extensions.enabled` and configuring one shared exact model:

```yaml
# project .hatfield/settings.yaml
extensions:
  enabled:
    - Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension
  settings:
    observational_memory:
      storage:
        database: .hatfield/extensions-data/observational-memory/om.sqlite
      model: llama_cpp_test/test
      observer:
        context_window_ratio: 0.65
      reflector:
        reflect_after_observation_tokens: 40000
      pools:
        observations_max_tokens: 30000
```

Install package dependencies into the project extension Composer root:

```bash
cd .hatfield/extensions
composer install
# or after package changes:
composer update ineersa/hatfield-ext-observational-memory
```

## Ownership boundaries

- **OM SQLite** (`.hatfield/extensions-data/observational-memory/om.sqlite`) owns
  observations, coverage, reflections, and generations. Historical compaction
  request/result tables may still exist from older migrations and are inert.
- **Hatfield** owns canonical `events.jsonl`, model infrastructure, and generic
  `extension_agent` FIFO/worker supervision. Session tree UI/command (`/tree`) is
  **not** shipped. OM does **not** own a private consumer or failure transport.
- **Replacement summaries** are deterministic PHP projections of current durable
  active memory. Reflector/Dropper models never author final compact text.
- **Source refs** stay SQLite-only; compact summaries do not include footnotes.
  Model-facing compacted-memory IDs are lowercase first-12-char prefixes (same as `/om-view`);
  full SHA-256 identities remain in SQLite/generation links only.
- **Session-global MVP:** non-branch-aware. Rewind (package-local) does not rewind the OM pool.
- **Delivery gap:** events and OM SQLite can diverge after worker loss; later turn
  boundaries advance Observer coverage asynchronously.

## Commands, search, and recall

- `/om-status` — durable OM memory/activity aggregates for the current session
  (Observer → delta Reflector → bounded Dropper pipeline; compaction is instant projection).
- `/om-view` — active reflections and candidate observations with 12-char display ids,
  timestamp/relevance, content, and human source event sequences.
- `memory_search` — permanent ambient tool. Without semantic settings, find prior work across sessions by one contiguous
  literal substring in retained observational-memory content (all history by default). Optional
  `after` / `before` memory-date filters (`YYYY-MM-DD` or `YYYY-MM-DD HH:MM`). Multi-word queries
  match that exact phrase, not AND of separate words. Results are newest first and bounded
  (default 20, max 50); truncated replies omit older matches and do not provide a total or
  pagination. Observation hits include `importance` assigned at recording time, not a query
  match score. Searches memory content only, not raw transcript events.
  No hits does not prove a conversation never happened; memory can lag or omit details. Prefer
  a single identifier in exact mode, then `recall` for provenance. With semantic settings,
  the same tool combines BM25 keyword and semantic vector search, accepts natural-language
  descriptions and paraphrases, and returns relevance-ranked results.
- `recall` — permanent ambient tool; recover provenance for one known memory id from
  compacted memory, `/om-view`, or `memory_search` (unique lowercase 12–64 hex prefix, or full
  64-char SHA-256). Defaults to the current session; pass `session_id` from a search hit for a
  prior session. Observation results include `importance` assigned at recording time, not a
  query match score. If an id is missing in the selected session, the error names that session
  and reminds you to pass `session_id` from `memory_search`. Verify current repo or PR state
  before acting on historical decisions.

### Search implementation notes

Without semantic settings, `memory_search` uses escaped SQL `LIKE` substring matching with a small result limit. Under
default SQLite settings (and OM does not enable `case_sensitive_like`), ASCII letter case
is insensitive, so `MapTool` matches `maptool`. `%` and `_` in the query are treated as
literals via `ESCAPE`.

Date filters accept real calendar values only (`YYYY-MM-DD` or `YYYY-MM-DD HH:MM`).
`after` must not be later than `before`. Date-only bounds cover the whole day. An HH:MM
`before` bound includes the entire minute for reflection `created_at` values.

Benchmark evidence (read-only disposable copies; never modify the live OM database) is
recorded under `.hatfield/extensions/observational-memory/docs/om-search-like-benchmark.md`, including the exact commands used
on representative (~4.9k observations) and disposable 50k-row corpora. Leading-wildcard
`LIKE` stayed in the low-millisecond to ~15 ms range for identifier queries such as `2510`
and `MapToolArguments`, with `SCAN om_observation` plans. FTS5 was faster at 50k rows but
needs schema/index maintenance and weaker exact-substring fidelity for punctuation-heavy
identifiers. Exact mode keeps bounded `LIKE` search; hybrid mode has a separate derived FTS index.

### Optional hybrid retrieval

Configure `extensions.settings.observational_memory.semantic` to enable hybrid retrieval.
The example below is not enabled by default:

```yaml
semantic:
  embedding_api:
    base_url: http://localhost:8059/v1
    model_id: coderankembed-q8_0.gguf
    query_prefix: 'Represent this query for searching relevant code:'
    chunk_bytes: 1200
    overlap_bytes: 192
    max_lines: 80
    batch_size: 4
  reranker_api:
    base_url: http://localhost:8060/v1
    model_id: bge-reranker-base-q8_0.gguf
    batch_size: 8
    document_characters: 768
    min_score: -4
```

Both API blocks require `base_url` and `model_id`. Omit `reranker_api` to use fused
keyword and vector ranking without reranking. A reranker without embeddings is a
configuration error. The embedding prefix defaults to empty and applies only to
query embeddings, joined with one space. The reranker receives the raw query.
The embedding example includes the colon required by this model.
The other defaults are shown above. Counts must be positive; `chunk_bytes` must be
at least four and `overlap_bytes` must be nonnegative and smaller than the chunk.
Requests run serially with a ten-second HTTP limit. An indexing job embeds at most
four chunks, even if a larger provider batch size is configured.
`reranker_api.min_score` is optional. If set, it keeps chunks with a raw
reranker score greater than or equal to the finite numeric floor. Scores are
model-specific and are not probabilities; choose a floor using labeled queries.
Without a floor, the reranker sorts all candidates. Without a reranker, this
setting has no effect and hybrid search can still return unrelated neighbors.
The `-4` shown here was measured for the named local reranker on this corpus;
it is not a general default. See the [calibration report](docs/om-relevance-calibration.md)
and the [50 reusable questions](docs/relevance-calibration-questions.json).

The implementation uses Symfony AI's Vektor bridge for persistent HNSW vectors,
its SQLite Store for FTS5 BM25, and `CombinedStore` for reciprocal-rank fusion.
PHP needs `mbstring` and `pdo_sqlite` with SQLite FTS5 support. The extension pins
the tested store packages and Vektor version in its Composer requirements.
It retrieves at most 100 chunks from each store, fuses at most 200 candidates,
optionally reranks them, and collapses them to source memories before applying
the existing result limit.
No raw ranking scores are returned. `truncated` is true when either the candidate
budget or result limit is reached. Date-constrained queries overfetch at most 500
candidates per store, filter by date, and retain at most 100 per ranked list.
Returned dates remain correct, but matches outside the bounded candidate pool
can be omitted. Exhausting that pool sets `truncated`, even with zero surviving hits.

The tool name and arguments remain unchanged. Its description, query guidance,
and limit description switch between exact and hybrid capabilities. Reranker
configuration does not change that guidance. `recall` uses the same canonical
memory IDs and source session IDs in both modes.

The [full-corpus benchmark](docs/om-semantic-retrieval-benchmark.md) records
backfill cost, retrieval quality, bounded date filtering, and negative-query limitations.

### Index lifecycle and privacy

The extension schedules indexing through `extension_agent` at session start and
after successful observation and reflection jobs. Each worker processes one
bounded batch and schedules a continuation until complete. SQLite records
progress; restarting a worker does not repeat completed clean batches. Workers
can serve different projects sequentially, with per-operation Vektor setup.

Canonical observations and reflections remain in the configured OM SQLite file.
`om_semantic_document` and its FTS table hold derived chunk text. The sibling
`<database-path>.semantic/vektor/` directory holds vectors, chunk text metadata, and graph files.
These are Composer dependencies, not a native SQLite extension or external service.

A durable dirty marker precedes writes to the two stores. An interrupted write
forces rebuilding derived storage at the next indexing job. Deletions, changed
chunks, changed embedding models, and missing vector files also cause rebuilding.
Vektor soft deletes are deliberately not used: their fixed candidate buffer can
return no results despite live documents. Search rejects incomplete, stale, dirty,
or busy indexes instead of presenting a partial index as healthy. Missing storage
or explicit corruption invalidates the index; transient read failures do not
discard embeddings. Configured endpoint failures return visible errors without
fallback results. A later session start can restart failed indexing. Query HTTP
calls do not hold the index lock. The source/index snapshot is revalidated after
embedding; reranking uses materialized hits after releasing the read lock.

`index_building` includes `indexed_count` and `source_count`, both in memory units.
The first counts current source memories represented by at least one indexed
chunk, not fully indexed memories or search readiness. Dirty or incompatible
indexes report zero. Counts reuse the loaded source snapshot and stored parent
metadata rather than rescanning and rechunking the corpus.

The embedding endpoint receives memory chunks and prefixed search queries. The
reranker receives queries and bounded candidate text. Only configure trusted
endpoints. Logs record failure stages and correlation fields, not memory text,
queries, vectors, response bodies, or chained HTTP exceptions. Derived files
have the same privacy requirements as canonical memory and remain on disk when
semantic retrieval is disabled. Deleting canonical rows prevents stale search
immediately; the next successful indexing job removes their derived copies.

To rebuild a damaged index, stop indexing for that project, remove only its
`<database-path>.semantic/` directory, and start a session to schedule rebuilding.
Do not remove the canonical database to repair derived storage.

### Generation model

One top-level `observational_memory.model` is shared by Observer, Reflector, and Dropper.
Thinking levels are not configured; provider defaults apply. Observer uses
`maxToolCalls=6` to allow correction rounds while bounding accumulated context;
Reflector and Dropper retain the Pi-mapped `maxToolCalls=16` cap.
