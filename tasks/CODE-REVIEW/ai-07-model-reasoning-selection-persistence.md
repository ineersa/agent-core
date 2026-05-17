# AI-07 Implement model/reasoning selection and persistence service

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-07--implement-modelreasoning-selection-and-persistence-service

Goal: centralize model/reasoning selection priority and persistence.

Depends on: AI-02; AI-03 for home settings writes.

Parallelism: most logic can run after AI-02; persistence integration needs AI-03. Unblocks AI-10 and AI-14.

Scope:
- Implement `ModelSelectionService` and `ReasoningSelectionService`, or one cohesive service.
- Model resolution priority: explicit request/CLI/runtime input, session metadata, Hatfield `ai.default_model`, first available configured model.
- Reasoning resolution priority mirrors model selection, falling back to `ai.default_reasoning` then `medium` or `off` if needed.
- On change: update home default, update session metadata current fields, expose enough info for runtime/TUI events later.
- Validate every selected model against `HatfieldModelCatalog`.

## Acceptance criteria
- New sessions use configured default model.
- Resumed sessions use session metadata model/reasoning.
- Changing model/reasoning persists both home defaults and session current state.
- Suggested validation: `castor test --filter ModelSelection`.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/ai-07-model-reasoning-selection-persistence
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-07-model-reasoning-selection-persistence
Fork run: lerrdnurexld
PR URL: https://github.com/ineersa/agent-core/pull/13
PR Status: open
Started: 2026-05-17T01:27:21.764Z
Completed:

## Work log
- Created: 2026-05-16T22:01:55.477Z

## Task workflow update - 2026-05-17T01:27:21.764Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-07-model-reasoning-selection-persistence.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-07-model-reasoning-selection-persistence.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-07-model-reasoning-selection-persistence.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-07-model-reasoning-selection-persistence.

## Task workflow update - 2026-05-17T01:32:34.831Z
- Recorded fork run: k3iy1ja8fe75
- Summary: Launched fork k3iy1ja8fe75 to implement AI-07 in worktree /home/ineersa/projects/agent-core-worktrees/ai-07-model-reasoning-selection-persistence. Scope: add ModelSelectionService with 4-tier priority (explicit → session metadata → ai.default_model → first available), reasoning mirror with fallback to ai.default_reasoning/medium, persistence via HomeSettingsWriter and session metadata, catalog validation via HatfieldModelCatalog::isAvailable(), focused tests.

## Task workflow update - 2026-05-17T01:52:51.000Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ai-07-model-reasoning-selection-persistence to origin.
- branch 'task/ai-07-model-reasoning-selection-persistence' set up to track 'origin/task/ai-07-model-reasoning-selection-persistence'.
- Created PR: https://github.com/ineersa/agent-core/pull/13
- Summary: AI-07 implemented by fork k3iy1ja8fe75 in commit 2b58610a. Added ModelSelectionService (294 lines) with 4-tier model priority (explicit → session metadata → ai.default_model → first available) and 3-tier reasoning priority (explicit → session metadata → ai.default_reasoning → medium). Persists via HomeSettingsWriter::writeDefaultModel/writeDefaultReasoning and session metadata YAML. 18 tests covering priority chain, dual persistence, edge cases. Cleaned up 7 stale PHPStan baselines for properties now read by the service.

## Task workflow update - 2026-05-17T02:34:04.586Z
- Recorded fork run: wffz81bijh8q
- Summary: Launched fork wffz81bijh8q to refactor AI-07 per PR #13 review: move HatfieldModelCatalog onto AppConfig (created once during resolution, cached), extract SessionMetadataStore (read/write session metadata, setSessionsBasePath), wire setSessionsBasePath in InProcessAgentSessionClient, strip ModelSelectionService of all private helpers (createCatalog, read/write session metadata, path resolution), simplify to ~150 lines.

## Task workflow update - 2026-05-17T02:57:26.671Z
- Recorded fork run: lerrdnurexld
- Summary: Launched fork lerr dnurexld to refactor HomeSettingsWriter — remove filePath parameters from writeDefaultModel/writeDefaultReasoning, have the writer own the home settings path via SettingsPathResolver injected in constructor. ModelSelectionService drops SettingsPathResolver dependency entirely.
