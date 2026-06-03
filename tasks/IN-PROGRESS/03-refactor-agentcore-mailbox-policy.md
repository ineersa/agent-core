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
Fork run: h13xzm03mjmw
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

## Task workflow update - 2026-06-03T15:18:28.325Z
- - Claimed task, moved to IN-PROGRESS, worktree: /home/ineersa/projects/agent-core-worktrees/03-refactor-agentcore-mailbox-policy
- - Fork launched: h13xzm03mjmw — implementing the unified CommandMailboxPolicy refactor

## Task workflow update - 2026-06-03T15:22:51.755Z
- Recorded fork run: h13xzm03mjmw
- Validation: castor test --filter=CommandMailboxPolicy → 7 tests, 24 assertions, PASS; castor test → 1587 tests, 4734 assertions, 0 failures; castor deptrac → 0 violations, 591 uncovered, 856 allowed
- Summary: Implementation complete. Commit 04fa7013 on branch task/03-refactor-agentcore-mailbox-policy.

**Changes (4 files):**
- Created `CommandApplicationBoundary` enum (TurnStart | StopBoundary)
- Created `CommandApplicationResult` readonly DTO (state, eventSpecs, shouldContinue)
- Rewrote `CommandMailboxPolicy.php`: ~200 lines of duplicated logic collapsed into unified `applyPendingCommands()` private method; both public methods are now thin delegates preserving exact signatures
- Added 3 new boundary-mode tests in `CommandMailboxPolicyTest.php`

**Validation:**
- `castor test --filter=CommandMailboxPolicy` — 7 tests, 24 assertions, PASS
- `castor test` — 1587 tests, 4734 assertions, PASS
- `castor deptrac` — 0 violations

**No callers changed** (AdvanceRunHandler, LlmStepResultHandler, ApplyCommandHandler untouched).

## Task workflow update - 2026-06-03T15:30:35.231Z
- Validation: castor test → 1587 tests, 4734 assertions, 0 failures; castor deptrac → 0 violations, 591 uncovered, 856 allowed; castor phpstan → 0 errors, 0 file_errors; castor cs-check → ok, files_fixed=0
- Summary: Reviewer verdict: APPROVE WITH SUGGESTIONS (no blockers). Suggestions are cosmetic/nice-to-have: enum naming convention, docblock clarity, additional negative-path tests. All local validation passes: castor test (1587/4734), deptrac (0 violations), phpstan (0 errors), cs-check (0 fixes).
- - Reviewer: APPROVE WITH SUGGESTIONS — behavioral equivalence verified, no critical issues
- - Local validation: castor test + deptrac + phpstan + cs-check all clean
- - Moving to CODE-REVIEW for Castor quality gate + PR
