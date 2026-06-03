# 03-refactor-agentcore-mailbox-policy: unify command boundary application

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/agent-core-architecture.md

Deepen CommandMailboxPolicy by removing duplicated command-iteration logic between turn-start and stop-boundary application while preserving the public behavior and event sequence.

Scope:
- Extract a shared internal command application path parameterized by boundary semantics.
- Preserve public methods applyPendingTurnStartCommands() and applyPendingStopBoundaryCommands().
- Keep command validation, steer superseding, extension commands, rejection/applied events, and shouldContinue semantics identical.

## Acceptance criteria
- Duplicated inner loops in CommandMailboxPolicy are consolidated into a single internal flow with explicit boundary semantics.
- Existing CommandMailboxPolicy tests pass and include focused coverage for both boundary modes after refactor.
- No external AgentCore contracts or persisted event payloads change.
- Run and report Castor validation: castor test --filter=CommandMailboxPolicy plus castor check, or exact environmental blockers.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/03-refactor-agentcore-mailbox-policy
Worktree: /home/ineersa/projects/agent-core-worktrees/03-refactor-agentcore-mailbox-policy
Fork run:
PR URL:
PR Status:
Started: 2026-06-03T15:16:57.156Z
Completed:

## Work log
- Created: 2026-06-03T00:31:40.751Z

## Task workflow update - 2026-06-03T15:16:12.383Z
- Summary: Implementation plan agreed:

**Decisions:**
- Rejection reason strings: unify to generic `"Invalid command payload:..."` for both boundaries
- `CommandApplicationBoundary` enum and `CommandApplicationResult` DTO as standalone files in `src/AgentCore/Application/Pipeline/`

**Steps:**
1. Create `CommandApplicationBoundary` enum (TurnStart | StopBoundary)
2. Create `CommandApplicationResult` readonly DTO (state, eventSpecs, shouldContinue)
3. Extract shared `applyPendingCommands(RunState, CommandApplicationBoundary): CommandApplicationResult` private method — unified loop using StopBoundary's combined `\in_array()` style with dynamic `$pendingCommand->kind`
4. Rewrite both public methods as thin delegates preserving exact signatures and positional-array returns
5. Add focused boundary-mode tests (TurnStart never sets shouldContinue; StopBoundary sets it on follow-up apply; StopBoundary false when no commands)
6. Validate: `castor test --filter=CommandMailboxPolicy`, `castor test`, `castor check`

**No external contract changes.** Callers (AdvanceRunHandler, LlmStepResultHandler) untouched.

## Task workflow update - 2026-06-03T15:16:57.156Z
- Moved TODO → IN-PROGRESS.
- Created branch task/03-refactor-agentcore-mailbox-policy.
- Created worktree /home/ineersa/projects/agent-core-worktrees/03-refactor-agentcore-mailbox-policy.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/03-refactor-agentcore-mailbox-policy.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/03-refactor-agentcore-mailbox-policy.
