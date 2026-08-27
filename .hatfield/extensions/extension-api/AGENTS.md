# Extension API

- Package `ineersa/hatfield-extension-api`, namespace `Ineersa\Hatfield\ExtensionApi`. Canonical development is in this monorepo; tag publish is a read-only mirror (`docs/distribution.md`).
- This is a public compatibility surface: do not depend on CodingAgent internals, AgentCore, in-repo TUI (`Ineersa\Tui\*`), Symfony DI/AI, settings, tool registry, runtime adapters, or PHAR packaging.
- Generic TUI contracts under `Ineersa\Hatfield\ExtensionApi\Tui\*` may depend only on public Symfony TUI widgets/events/input (`AppExtensionApi` → `SymfonyTui` in Deptrac).
- Feature UX belongs in `.hatfield/extensions/<name>/`; do not add feature-shaped types to ExtensionApi or Runtime Contract. Loader/registry may depend on ExtensionApi; never the reverse. Keep the `Ineersa\Hatfield\ExtensionApi` namespace stable.
