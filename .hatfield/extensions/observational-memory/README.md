# Observational Memory (OM) extension

Extension-owned observational memory storage, asynchronous Observer pipeline, and
CompactRun Reflector replacement summaries.

## Architecture (OM-03 + OM-04)

Hatfield provides a **generic** async extension-agent job facility. OM uses the
existing single FIFO `extension_agent` transport/worker for both Observer and
Reflector model work.

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
          → package-local renderer + tool-result bounding
          → $api->agent()->run(... record_observations tool, maxToolCalls=3 ...)
          → transactionally persist observations + coverage

CompactRun (run_control, under run lock)
  → public OmBeforeCompactionHook (CompactRun only; not snapshot/fork)
      → ensure om_compaction_request (fingerprint = run + 1..lastSeq + versions/models/budgets)
      → dispatch ExtensionAgentJobRequestDTO (JSON-safe only)
      → poll om.sqlite request/result with short autocommit reads + bounded timeout
      → replaceSummary(...) or cancel(...); never silent summary fallback
  → BuildCompactionMemoryJobHandler on extension_agent worker
      → contiguous coverage catch-up via ObserverPipeline (requires record_observations call)
      → Reflector via $api->agent()->run(... record_reflections, maxToolCalls=3 ...)
      → atomic reflections + om_compaction_result
  → CompactRunHandler::handleReplacementSummary() (skips ExecuteCompactionStep)
```

OM does **not** own a private Symfony Kernel, bin/console, Messenger bus, consumer
supervisor, or priority/multi-receiver queue.

### Required coverage watermark

Compaction catch-up uses session-global **`1..RunState.lastSeq`** captured when
CompactRun prepares the public hook context. No compacted-message-index → event-seq
mapping is performed. Retained-tail overlap with already-observed ranges is acceptable.

### FIFO wait / timeout

Compaction jobs wait behind already-queued observation jobs on the single
`extension_agent` worker. That is intentional for MVP.

- Hook wait is bounded by `observational_memory.compaction.wait_timeout_seconds`
  (default 180).
- Timeout cancels CompactRun actionably and preserves original messages.
- Timeout does **not** write a durable `timed_out` terminal row, so a late exact-identity
  success remains reusable on a later compaction.
- Persistent backlog is an operational problem, not a reason to add priority queues.

### Async transport requirement

`ExtensionAgentJobDispatcher` is **fail-closed for `sync://`**. The public
`dispatchExtensionAgentJob()` contract promises work on a dedicated worker, not
inline model execution during AfterTurnCommit or CompactRun.

| Mode | `HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN` |
|---|---|
| Process controller (production async) | `doctrine://messenger_transport?queue_name=extension_agent_*` (set by HeadlessController / JsonlProcessAgentSessionClient) |
| Unit tests | `in-memory://…` or mock bus with non-sync DSN |
| Default `.env` `sync://` | **refused** at dispatch (OM hot hooks cancel/log rather than model-call inline) |

Do not work around this by launching consumers in direct/headless mode.

## Activation

OM is **not enabled by default**. Tracked project `.hatfield/settings.yaml` intentionally
omits both the extension class and any `observational_memory` settings block so normal
dev sessions do not start Observer/Reflector work. Canonical key documentation also lives
in `docs/settings.md` under `extensions.settings.observational_memory`.

Enable the extension class and settings when you want OM active:

```yaml
# project .hatfield/settings.yaml
extensions:
  enabled:
    - Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension
  settings:
    observational_memory:
      enabled: true
      # exact provider/model required for Observer / Reflector jobs
      observer_model: llama_cpp_test/test
      reflector_model: llama_cpp_test/test
      # database_path: .hatfield/extensions-data/observational-memory/om.sqlite
      # observer_input_budget_tokens: 12000
      # max_observations: 12
      # tool_result_max_chars: 4000
      # content_max_chars: 2000
      # observations_max_tokens: 30000
      # reflections_max_tokens: 10000
      # reflector_input_budget_tokens: 20000
      # max_reflections: 8   # Reflector requires >=1 reflection when invoked
      # reflection_content_max_chars: 4000
      # replacement_max_chars: 12000
      # compaction:
      #   wait_timeout_seconds: 180
```

Install package dependencies into the project extension Composer root:

```bash
cd .hatfield/extensions
composer install
# or after package changes:
composer update ineersa/hatfield-ext-observational-memory
```

## Ownership boundaries

| Owned by OM package | Not owned by OM |
|---|---|
| `om.sqlite` domain tables | `.hatfield/state.sqlite` |
| observation/reflection/coverage/compaction schema | `.hatfield/messenger-transport.sqlite` |
| package-local renderer / tool validation | Hatfield provider credentials / Platform |
| request/result polling for CompactRun | normal `ExecuteCompactionStep` worker/result path |
| | `events.jsonl` (read-only via public SessionEventReader) |

The generic `extension_agent` transport lives in Hatfield Messenger and carries only JSON-safe job envelopes (handler ID + payload). Live tool handlers are never serialized.

## Failure semantics

- Public CompactRun hook expected failures return `cancel(...)` (conflict, timeout, durable worker failure, missing model config, `sync://` dispatch refusal). No silent fallback to summary-mode LLM compaction.
- Explicit empty `record_observations` is valid coverage; model never calling the tool is a durable typed failure (`observer_tool_not_called`) and does not advance coverage.
- Zero durable observations after catch-up produces durable `no_observations` failure (no empty-memory replacement).
- Reflector must call `record_reflections` once with non-empty `replacement_text` and **at least one** reflection; empty reflections are model-correctable rejections (no state mutation).
- Compatible job redelivery no-ops without a second model call. Immutable request fingerprint mismatches are conflicts.
- Terminal request without a result row cancels CompactRun immediately (no redispatch / timeout wait).
- Unexpected transient provider/process errors may propagate for Messenger retry; durable failure persistence exceptions are also rethrown (not swallowed) so the job can retry rather than ACK while the request stays `running`. CompactRun hook timeout remains the safety valve if no durable failure row is available.
- Successful replacement metadata exposes request/watermark keys plus namespaced `om_provenance` from result `metadata_json`; malformed/non-JSON-safe provenance fails closed without leaking raw content.

## Privacy

Runtime logs use structured fields only (`component`, `event_type`, correlation IDs). Observation content, prompts, and tool output are never written to Hatfield logs by default. Treat `om.sqlite` as sensitive (data directory is created with mode `0700`).

## Out of scope (later OM tasks)

- `/om status` and `/om view` TUI commands
- Priority / multi-receiver `extension_agent` transport
- Cross-session memory index
- Branch-aware OM projection
- Failure-transport drain UI
- Ranged EventStore / connection pooling (perf deferred)
