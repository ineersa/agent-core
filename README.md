# Hatfield Monorepo

Monorepo for Hatfield, a coding assistant built with PHP.

## Structure

```
├── packages/
│   ├── agent-core/            # ineersa/agent-core library
│   │   ├── src/               # Pipeline, Domain, Contract, Infrastructure
│   │   ├── tests/
│   │   ├── castor.php         # Package-level task runner
│   │   └── composer.json
│   └── tui-bundle/            # ineersa/tui-bundle (Symfony TUI)
│       ├── src/
│       ├── tests/
│       └── composer.json
├── apps/
│   └── coding-agent/          # Symfony CLI application
│       ├── bin/console
│       ├── src/
│       ├── config/
│       └── composer.json
├── docs/
│   └── archive/implementation/  # Archived stage plans
├── .pi/plans/                 # Active plans
├── castor.php                 # Root orchestrator
└── composer.json              # Root: orchestration only
```

## Getting Started

```bash
# Install root dependencies (castor)
composer install

# Install all workspace dependencies
castor install

# Project extensions (e.g. task-workflow): Hatfield loads
# .hatfield/extensions/vendor/autoload.php — run after clone/pull when
# extensions.enabled includes packages under .hatfield/extensions/
composer install -d .hatfield/extensions

# Run QA across all workspaces
castor check
```

After enabling new extensions in `.hatfield/settings.yaml`, start a new Hatfield session so tools and slash commands register at startup.

## Workspace Commands

| Command | Description |
|---------|-------------|
| `castor check` | Run QA in all workspaces |
| `castor install` | Install all dependencies |
| `castor lib:check` | Run agent-core library QA |
| `castor lib:test` | Run agent-core library tests |
| `castor lib:cs-fix` | Run CS fixer on agent-core |
| `castor lib:phpstan` | Run PHPStan on agent-core |
| `castor tui:validate` | Validate tui-bundle composer.json |
| `castor app:check` | Run coding-agent app QA |
