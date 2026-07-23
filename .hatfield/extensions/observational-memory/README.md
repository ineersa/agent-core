# Observational Memory (OM) extension

Extension-owned observational memory storage and asynchronous Observer pipeline.

## Architecture (OM-03)

Hatfield provides a **generic** async extension-agent job facility:

```text
AfterTurnCommit (any run_control/llm/tool worker)
  → ObserveBoundaryTerminalHook (hot batch only)
  → ExtensionApi::dispatchExtensionAgentJob(scalar payload)
  → Symfony Messenger transport `extension_agent`  (async Doctrine DSN required)
  → dedicated Hatfield messenger:consume extension_agent worker
      → ExtensionLoaderSubscriber loads extensions
      → ExtensionAgentJobWorker resolves handler by stable ID
      → ObserveBoundaryJobHandler
          → open/migrate om.sqlite (per-job path-local DBAL connection)
          → SessionEventReader::readRange (async path)
          → package-local renderer + tool-result bounding
          → $api->agent()->run(... record_observations tool, maxToolCalls=3 ...)
          → transactionally persist observations + coverage
```

OM no longer owns a private Symfony Kernel, bin/console, Messenger bus, or consumer supervisor.

### Async transport requirement

`ExtensionAgentJobDispatcher` is **fail-closed for `sync://`**. The public
`dispatchExtensionAgentJob()` contract promises work on a dedicated worker, not
inline model execution during AfterTurnCommit.

| Mode | `HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN` |
|---|---|
| Process controller (production async) | `doctrine://messenger_transport?queue_name=extension_agent_*` (set by HeadlessController / JsonlProcessAgentSessionClient) |
| Unit tests | `in-memory://…` or mock bus with non-sync DSN |
| Default `.env` `sync://` | **refused** at dispatch (OM hot hook logs and continues) |

Do not work around this by launching consumers in direct/headless mode.

## Activation

OM is **not enabled by default**. Enable the extension class in project settings:

```yaml
# project .hatfield/settings.yaml
extensions:
  enabled:
    - Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension
  settings:
    observational_memory:
      enabled: true
      # exact provider/model required for Observer jobs
      observer_model: llama_cpp_test/test
      # database_path: .hatfield/extensions-data/observational-memory/om.sqlite
      # observer_input_budget_tokens: 12000
      # max_observations: 12
      # tool_result_max_chars: 4000
      # content_max_chars: 2000
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
| observation/reflection/coverage schema | `.hatfield/messenger-transport.sqlite` |
| package-local renderer / tool validation | Hatfield provider credentials / Platform |
| | `events.jsonl` (read-only via public SessionEventReader) |

The generic `extension_agent` transport lives in Hatfield Messenger and carries only JSON-safe job envelopes (handler ID + payload). Live tool handlers are never serialized.

## Privacy

Runtime logs use structured fields only (`component`, `event_type`, correlation IDs). Observation content, prompts, and tool output are never written to Hatfield logs by default. Treat `om.sqlite` as sensitive (data directory is created with mode `0700`).

## Out of scope (later OM tasks)

- Reflector / compaction replacement hook
- `/om status` and `/om view` TUI commands
- Cross-session memory index
- Failure-transport drain UI
- Ranged EventStore / connection pooling (perf deferred)
