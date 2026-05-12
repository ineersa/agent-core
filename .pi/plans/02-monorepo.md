# Plan 02: Monorepo Migration & Architecture Split

**Goal:** After cleanup (Plan 01), move the library into `packages/agent-core/`, set up the full monorepo skeleton with three workspaces, and define what code lives where.

**Prerequisite:** Plan 01 (Cleanup) complete. Library is lean.

**Duration:** ~45 mins execution, ~30 mins verification

---

## Final Directory Tree

```
agent-core/                              # git repo, orchestration root
├── packages/
│   ├── agent-core/                      # ineersa/agent-core composer package
│   │   ├── composer.json
│   │   ├── src/                         # Pipeline, Domain, Contract, Storage, SymfonyAi
│   │   ├── tests/
│   │   ├── castor.php
│   │   ├── .castor/
│   │   ├── phpstan.dist.neon
│   │   ├── phpstan-baseline.neon
│   │   ├── phpunit.xml.dist
│   │   ├── .php-cs-fixer.dist.php
│   │   └── LICENSE
│   │
│   └── tui-bundle/                      # ineersa/tui-bundle (skeleton for now)
│       ├── composer.json
│       ├── src/
│       └── tests/
│
├── apps/
│   └── coding-agent/                    # Symfony CLI app (self-contained)
│       ├── composer.json                # requires agent-core + tui-bundle (path repos)
│       ├── bin/console
│       ├── public/index.php
│       ├── src/Kernel.php
│       ├── src/CLI/                     # Commands: run, list, resume
│       ├── src/TUI/                     # Interactive mode
│       ├── src/Tool/                    # Tool implementations
│       ├── src/Session/                 # Persistence, compaction
│       ├── src/Extension/              # Loader, runtime API
│       ├── config/
│       │   ├── bundles.php
│       │   ├── services.php
│       │   └── packages/
│       ├── tests/
│       └── var/ vendor/ (gitignored)
│
├── docs/
│   └── archive/implementation/          # Archived stage plans
├── .pi/plans/                           # These plans
├── castor.php                           # Root orchestrator
├── .castor/                             # Workspace-level tasks
├── composer.json                        # Root: orchestration only (require-dev)
├── .gitignore
├── README.md
└── AGENTS.md
```

---

## Part A: Move Library into `packages/agent-core/`

### A1: Create directory and move files

```bash
mkdir -p packages/agent-core

# Move source, tests, and QA config
mv src/          packages/agent-core/src/
mv tests/        packages/agent-core/tests/
mv castor.php    packages/agent-core/castor.php
mv .castor/      packages/agent-core/.castor/
mv composer.json packages/agent-core/composer.json
mv composer.lock packages/agent-core/composer.lock
mv phpstan.dist.neon         packages/agent-core/
mv phpstan-baseline.neon     packages/agent-core/
mv phpunit.xml.dist          packages/agent-core/
mv .php-cs-fixer.dist.php    packages/agent-core/
mv .php-cs-fixer.cache       packages/agent-core/
mv LICENSE        packages/agent-core/
```

### A2: Archive implementation docs

```bash
mkdir -p docs/archive
mv implementation/ docs/archive/implementation/
```

### A3: Verify library works standalone

```bash
cd packages/agent-core
composer install
LLM_MODE=true castor dev:check
```

If tests reference a kernel or DI extension (removed in Plan 01), delete or fix those tests.

---

## Part B: Set Up Root Orchestration

### B1: Root `composer.json`

Root is NOT a Symfony app. It's an orchestration project for the workspace.

```json
{
    "name": "ineersa/agent-core-dev",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": ">=8.5"
    },
    "require-dev": {
        "jolicode/castor": "^0.26"
    }
}
```

### B2: Root `castor.php` + `.castor/`

Workspace-level tasks:

| Task | Action |
|------|--------|
| `castor check` | Run QA in all three workspaces (agent-core, tui-bundle, coding-agent) |
| `castor install` | `composer install` in root + all three workspaces |
| `castor lib:check` | `cd packages/agent-core && castor dev:check` |
| `castor lib:test` | `cd packages/agent-core && castor dev:test` |

