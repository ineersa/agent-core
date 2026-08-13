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
Symfony DI/AI, settings loaders, tool registry internals, or packaging code.

## Enablement

1. Install the extension package under the project `.hatfield/extensions` Composer root (or ship with the monorepo).
2. List the extension class in `.hatfield/settings.yaml` → `extensions.enabled`.
3. Start a **new** session — `HatfieldExtensionInterface::register(ExtensionApiInterface $api)` runs at startup.

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
| `registerBeforeCompactionHook` | Compaction contribution/trim |
| `sessionEvents` | Read canonical session events |
| `agent` / extension-agent jobs | Isolated agent calls + async jobs |

Focused references: [extension-api-tools.md](extension-api-tools.md),
[extension-api-runtime.md](extension-api-runtime.md),
[extension-api-tui.md](extension-api-tui.md).

## Settings and CWD

`getSettings('file_rewind')` returns `extensions.settings.file_rewind` (array, possibly empty).
Paths should resolve against `getCwd()`, not the extension install path, unless you intentionally use `__DIR__` for package assets.

## Skills

`registerSkill($absoluteDirectoryWithSkillMd)` adds a lowest-precedence skill root.
Relative paths are rejected — pass `__DIR__.'/skills/foo'`.

## Versioning

Treat this package as a published API. Additive methods may appear; do not rely on host-private classes.
