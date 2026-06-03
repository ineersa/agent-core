# 08-refactor-codingagent-config-selection: split model selection and config path resolution

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/coding-agent-architecture.md

Separate pure model/reasoning resolution from settings persistence and simplify repetitive config path resolution.

Scope:
- Extract read-side ModelResolver for explicit/session/default/fallback model and reasoning selection.
- Extract or clarify write-side ModelSettingsPersister for home settings and session metadata writes.
- Keep ModelSelectionService as a compatibility/coordinator facade for existing callers during this internal refactor.
- Replace AppConfigLoader hardcoded path if-blocks with a declarative path map.

## Acceptance criteria
- Pure model/reasoning resolution can be tested without filesystem or session metadata persistence dependencies.
- Model mutation/persistence tests are isolated from catalog resolution tests.
- AppConfigLoader path resolution is declarative and covered by tests for existing path-bearing config keys.
- Run and report Castor validation: castor test --filter=ModelSelectionService/AppConfigLoader relevant tests plus castor check, or exact environmental blockers.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/08-refactor-codingagent-config-selection
Worktree: /home/ineersa/projects/agent-core-worktrees/08-refactor-codingagent-config-selection
Fork run:
PR URL:
PR Status:
Started: 2026-06-03T20:50:07.554Z
Completed:

## Work log
- Created: 2026-06-03T00:32:17.712Z

## Task workflow update - 2026-06-03T20:42:28.906Z
- Summary: Finalized plan with user decisions: (1) SessionMetadataStore stays in ModelResolver as-is, (2) favorites stay in ModelSelectionService, (3) ModelSelectionService validates before delegating to persister, (4) flat dot-notation path map with Symfony PropertyAccess for AppConfigLoader, (5) test migration with simplification — collapse resolution tests into data providers, reduce from 56 tests to ~20 focused tests.

## Task workflow update - 2026-06-03T20:46:53.090Z
- Summary: Implementation plan finalized. Architecture: ModelResolver (read-only, ~120 lines, deps: AppConfig + SessionMetadataStore), ModelSettingsPersister (write-only, ~60 lines, deps: HomeSettingsWriter + SessionMetadataStore, trusts input — no validation), ModelSelectionService (coordinator, ~150 lines, owns favorites + favRaw cache + validation before delegating). AppConfigLoader path map: flat constant using Symfony PropertyAccess bracket notation ([tui][theme_paths] => 'list', etc.), 5 if-blocks collapse into single loop. Tests: ~25 focused tests across ModelResolverTest (pure unit, ~10 tests with data providers), ModelSettingsPersisterTest (~4 tests), ModelSelectionServiceTest (~8 coordinator-level), AppConfigLoaderTest (add path-map coverage). No external contract changes.

## Task workflow update - 2026-06-03T20:50:07.554Z
- Moved TODO → IN-PROGRESS.
- Created branch task/08-refactor-codingagent-config-selection.
- Created worktree /home/ineersa/projects/agent-core-worktrees/08-refactor-codingagent-config-selection.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/08-refactor-codingagent-config-selection.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/08-refactor-codingagent-config-selection.