Root castor imports shared helpers from `.castor/helpers.php`.

### B3: Root `.gitignore`

```
/vendor/
/.php-cs-fixer.cache
/.idea/

# Package vendors (each manages its own)
/packages/agent-core/vendor/
/packages/agent-core/.php-cs-fixer.cache
/packages/tui-bundle/vendor/
/packages/tui-bundle/composer.lock

# App outputs
/apps/coding-agent/vendor/
/apps/coding-agent/var/
/apps/coding-agent/.php-cs-fixer.cache
```

---

## Part C: Create `packages/tui-bundle/` Skeleton

### C1: `packages/tui-bundle/composer.json`

```json
{
    "name": "ineersa/tui-bundle",
    "description": "Symfony TUI component integration bundle",
    "type": "symfony-bundle",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "symfony/console": "^8.0",
        "symfony/framework-bundle": "^8.0"
    },
    "autoload": {
        "psr-4": {
            "Ineersa\\TuiBundle\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Ineersa\\TuiBundle\\Tests\\": "tests/"
        }
    }
}
```

**Future:** Add `symfony/tui-component` when Symfony releases the official component. For now, tui-bundle is the implementation.

### C2: Minimal `src/`

```
packages/tui-bundle/src/
├── TuiBundle.php            # Symfony bundle class
├── TUI.php                  # Core TUI engine (Component, Container, rendering)
├── Component/               # Widgets: Editor, SelectList, Markdown, Input, etc.
├── Keybinding/              # Key → action registry
├── Theme/                   # Color/style themes
└── DependencyInjection/     # Bundle DI extension
```

### C3: `TuiBundle.php`

```php
namespace Ineersa\TuiBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class TuiBundle extends Bundle {}
```

---

## Part D: Create `apps/coding-agent/` Skeleton

### D1: `apps/coding-agent/composer.json`

```json
{
    "name": "ineersa/coding-agent",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "symfony/framework-bundle": "^8.0",
        "symfony/console": "^8.0",
        "symfony/messenger": "^8.0",
        "ineersa/agent-core": "@dev",
        "ineersa/tui-bundle": "@dev"
    },
    "require-dev": {
        "friendsofphp/php-cs-fixer": "^3.94",
        "phpstan/phpstan": "^2.1",
        "phpunit/phpunit": "^13.0",
        "symfony/test-pack": "^1.0"
    },
    "repositories": [
        {
            "type": "path",
            "url": "../../packages/agent-core",
            "options": { "symlink": true }
        },
        {
            "type": "path",
            "url": "../../packages/tui-bundle",
            "options": { "symlink": true }
        }
    ],
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "App\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

### D2: Standard Symfony app files

- `apps/coding-agent/bin/console` — Symfony console entry
- `apps/coding-agent/public/index.php` — Front controller
- `apps/coding-agent/src/Kernel.php` — Standard kernel, register `TuiBundle`
- `apps/coding-agent/config/bundles.php` — FrameworkBundle, TuiBundle
- `apps/coding-agent/config/services.php` — App service autowiring
- `apps/coding-agent/config/packages/framework.yaml` — Framework defaults
- `.env` — `APP_ENV=dev`

### D3: Wire agent-core in the app

`apps/coding-agent/config/packages/agent_core.yaml`:

```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true

  # Default in-memory stores (replace with Doctrine later)
  Ineersa\AgentCore\Contract\RunStoreInterface:
    alias: Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore

  Ineersa\AgentCore\Contract\EventStoreInterface:
    alias: Ineersa\AgentCore\Infrastructure\Storage\RunEventStore

  Ineersa\AgentCore\Contract\CommandStoreInterface:
    alias: Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore
