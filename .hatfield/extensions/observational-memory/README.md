# Observational Memory (OM) extension

Architecture preview of the extension-owned operational runtime:

- private SQLite database at `.hatfield/extensions-data/observational-memory/om.sqlite`
- private Symfony Messenger bus + Doctrine transport (not Hatfield's Messenger DB)
- one persistent consumer process per owning HeadlessController
- extension-owned supervisor started/stopped via public `RuntimeStartedEvent` / `RuntimeStoppingEvent`

## Activation

OM is **not enabled by default**. Enable it only when you want the preview consumer:

```yaml
# project .hatfield/settings.yaml
extensions:
  enabled:
    - Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension
  settings:
    observational_memory:
      # Optional absolute path. Relative paths resolve against Hatfield CWD.
      # database_path: .hatfield/extensions-data/observational-memory/om.sqlite
      # enabled: true   # default true when the extension class is enabled
```

Also install the package into the project extension Composer root:

```bash
cd .hatfield/extensions
# path repository already present after checkout; require the package
composer require ineersa/hatfield-ext-observational-memory:@dev
```

## Process topology

```text
HeadlessController
  → RuntimeStartedEvent
  → OM supervisor (in controller process)
      → <hatfield> extension:run Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension consume
          env HATFIELD_OM_CONSUMER=1
          → private Worker against om.sqlite
```

Recursion guard: when `HATFIELD_OM_CONSUMER=1` is set, the extension does not start another supervisor.

## Ownership boundaries

| Owned by OM | Not owned by OM |
|---|---|
| `om.sqlite` | `.hatfield/state.sqlite` |
| OM Messenger queues | `.hatfield/messenger-transport.sqlite` |
| observation/reflection/coverage tables | `events.jsonl` |
| extension supervisor/consumer | Hatfield ConsumerSupervisor |

## Privacy

Runtime logs use structured fields only (`component`, `event_type`, correlation IDs). Observation content, prompts, and tool output are never written to Hatfield logs by default. Failed Messenger messages may retain rendered payloads inside `om.sqlite` until drained — treat the OM DB as sensitive.

## Out of scope for this package preview

Observer/Reflector prompts, model calls from handlers, compaction hooks, `/om` TUI commands, and settings UI belong to later OM tasks.
