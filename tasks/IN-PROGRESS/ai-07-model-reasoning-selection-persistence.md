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
Status: IN-PROGRESS
Branch: task/ai-07-model-reasoning-selection-persistence
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-07-model-reasoning-selection-persistence
Fork run:
PR URL:
PR Status:
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
