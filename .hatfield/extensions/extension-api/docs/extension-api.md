---
builtin: true
description: Public Extension API installation, lifecycle, settings, skills, and minimal extension skeleton.
---

# Hatfield Extension API Authoring Overview

Package `ineersa/hatfield-extension-api` (`Ineersa\Hatfield\ExtensionApi\`) is the stable surface
extensions compile against. Host Hatfield injects a concrete `ExtensionApiInterface` at startup.

## Boundary

Extensions **may** depend on this package and Symfony TUI public widgets (for TUI extensions).
Extensions **must not** depend on CodingAgent internals, AgentCore, in-repo `Ineersa\Tui\*`,
Symfony DI/AI, settings loaders, tool registry internals, or packaging code. Host code implements
and consumes these contracts but must not depend on concrete extension implementations.
Host adapters intentionally map public DTOs and handlers into internal models; this small cost
keeps the separately published API stable and is not an internal boundary workaround.

## Enablement

1. Install the extension package under the project `.hatfield/extensions` Composer root (or ship with the monorepo).
2. List the extension class in `.hatfield/settings.yaml` → `extensions.enabled`.
3. Restart Hatfield. `HatfieldExtensionInterface::register(ExtensionApiInterface $api)`
   runs on console command startup in each process, including consumers. `/new`
   inside a running host does not reload classes. Session-start hooks are a
   separate interactive-controller lifecycle, not worker-start callbacks.
   Failures in construction, logger injection, registration or subscriber attachment
   are caught and logged; other extensions continue loading. Partial registrations
   are **not rolled back**, so a failed load can leave earlier registrations active.

```php
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;

final class ExampleExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        // register tools, hooks, commands, skills, …
    }
}
```

## Core host capabilities

| API | Purpose |
|---|---|
| `registerTool` | Permanent tools |
| `registerToolCallHook` / `registerToolResultHook` | Pre/post tool hooks |
| `registerToolCallRewriteHook` | Rewrite arguments for a named tool |
| `registerCommand` | Slash commands |
| `registerPromptContributor` | System prompt injection |
| `registerSkill` | Package-local skill directory (`SKILL.md`) |
| `getSettings` / `getCwd` | Extension settings bag + project CWD |
| `exec` | Safe argv exec (no shell interpolation) |
| `registerAfterTurnCommitHook` | Stable post-turn commits |
| `registerSessionStartHook` | Interactive controller session start |
| `registerBeforeCompactionHook` | Compaction contribution/trim |
| `sessionEvents` | Read canonical session events |
| `agent` / extension-agent jobs | Isolated agent calls + async jobs |

Focused references: [extension-api-tools.md](extension-api-tools.md),
[extension-api-runtime.md](extension-api-runtime.md),
[extension-api-tui.md](extension-api-tui.md).

## Settings and CWD

`getSettings('task_workflow')` returns `extensions.settings.task_workflow` (array, possibly empty).
Paths should resolve against `getCwd()`, not the extension install path, unless you intentionally use `__DIR__` for package assets.

For editable setup fields, implement `ConfigurableExtensionInterface::getConfiguration()`
with Symfony Config's `ConfigurationInterface`. Its tree root names the
`extensions.settings` key; use native node defaults, validation and `info()`.
Set node attribute `hatfield.model_picker` to `true` for a generation-model picker.
Configuration inspection does not call `register()`.

`hatfield extensions:setup` lists built-ins and matching packages from Hatfield's
own Composer `suggest` catalog, not every installed extension. For catalog packages,
Composer `extra.hatfield.extension` identifies the entry class; it does not add an
arbitrary package to setup. Configure other custom extensions manually through
`extensions.enabled` and `extensions.settings.<tree-root>` in settings YAML.

## Skills

`registerSkill($skillDirectory)` registers a skill directory scanned after CLI and
project/user auto-discovery paths (and only when skill auto-discovery is enabled).
The host stores the string as given and does **not** currently validate absolute vs
relative paths — pass an absolute package path such as `__DIR__.'/skills/foo'` so
resolution does not depend on process CWD.

## Versioning

Treat this package as a published API. Additive methods may appear; do not rely on host-private classes.
