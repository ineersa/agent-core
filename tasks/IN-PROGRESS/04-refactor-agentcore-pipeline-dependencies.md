# 04-refactor-agentcore-pipeline-dependencies: remove shallow state tools facade

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/agent-core-architecture.md

Remove the shallow RunMessageStateTools facade and give each pipeline handler only the focused collaborators it actually uses. Use this task to also improve the AgentMessage/Symfony AI conversion boundary where it naturally falls out of the facade removal.

Scope:
- Delete or retire RunMessageStateTools.
- Inject EventFactory, ToolCallExtractor, and message conversion/normalization services directly where needed.
- Move AgentMessageNormalizer out of Domain if feasible in this task; otherwise leave a small follow-up note for remaining Symfony AI coupling.
- Update handler tests to construct focused dependencies.

## Acceptance criteria
- Pipeline handlers no longer depend on RunMessageStateTools.
- Each handler constructor depends on focused services actually used by that handler.
- AgentMessageNormalizer no longer lives in Domain, or the task documents the remaining Domain/Symfony AI coupling and why it is deferred.
- Run and report Castor validation: castor test --filter for affected pipeline handlers, castor deptrac, and castor check, or exact environmental blockers.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/04-refactor-agentcore-pipeline-dependencies
Worktree: /home/ineersa/projects/agent-core-worktrees/04-refactor-agentcore-pipeline-dependencies
Fork run: ovne33fzhgdm
PR URL:
PR Status:
Started: 2026-06-03T16:09:54.694Z
Completed:

## Work log
- Created: 2026-06-03T00:31:44.294Z

## Task workflow update - 2026-06-03T16:08:09.850Z
- Summary: Revised implementation plan agreed:

**Decisions:**
- Symfony AI coupling in Domain is intentional and stays — no AgentMessageNormalizer move
- `isStaleResult()` inlined directly into the two handlers (no private method extraction)
- No new files, no namespace changes, no file moves — pure facade removal + constructor rewiring

**Steps:**
1. Rewrite 5 handler constructors — replace `RunMessageStateTools $stateTools` with focused deps:
   - StartRunHandler → `EventFactory`
   - AdvanceRunHandler → `EventFactory`
   - LlmStepResultHandler → `EventFactory`, `ToolCallExtractor`, `AgentMessageNormalizer`
   - ToolCallResultHandler → `EventFactory`, `ToolCallExtractor`, `AgentMessageNormalizer`
   - ApplyCommandHandler → `EventFactory`, `AgentMessageNormalizer`
2. Rewrite handler bodies — `$this->stateTools->x()` → `$this->eventFactory->x()` / `$this->toolCallExtractor->x()` / `$this->messageNormalizer->x()`
3. Inline `isStaleResult()` 2-line condition directly in LlmStepResultHandler and ToolCallResultHandler
4. Update 6 test files — replace `new RunMessageStateTools(...)` with individual deps
5. Delete `RunMessageStateTools.php`

**Files changed:** 5 handlers (edit), 1 facade (delete), 6 test files (edit)
- - Plan discussed: RunMessageStateTools removal, Symfony AI Domain coupling is intentional, isStaleResult inlined

## Task workflow update - 2026-06-03T16:09:54.694Z
- Moved TODO → IN-PROGRESS.
- Created branch task/04-refactor-agentcore-pipeline-dependencies.
- Created worktree /home/ineersa/projects/agent-core-worktrees/04-refactor-agentcore-pipeline-dependencies.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/04-refactor-agentcore-pipeline-dependencies.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/04-refactor-agentcore-pipeline-dependencies.

## Task workflow update - 2026-06-03T16:10:59.199Z
- - Claimed task, moved to IN-PROGRESS, worktree: /home/ineersa/projects/agent-core-worktrees/04-refactor-agentcore-pipeline-dependencies
- - All source and test files read, exact constructor signatures and call sites mapped
- - Launching fork for implementation

## Task workflow update - 2026-06-03T16:20:06.333Z
- Recorded fork run: ovne33fzhgdm
- Validation: castor test → 1587 tests, 4734 assertions, 0 failures; castor deptrac → 0 violations, 591 uncovered, 856 allowed; castor phpstan → 0 errors, 0 file_errors
- Summary: Implementation complete. Commit e08fb44f on branch task/04-refactor-agentcore-pipeline-dependencies.

**Changes (12 files: +90 / -159 lines):**
- Deleted `RunMessageStateTools.php` (84-line god-class facade)
- Rewrote 5 handler constructors to inject focused deps:
  - StartRunHandler → `EventFactory`
  - AdvanceRunHandler → `EventFactory`
  - LlmStepResultHandler → `EventFactory`, `ToolCallExtractor`, `AgentMessageNormalizer`
  - ToolCallResultHandler → `EventFactory`, `ToolCallExtractor`, `AgentMessageNormalizer`
  - ApplyCommandHandler → `EventFactory`, `AgentMessageNormalizer`
- Inlined `isStaleResult()` 2-line condition into LlmStepResultHandler and ToolCallResultHandler
- Updated 6 test files (constructor calls + imports)

**Validation:**
- Focused tests: 20 tests across 6 test classes, all PASS
- `castor test` — 1587 tests, 4734 assertions, PASS
- `castor deptrac` — 0 violations
- `castor phpstan` — 0 errors

**Notes:** Symfony AI Domain coupling is intentional and stays per user decision.
