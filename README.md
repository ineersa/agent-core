# Hatfield Monorepo

Monorepo for Hatfield, a coding assistant built with PHP.

## Install (PHAR / static)

Default install path is `~/.local/bin` (ensure it is on `PATH`):

```bash
export PATH="$HOME/.local/bin:$PATH"
```

```bash
# PHAR (requires system PHP >= 8.5 + extensions; see docs/phar-packaging.md)
curl --proto '=https' --tlsv1.2 -fsSL \
  https://raw.githubusercontent.com/ineersa/agent-core/main/installer/bash-installer \
  | bash -s --

# Native static binary (no system PHP)
curl --proto '=https' --tlsv1.2 -fsSL \
  https://raw.githubusercontent.com/ineersa/agent-core/main/installer/bash-installer \
  | bash -s -- --static

# Pin a release (optional; omit for latest)
# ... | bash -s -- --version=vX.Y.Z
# ... | bash -s -- --static --version=vX.Y.Z
```

Artifacts: `hatfield.phar`, `hatfield.linux-amd64`, `hatfield.linux-arm64`,
`hatfield.darwin-amd64`, `hatfield.darwin-arm64`, plus `SHA256SUMS`.
Local builds: `castor distribution:build`, `scripts/build-distribution.sh`.
Packaging docs: [`docs/distribution.md`](docs/distribution.md) (release/installer),
[`docs/phar-packaging.md`](docs/phar-packaging.md), [`docs/static-packaging.md`](docs/static-packaging.md).

## Extensions

Public contracts package (not an extension):
[`ineersa/hatfield-extension-api`](https://packagist.org/packages/ineersa/hatfield-extension-api)
([README](.hatfield/extensions/extension-api/README.md)).

| Extension | Purpose | Packagist |
| --- | --- | --- |
| task-workflow | External task board tools, slash commands, and prompt guidance | [`ineersa/hatfield-ext-task-workflow`](https://packagist.org/packages/ineersa/hatfield-ext-task-workflow) |
| castor-llm-mode | LLM-friendly Castor bash rewrites (`LLM_MODE`, normalized `castor list`) | [`ineersa/hatfield-ext-castor-llm-mode`](https://packagist.org/packages/ineersa/hatfield-ext-castor-llm-mode) |
| file-rewind | Hidden-git file checkpoints and `/rewind` restore picker | [`ineersa/hatfield-ext-file-rewind`](https://packagist.org/packages/ineersa/hatfield-ext-file-rewind) |
| observational-memory | Observational memory storage + async Observer/Reflector pipeline | [`ineersa/hatfield-ext-observational-memory`](https://packagist.org/packages/ineersa/hatfield-ext-observational-memory) |

Package READMEs: [`task-workflow`](.hatfield/extensions/task-workflow/README.md),
[`castor-llm-mode`](.hatfield/extensions/castor-llm-mode/README.md),
[`file-rewind`](.hatfield/extensions/file-rewind/README.md),
[`observational-memory`](.hatfield/extensions/observational-memory/README.md).

External install under a project `.hatfield/extensions` Composer root (example):

```json
{
  "require": {
    "ineersa/hatfield-ext-task-workflow": "^X.Y",
    "ineersa/hatfield-extension-api": "^X.Y"
  }
}
```

Enable the extension class in `.hatfield/settings.yaml` (`extensions.enabled`); it takes effect in a **new** session. Details and settings keys live in each package README and [`docs/settings.md`](docs/settings.md). Release/mirror notes: [`docs/distribution.md`](docs/distribution.md).

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
