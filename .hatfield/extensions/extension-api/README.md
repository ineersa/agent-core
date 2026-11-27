# Hatfield Extension API

Public Composer contracts for Hatfield project extensions.

| | |
|---|---|
| Package | `ineersa/hatfield-extension-api` |
| Namespace | `Ineersa\Hatfield\ExtensionApi\` |
| PHP | `>=8.3` |
| Requires | `symfony/tui: ^8.0`, `symfony/config: ^8.1` |

This package is the stable surface extensions depend on. Host Hatfield provides a concrete `ExtensionApiInterface` implementation at runtime; extensions should depend only on these contracts, not on CodingAgent, AgentCore, the in-repo TUI layer (`Ineersa\Tui\*`), Symfony DI, Symfony AI, settings internals, tool registry, runtime adapters, or packaging code.

## What it provides

- `HatfieldExtensionInterface` — startup `register(ExtensionApiInterface $api)` entry for enabled extensions.
- `ExtensionApiInterface` — tools, standalone tool invocation, owner notifications, recurring job sources, tool call/result/rewrite hooks, slash commands, prompt contributors, skills, settings/cwd access, after-turn-commit, session-start, session-deleted and before-compaction hooks, exec, session event reading, extension-agent jobs, and related types under `Agent\`, `Approval\`, `Command\`, `Compaction\`, `DTO\`, `Exec\`, `Lifecycle\`, `Path\`, `Prompt\`, `Scheduler\`, `Session\`, `Tool\`.
- `Tui\TuiExtensionInterface` and `Tui\TuiExtensionContextInterface` — generic TUI extension registration and overlay/status APIs (`Tui\TuiProjectExtensionInterface` is deprecated).
- `Session\SessionContextInterface` — the session selected in the interactive process: ID, title and background snapshot (`Session\DTO\BackgroundSnapshotDTO`, `Session\DTO\ScheduleDTO`).
- `Session\Event\*` — live session, turn, assistant message, tool, input, child and background-job events for Symfony event subscribers.

**Approved Symfony TUI dependency:** generic TUI contracts intentionally depend on **Symfony TUI public widget types** (`Symfony\Component\Tui\Widget\AbstractWidget`, events, input) so extensions can mount their own overlays without Hatfield adding feature-shaped runtime ports. That is part of the public UI extension API — not a leak of Hatfield internals.

Feature-specific UX stays in `.hatfield/extensions/<name>/` and must not add feature-shaped types to this package.

Extensions may implement `ConfigurableExtensionInterface::getConfiguration()` to expose their own native Symfony Config `ConfigurationInterface` without runtime registration. The tree root names the `extensions.settings` key; native node types, `info`, defaults, and validation describe the editable settings. Set the native node attribute `hatfield.model_picker` to `true` for a configured generation-model picker. Packages identify their extension entry class in `composer.json` under `extra.hatfield.extension`; configuration fields and validation stay in the extension.

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
- [AGENTS.md](https://github.com/ineersa/agent-core/blob/main/.hatfield/extensions/extension-api/AGENTS.md) — Extension API boundary rules
