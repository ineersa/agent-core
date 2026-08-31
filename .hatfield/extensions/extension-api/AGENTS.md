# Extension API

- Package `ineersa/hatfield-extension-api`, namespace `Ineersa\Hatfield\ExtensionApi`. Canonical development is in this monorepo; tag publish is a read-only mirror (`docs/distribution.md`).
- This is a public compatibility surface: do not depend on CodingAgent internals, AgentCore, in-repo TUI (`Ineersa\Tui\*`), Symfony DI/AI, settings, tool registry, runtime adapters, or PHAR packaging.
- Generic TUI contracts under `Ineersa\Hatfield\ExtensionApi\Tui\*` may depend only on public Symfony TUI widgets/events/input (`AppExtensionApi` → `SymfonyTui` in Deptrac).
- Feature UX belongs in `.hatfield/extensions/<name>/`; do not add feature-shaped types to ExtensionApi or Runtime Contract. Concrete extensions depend on ExtensionApi contracts (plus explicitly approved public vendor APIs), never AgentCore, CodingAgent internals, or in-repo TUI. Host loader/registry code may implement or consume ExtensionApi contracts but must not depend on concrete extension implementations; ExtensionApi never depends on either side.
- Host adapters may translate public ExtensionApi DTOs and handlers into internal models. That small mapping cost is intentional for a separately published compatibility boundary; do not replace it with dependencies on host internals.
- Keep the `Ineersa\Hatfield\ExtensionApi` namespace stable.
