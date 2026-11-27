# Build and observability

[Architecture map](README.md) · [Logging](logging.md)

## One canonical PHAR, several release assets

```mermaid
flowchart TD
    Source[bin, src, config, migrations] --> Stage[Fresh PHAR staging directory]
    API["Extension API package source, docs/ excluded"] --> Stage
    Reporter["hatfield-360 reporter src"] --> Stage
    Docs["docs/*.md and extension-api/docs/*.md<br/>with builtin: true"] --> Catalog[BuiltinDocsCatalog]
    Catalog -->|"Selected files at canonical paths"| Stage
    Identity["Generated build identity<br/>version and commit"] --> Stage
    Stage --> Composer["Composer install --no-dev,<br/>materialize path-package symlink"]
    Composer --> Box[Box compile]
    Box --> PHAR[Canonical hatfield.phar]
    PHAR --> PharSmoke["phar_smoke: list, about,<br/>agent --help, --version, cache isolation"]
    PharSmoke --> Dist["distribution:build<br/>copy to dist, --version and list smoke"]
    Dist --> Handoff[Canonical PHAR handoff]
    Handoff --> Native["distribution:build-static<br/>PHP-micro fusion per target"]
    Native --> NativeSmoke["--version and list smoke,<br/>native process-topology smoke"]
    Dist --> Assets[Release assets]
    NativeSmoke --> Assets
    Assets --> Checksums[SHA256SUMS]
    Checksums --> Verify["distribution:verify<br/>smokes, bundled resources, checksums"]
    Checksums --> Installer["bash installer: download, verify,<br/>smoke candidate, atomic mv"]
    RepoOnly[README assets, architecture, unmarked docs] -.->|Excluded from staging| Excluded[Repository only]
```

`BuiltinDocsCatalog` has exactly two approved roots, top-level `docs/*.md` and
`.hatfield/extensions/extension-api/docs/*.md`; selection requires strict
`builtin: true` frontmatter and IDs must be unique across roots. The GIF and VHS
tape under `docs/assets/` are not catalog-selected Markdown. A failed compile or
PHAR smoke deletes the artifact, and only a successful smoke writes the freshness
marker.

Native builds reuse the canonical PHAR rather than rebuilding it or introducing a
second documentation catalog: release static jobs consume the PHAR job's artifact
as-is. `distribution:verify` additionally proves the PHAR contains bundled defaults,
themes, migrations and byte-identical selected docs, and rejects unmarked Markdown
under either docs root.

### Packaged process-topology smoke

```mermaid
flowchart LR
    Fixture["Disposable HOME, settings,<br/>empty fixture session"] --> Provider["Local TCP fixture provider<br/>smoke/smoke model"]
    Provider --> Launch[Launch packaged controller]
    Launch --> Topology["After runtime.ready, assert<br/>Messenger consumer descendants"]
    Topology --> Broker["Queue broker proof:<br/>send, receive, acknowledge"]
    Broker --> Calls{"Variant"}
    Calls -->|Default| Code[Scripted code-mode tool calls]
    Calls -->|"Bash without PHP on PATH"| Bash["Scripted bash calls,<br/>foreground and background"]
    Code --> Gone[Assert captured owned PIDs exit]
    Bash --> Gone
```

`distribution_smoke_packaged_process_topology()` runs the default variant against the
PHAR during `distribution:verify`. Static binaries run both variants; the bash variant
restricts `PATH` so packaged Bash execution cannot fall back to a host PHP.
`distribution:verify --skip-topology` skips only the native runs.

## QA ownership

```mermaid
flowchart TB
    Change[Implementation change] --> Focused[Focused Castor checks]
    Focused --> Gate["castor check<br/>shared repository lock, absolute wall budget"]
    Gate --> Migrate[Test database migration and tmux preflight]
    Migrate --> Lanes[Parallel lane subprocesses]
    Lanes --> Tests["test: ParaTest unit and integration"]
    Lanes --> Replay["test:controller-replay, test:tui"]
    Lanes --> Static["deptrac, phpstan, lsp:check,<br/>dead-code, cs-check"]
    Lanes --> Meta["docs:validate, catalog:version-check"]
    Tests --> Reports["var/reports/check-step.log<br/>and per-lane reports"]
    Replay --> Reports
    Static --> Reports
    Meta --> Reports
    Reports --> Final["Lane artifact integrity,<br/>process and tmux leak assertion,<br/>wall check, quality result"]
```

All lanes run without live model providers. The testing skill and `tests/AGENTS.md`
own exact prerequisites and budgets. Docs validation checks the selected catalog and
package-safe links; it is not proof that Mermaid diagrams render or that
architectural claims match source.

## Correlated logs, not transcript dumps

```mermaid
flowchart LR
    Controller[Controller lifecycle] --> Logs[Structured application logs]
    Core[Run transitions and commits] --> Logs
    Worker[Tool and model worker outcomes] --> Logs
    Tracer["RunTracer spans<br/>agent_loop.trace.start / finish"] --> Logs
    Logs --> Fields[run_id, session_id, component, event_type]
    Fields --> Local[Configured local log files]
    Local --> DD[Optional Datadog Agent file collection]
    Sampler["Opt-in session resource sampler"] -->|DogStatsD UDP| DD
```

Do not log raw prompts, tool output, credentials, or entire sessions by default.
Operational logs help correlate a failure; `events.jsonl` remains conversation
history, not an observability export. Tracing is log-based; there is no APM
exporter. See [Logging](logging.md) for levels, fields and the sampler.

Sources: [castor check](../.castor/tasks.php), [PHAR compile](../.castor/helpers.php),
[distribution tasks](../.castor/distribution.php),
[docs catalog](../src/CodingAgent/Docs/BuiltinDocsCatalog.php),
[installer](../installer/bash-installer), [release workflow](../.github/workflows/release.yml),
[distribution](../docs/distribution.md), [PHAR packaging](../docs/phar-packaging.md),
[Datadog development setup](../docs/datadog.md), [testing procedure](../.agents/skills/testing/SKILL.md).
