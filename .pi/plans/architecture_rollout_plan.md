# Architecture Rollout Plan — Phase 1 Complete

## Status

**Phase 1 (Single Composer App / Modular Monolith) is complete as of 2026-05-13.**

The project was converted from a multi-workspace monorepo (root + `apps/coding-agent/`, `packages/agent-core/`, `packages/tui-bundle/`, each with their own `composer.json`/`vendor`) into a single Composer application with logical module boundaries enforced by Deptrac.

## Changes in Phase 1

### Source consolidation

| Original | New | Namespace |
|----------|-----|-----------|
| `packages/agent-core/src/` | `src/AgentCore/` | `Ineersa\AgentCore\` (unchanged) |
| `apps/coding-agent/src/` | `src/CodingAgent/` | `App\` → `Ineersa\CodingAgent\` |
| `packages/tui-bundle/src/` | `src/TuiBundle/` (Phase 1) → `src/Tui/` (Phase 2) | `Ineersa\TuiBundle\` (Phase 1) → `Ineersa\Tui\` (Phase 2) |

### Entry point & config

- `bin/console` — moved from `apps/coding-agent/bin/console`, updated to use `Ineersa\CodingAgent\Kernel`
- `config/` — moved from `apps/coding-agent/config/`, YAML files updated
- `.env` — moved from `apps/coding-agent/.env`
- `depfile.yaml` — moved from `apps/coding-agent/depfile.yaml`, paths updated
- `phpunit.xml.dist` — new root-level config covering both test suites

### Root composer.json

Single dependency set merging all 3 workspaces. PSR-4 autoloads all source from `src/`. No path repositories. `minimum-stability: dev` + `prefer-stable: true`.

### QA configs copied to root

- `.php-cs-fixer.dist.php`, `phpstan.dist.neon`, `phpstan-baseline.neon` — copied from agent-core package

### Boundary enforcement

Deptrac at root enforces the same rules as before: TUI cannot import AgentCore Application/Infrastructure or Messenger. Zero violations.

## Legacy workspaces (transitional)

The old workspace directories still exist but are no longer the canonical build targets:
- `packages/agent-core/` — source, tests, QA configs remain for reference
- `packages/tui-bundle/` — source remains for reference
- `apps/coding-agent/` — source, config, tests remain for reference

These will be removed in Phase 2.

## Running the app

```bash
php bin/console list
php bin/console agent --help
php bin/console agent --headless < start_command.jsonl
castor check  # deptrac + phpunit
```

## Phase 2 (complete 2026-05-13)

1. ✅ **Legacy workspace directories removed**: `packages/agent-core/`, `packages/tui-bundle/`, `apps/coding-agent/` deleted after verifying all source/config/tests fully replicated at root.
2. ✅ **PHPStan baseline regenerated**: Fresh baseline generated with 121 ignored errors using correct paths (`src/AgentCore/...`). PHPStan passes with 0 errors.
3. ✅ **Full QA wired into `castor check`**: Now runs deptrac + phpunit + phpstan + cs-fixer dry-run. Individual tasks also available.
4. ✅ **Gitignore cleaned**: Legacy entries removed, only root-prefixed ignores remain.
5. ✅ **Docs updated**: `AGENTS.md` rewritten for modular monolith, old workspace references removed.
6. ✅ **TuiBundle migrated to `src/Tui/`**: `src/TuiBundle/` deleted, `src/CodingAgent/TUI/` moved to `src/Tui/Application/` and `src/Tui/Widget/` under namespace `Ineersa\Tui\`. Bundle registration removed from `bundles.php`. Deptrac layers updated.

## Remaining / future

- No further architecture-migration work planned. The project is a single modular monolith.
