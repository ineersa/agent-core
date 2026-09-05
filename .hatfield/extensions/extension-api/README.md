# Hatfield Extension API

Public Composer contracts for Hatfield project extensions.

| | |
|---|---|
| Package | `ineersa/hatfield-extension-api` |
| Namespace | `Ineersa\Hatfield\ExtensionApi\` |
| PHP | `>=8.3` |
| Requires | `symfony/tui: ^8.0` |

This package is the stable surface extensions depend on. Host Hatfield provides a concrete `ExtensionApiInterface` implementation at runtime; extensions should depend only on these contracts, not on CodingAgent, AgentCore, the in-repo TUI layer (`Ineersa\Tui\*`), Symfony DI, Symfony AI, settings internals, tool registry, runtime adapters, or packaging code.

## What it provides

- `HatfieldExtensionInterface` — startup `register(ExtensionApiInterface $api)` entry for enabled extensions.
- `ExtensionApiInterface` — tools, tool call/result/rewrite hooks, slash commands, settings/cwd access, after-turn-commit hooks, session-start hooks, before-compaction hooks, exec, session event reading, extension-agent jobs, and related DTOs under `Agent\`, `Approval\`, `Command\`, `Compaction\`, `Exec\`, `Lifecycle\`, `Prompt\`, `Session\`, `Tool\`.
- `Tui\TuiExtensionInterface` / `Tui\TuiProjectExtensionInterface` and `Tui\TuiExtensionContextInterface` — generic TUI extension registration and overlay/slot APIs.

**Approved Symfony TUI dependency:** generic TUI contracts intentionally depend on **Symfony TUI public widget types** (`Symfony\Component\Tui\Widget\AbstractWidget`, events, input) so extensions can mount their own overlays without Hatfield adding feature-shaped runtime ports. That is part of the public UI extension API — not a leak of Hatfield internals.

Feature-specific UX (for example file rewind) stays in `.hatfield/extensions/<name>/` and must not add feature-shaped types to this package.

## Install

After a Hatfield `v*` release (and optionally Packagist):

```json
{
  "require": {
    "ineersa/hatfield-extension-api": "^X.Y"
  },
  "repositories": [
    { "type": "vcs", "url": "https://github.com/ineersa/hatfield-extension-api" }
  ]
}
```

Prefer released tags. In the Hatfield monorepo, root and `.hatfield/extensions` Composer roots path-require this package from `.hatfield/extensions/extension-api`.

## Source of truth

**[ineersa/agent-core](https://github.com/ineersa/agent-core)** is the authoritative monorepo. This GitHub repository is a **read-only release mirror**: each Hatfield `vX.Y.Z` tag updates `main` and publishes the same tag here. Do not open feature PRs against the mirror.

See monorepo docs:

- [docs/distribution.md](https://github.com/ineersa/agent-core/blob/main/docs/distribution.md) — package split, shared versioning, external install
- [AGENTS.md](https://github.com/ineersa/agent-core/blob/main/AGENTS.md) — Extension API boundary rules
