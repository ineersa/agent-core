# Extension API

- Keep the published `Ineersa\Hatfield\ExtensionApi` namespace stable.
- Do not depend on AgentCore, CodingAgent internals, in-repo TUI, Symfony DI or AI, settings, tool registries, or packaging.
- Generic TUI contracts may use only approved public Symfony TUI widget, event, and input APIs.
- Put feature-specific UX in concrete extensions, not ExtensionApi or Runtime Contract.
- Keep host-to-public DTO mapping at the host adapter boundary; do not replace it with dependencies on internals.
