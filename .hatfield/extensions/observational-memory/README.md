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

## Commands and recall

- `/om-status` — durable OM memory/activity aggregates for the current session
  (Observer → delta Reflector → bounded Dropper pipeline; compaction is instant projection).
- `/om-view` — active reflections and candidate observations with 12-char display ids,
  timestamp/relevance, content, and human source event sequences.
- `recall` — permanent ambient tool; recover exact source context for one known memory id
  shown in compacted memory or `/om-view` (unique lowercase 12–64 hex prefix, or full 64-char
  SHA-256) in the current session.
  Use before important decisions / for exact wording, provenance, supporting sources, or
  user evidence questions. Not semantic search or transcript browsing; do not recall every id.

One top-level `observational_memory.model` is shared by Observer, Reflector, and Dropper.
Thinking levels are not configured; provider defaults apply. Observer uses
`maxToolCalls=6` to allow correction rounds while bounding accumulated context;
Reflector and Dropper retain the Pi-mapped `maxToolCalls=16` cap.
