# Observational Memory (OM) extension

Self-contained Symfony console package providing the OM operational runtime:

- private SQLite database at `.hatfield/extensions-data/observational-memory/om.sqlite`
- private Symfony Messenger bus + Doctrine transports (`om_observation`, `om_compaction`, `om_failed`)
- package-owned `bin/console` (`om:migrate`, `messenger:consume`, …)
- extension-owned supervisor started during Hatfield extension `register()` for the interactive `agent` process (not `--controller` / `--headless`)
- native Symfony `ConsoleEvents::TERMINATE` / `ERROR` for stop

Hatfield does **not** expose `extension:run`, custom runtime lifecycle DTOs, or construct OM Messenger programmatically.

## Activation

OM is **not enabled by default**. Enable the extension class in project settings:

```yaml
# project .hatfield/settings.yaml
extensions:
  enabled:
    - Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension
  settings:
    observational_memory:
      # Optional absolute path. Relative paths resolve against Hatfield CWD.
      # database_path: .hatfield/extensions-data/observational-memory/om.sqlite
      # enabled: true
```

Install package dependencies into the project extension Composer root (package is already required by `.hatfield/extensions/composer.json` after checkout):

```bash
cd .hatfield/extensions
composer install
# or after package changes:
composer update ineersa/hatfield-ext-observational-memory
```

## Process topology

```text
Interactive Hatfield process: `agent` (not --controller / --headless)
  → ExtensionLoaderSubscriber dispatches ConsoleEvents::COMMAND
  → ExtensionManager loads extension, injects logger, calls register()
  → ObservationalMemoryExtension::register()
      (startup here: COMMAND listeners added mid-dispatch never receive
       the current event because EventDispatcher snapshots listeners)
      → OmConsumerSupervisor
          → php .hatfield/extensions/observational-memory/bin/console om:migrate
          → php …/bin/console messenger:setup-transports   # exit 0 required
          → php …/bin/console messenger:consume om_compaction om_observation
              env OM_DATABASE_PATH=<abs path>
              env OM_CACHE_DIR=<dirname(database)>/cache
              env OM_LOG_DIR=<dirname(database)>/log
              env OM_PARENT_PID=<owning agent pid>
  → later ConsoleEvents::TERMINATE / ERROR
  → ObservationalMemoryExtension stops supervisor + child
```

The child boots the **OM package Kernel**, not Hatfield. Recursion cannot occur via Hatfield extension loading. Cache/log dirs are project/database-specific so the compiled container never bakes another project's `OM_DATABASE_PATH`.

Supervisor health checks use `Revolt\EventLoop::repeat` and assume the interactive agent event loop will run after command startup. `HATFIELD_SESSION_ID` is often unset during `register()` (extensions load mid-`ConsoleEvents::COMMAND`); OM falls back to `agent-<pid>` for log correlation only.

## Ownership boundaries

| Owned by OM package | Not owned by OM |
|---|---|
| `om.sqlite` | `.hatfield/state.sqlite` |
| OM Messenger queues | `.hatfield/messenger-transport.sqlite` |
| observation/reflection/coverage tables | `events.jsonl` |
| package `bin/console` + supervisor | Hatfield `ConsumerSupervisor` |

## Privacy

Runtime logs use structured fields only (`component`, `event_type`, correlation IDs). Observation content, prompts, and tool output are never written to Hatfield logs by default. Failed Messenger messages may retain rendered payloads inside `om.sqlite` until drained — treat the OM DB as sensitive.

## Out of scope for this package preview

Observer/Reflector prompts, model calls from handlers, boundary enqueue hooks, compaction replacement hook, `/om` TUI commands, and settings UI belong to later OM tasks.