```

### D4: App source layout

```
apps/coding-agent/src/
├── Kernel.php
├── CLI/
│   ├── AgentRunCommand.php         # Start a new run
│   ├── AgentListCommand.php        # List existing runs
│   ├── AgentResumeCommand.php      # Resume an existing run
│   └── AgentChatCommand.php        # Interactive TUI mode
├── TUI/
│   ├── InteractiveMode.php         # Wires agent-core pipeline ↔ TUI widgets
│   └── Widget/                     # Agent-specific widgets (prompt input, tool output, etc.)
├── Tool/
│   ├── ToolRegistry.php            # Discovers built-in + extension tools
│   ├── ReadFileTool.php            # Implements agent-core ToolExecutorInterface
│   ├── WriteFileTool.php
│   ├── EditFileTool.php
│   ├── BashTool.php
│   ├── FindTool.php
│   └── GrepTool.php
├── Session/
│   ├── SessionStore.php            # Doctrine-backed persistence
│   ├── Compactor.php               # Message summarization
│   └── Skills.php                  # Skill definition loader
├── Extension/
│   ├── Loader.php                  # Discovers + loads user extensions
│   ├── ExtensionAPI.php            # The API object given to extensions
│   └── Runtime.php                 # Bridges API ↔ agent-core + TUI
└── Config/
    ├── ModelResolver.php           # Resolves which AI model to use
    └── AppConfig.php               # App-level settings
```

---

## Part E: Architecture Boundaries

### What `packages/agent-core/` OWNS

- Domain model (RunState, RunEvent, RunStatus, commands, messages, tools)
- Agent pipeline (RunOrchestrator + 5 handlers)
- Contracts (RunStoreInterface, EventStoreInterface, CommandStoreInterface, hook interfaces, tool interfaces)
- Default in-memory stores (InMemoryRunStore, etc.)
- Symfony AI bridge
- Hook system (HookDispatcher, HookSubscriberRegistry, AfterTurnCommitHookContext)
- Replay service (state reconstruction from events)
- Tool execution infrastructure (ToolExecutor, ToolBatchCollector, etc.)

### What `packages/tui-bundle/` OWNS

- Terminal rendering engine
- Keybinding system (key → action mapping)
- Theme system (colors, styles)
- Widget library (Editor, SelectList, Markdown, Input, Loader, etc.)
- Bundle wiring (TuiBundle, DI extension)

### What `apps/coding-agent/` OWNS

- CLI commands (start, list, resume, chat)
- Interactive TUI mode (wires agent-core pipeline events → TUI widgets)
- Built-in tool implementations (ReadFile, WriteFile, EditFile, Bash, Find, Grep)
- Extension loader + runtime API
- Session persistence (Doctrine-backed)
- Compaction (summarization)
- Skills (reusable sub-agent instructions)
- Model/provider configuration resolution

---

## Part F: Extension System Flow

The extension system bridges user code with agent-core + TUI:

```
User extension script
        │  receives ExtensionAPI
        ▼
ExtensionLoader::load()        # apps/coding-agent
        │
        ▼
ExtensionRuntime::bind()       # apps/coding-agent
        │  wires API → real services
        ▼
    ExtensionAPI
        │
        ├── pi.on(event, fn) ──▶ agent-core HookSubscriberRegistry
        ├── pi.tool(def)    ──▶ ToolRegistry → ToolExecutorInterface
        ├── pi.command(name)──▶ CommandRouter → CommandHandlerInterface
        ├── pi.model(...)   ──▶ ModelResolver
        └── pi.ui.widget(...)──▶ tui-bundle widget registry
```

Extensions never touch agent-core internals directly — only through the API.

---

## Verification Gates

```bash
# 1. Library checks
cd packages/agent-core && LLM_MODE=true castor dev:check

# 2. tui-bundle skeleton (composer validates)
cd packages/tui-bundle && composer validate

# 3. App boots
cd apps/coding-agent && composer install && php bin/console about

# 4. Root orchestrator
castor check
```

---

## Risks

- **`.php-cs-fixer.cache` path** — After moving, the cache references old absolute paths. Delete it and let it regenerate.
- **`castor.php` portability** — Library castor runs from `packages/agent-core/`. Paths inside `.castor/dev.php` must be relative to that directory. Verify by running `castor dev:check` from within the package, not from root.
- **Composer autoloader bootstrap in tests** — `tests/bootstrap.php` references `vendor/autoload.php`. After moving to `packages/agent-core/tests/`, it resolves to `packages/agent-core/vendor/` — correct. Verify.
