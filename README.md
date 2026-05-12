# Agent Core Monorepo

Monorepo for the agent-core PHP library and its ecosystem.

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

# Run QA across all workspaces
castor check
```

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
