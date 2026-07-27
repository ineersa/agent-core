# Observational Memory (OM) extension

Extension-owned observational memory storage, asynchronous Observer pipeline,
threshold Reflector generations, and CompactRun replacement summaries.

## Architecture (OM-03 + OM-04)

Hatfield provides a **generic** async extension-agent job facility. OM uses the
existing single FIFO `extension_agent` transport/worker for Observer and Reflector
model work.

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
          → deterministic 0.65 context-window chunk/part packer
          → $api->agent()->run(... record_observations, maxToolCalls=100 ...)
          → transactionally persist observations + coverage parts
          → optional threshold dispatch observational_memory.reflect_generation

Threshold (after all observe chunks durable, tokens > 40000)
  → ReflectGenerationJobHandler
      → claim generation by exact threshold-generation-v1 id
      → Reflector complete-generation tool loop (maxToolCalls=100)
      → exactly one compression retry if pools exceeded
      → promote om_active_generation transactionally

CompactRun (run_control, under run lock)
  → public OmBeforeCompactionHook (CompactRun only; not snapshot/fork)
      → resolve positive context windows via api->agent()->contextWindow()
      → ensure om_compaction_request (request_fingerprint + request_id formulas)
      → dispatch ExtensionAgentJobRequestDTO (JSON-safe only)
      → poll om.sqlite request/result with short autocommit reads + bounded timeout
      → on deadline: mark request timed_out and cancel CompactRun
      → replaceSummary(server-rendered text) or cancel(...); never silent summary fallback
  → BuildCompactionMemoryJobHandler on extension_agent worker
      → contiguous coverage catch-up via ObserverPipeline
      → Reflector ALWAYS when active memory exists (no 40k gate)
      → commit generation + deterministic PHP render + result atomically
  → CompactRunHandler::handleReplacementSummary() (skips ExecuteCompactionStep)
```

OM does **not** own a private Symfony Kernel, bin/console, Messenger bus, consumer
supervisor, Dropper stage, or priority/multi-receiver queue.

### Required coverage watermark

Compaction catch-up uses session-global **`1..RunState.lastSeq`** captured when
CompactRun prepares the public hook context. Contiguous coverage never uses
`MAX(source_end_seq)`; incomplete chunk parts do not advance the watermark.

### FIFO wait / timeout / failures

- Hook wait is bounded by `observational_memory.compaction.wait_timeout_seconds`
  (default 180).
- Timeout transitions request to terminal `timed_out` and preserves original messages.
- Late worker success after `timed_out` is rejected (no generation promotion / no reusable result).
- `extension_agent` uses `max_retries: 1` and **no** failure transport.
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
extension class from `extensions.enabled` and ships a nested
`observational_memory` example with `enabled: false`. Activate by listing the class
and setting `enabled: true` with exact models:

```yaml
# project .hatfield/settings.yaml
extensions:
  enabled:
    - Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension
  settings:
    observational_memory:
      enabled: true
      storage:
        database: .hatfield/extensions-data/observational-memory/om.sqlite
      observer:
        model: llama_cpp_test/test
        thinking_level: medium
        context_window_ratio: 0.65
      reflector:
        model: llama_cpp_test/test
        thinking_level: high
        context_window_ratio: 0.65
        reflect_after_observation_tokens: 40000
      pools:
        observations_max_tokens: 30000
        reflections_max_tokens: 10000
      compaction:
        wait_timeout_seconds: 180
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
  observations, coverage, reflections, generations, and compaction request/result rows.
- **Hatfield events.jsonl / Doctrine / Messenger** never store OM semantic memory.
- **Replacement summaries** are deterministic PHP projections of the latest active
  generation. Reflector models never author final `replacement_text`.
- **Source refs** stay SQLite-only for future recall (OM-05); compact summaries do not
  include footnotes.
