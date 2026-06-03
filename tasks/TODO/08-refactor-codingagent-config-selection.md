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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-06-03T00:32:17.712Z
