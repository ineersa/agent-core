# Observational Memory (OM) extension

Architecture preview of the extension-owned operational runtime:

- private SQLite database at `.hatfield/extensions-data/observational-memory/om.sqlite`
- private Symfony Messenger bus + Doctrine transport (not Hatfield's Messenger DB)
- one persistent consumer process per owning HeadlessController
- extension-owned supervisor started/stopped via public `RuntimeStartedEvent` / `RuntimeStoppingEvent`
- parent-death stop via `HATFIELD_OM_PARENT_PID` so SIGKILL of the controller does not leave orphans

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

The package is already required from the project extension Composer root
(`.hatfield/extensions/composer.json` path repository
`ineersa/hatfield-ext-observational-memory`). Install or refresh autoload after
checkout/pull:

```bash
cd .hatfield/extensions
composer install
# or, after package/path changes:
composer update ineersa/hatfield-ext-observational-memory
```

## Process topology

```text
HeadlessController
  → RuntimeStartedEvent
  → OM supervisor (in controller process)
      → Revolt repeat watcher → supervise()
      → <hatfield> extension:run Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension consume
          env HATFIELD_OM_CONSUMER=1
          env HATFIELD_OM_PARENT_PID=<controller pid>
          → private Worker against om.sqlite
          → OmParentDeathListener stops Worker if parent dies
```

Recursion guard: when `HATFIELD_OM_CONSUMER=1` is set, the extension does not start another supervisor or Revolt watcher.

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
