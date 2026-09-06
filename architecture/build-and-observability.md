# Build and observability

[Architecture map](README.md)

## One canonical PHAR, several release assets

```mermaid
flowchart TD
    Source[bin, src, config, migrations] --> Stage[Fresh PHAR staging directory]
    API[Public ExtensionApi package source] --> Stage
    Docs[Top-level Markdown with builtin: true] --> Catalog[BuiltinDocsCatalog]
    Catalog --> Stage
    Stage --> Composer[Composer install without dev dependencies]
    Composer --> Box[Box compile]
    Box --> PHAR[Canonical hatfield.phar]
    PHAR --> Smoke[Artifact startup and resource checks]
    Smoke --> Handoff[Canonical PHAR handoff]
    Handoff --> Native[PHP-micro fusion for each platform]
    Handoff --> Dist[Release assets]
    Native --> Dist
    Dist --> Checksums[SHA256SUMS]
    Checksums --> Installer[Download, verify, smoke candidate, atomic install]
    RepoOnly[README assets, architecture, unmarked docs] -.->|Excluded from staging| Excluded[Repository only]
```

The GIF and VHS tape under `docs/assets/` are not catalog-selected Markdown.
Native builds reuse the PHAR rather than introducing a second documentation catalog.

## QA ownership

```mermaid
flowchart TB
    Change[Task implementation] --> Focused[Focused Castor checks]
    Focused --> Review[CODE-REVIEW transition]
    Review --> Gate[castor check]
    Gate --> Unit[Unit and integration]
    Gate --> Replay[Controller and TUI replay]
    Gate --> Live[Live test-LLM smoke]
    Gate --> Static[Deptrac, PHPStan, dead code, code style]
    Gate --> Docs[docs:validate]
    Unit --> Reports[Per-run reports]
    Replay --> Reports
    Live --> Reports
    Static --> Reports
    Docs --> Reports
```

The testing skill and `tests/AGENTS.md` own exact prerequisites and budgets. Docs
validation checks the selected catalog and package-safe links; it is not proof that
Mermaid diagrams render or that architectural claims match source.

## Correlated logs, not transcript dumps

```mermaid
flowchart LR
    Controller[Controller lifecycle] --> Logs[Structured application logs]
    Core[Run transitions and commits] --> Logs
    Worker[Tool and model worker outcomes] --> Logs
    Logs --> Fields[run_id, session_id, component, event_type]
    Fields --> Local[Configured local log files]
    Fields --> DD[Optional Datadog collection]
    Provider[Provider invocation] --> Traces[Optional tracing instrumentation]
    Traces --> DD
```

Do not log raw prompts, tool output, credentials, or entire sessions by default.
Operational logs help correlate a failure; `events.jsonl` remains conversation
history, not an observability export.

Sources: [distribution](../docs/distribution.md), [PHAR packaging](../docs/phar-packaging.md),
[Datadog development setup](../docs/datadog.md), [testing procedure](../.agents/skills/testing/SKILL.md).
