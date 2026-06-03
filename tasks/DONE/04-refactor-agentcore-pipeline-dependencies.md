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
Status: DONE
Branch: task/04-refactor-agentcore-pipeline-dependencies
Worktree: /home/ineersa/projects/agent-core-worktrees/04-refactor-agentcore-pipeline-dependencies
Fork run: ovne33fzhgdm
PR URL: https://github.com/ineersa/agent-core/pull/87
PR Status: merged
Started: 2026-06-03T16:09:54.694Z
Completed: 2026-06-03T16:34:44.570Z

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

## Task workflow update - 2026-06-03T16:29:02.808Z
- - CS fix: 2 files (import ordering + redundant parens), commit amended to 3a67b84f
- - Code review: APPROVE WITH SUGGESTIONS — no critical issues, no issues. Suggestions: (1) EventFactory.incrementStateVersion() layering concern tracked for future, (2) FQN inconsistency in CommandMailboxPolicyTest pre-existing, (3) test coverage gaps in LlmStepResultHandler/ToolCallResultHandler pre-existing.
- - Local validation: castor test 1587/4734 PASS, deptrac 0 violations, phpstan 0 errors, cs-check clean
Castor Check Status: passed
Castor Check Commit: 3a67b84f67abdd471824a722f5e0ba8cc3e601e3
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 240s
Castor Check Completed: 2026-06-03T16:30:15.953Z
Castor Check Output SHA256: 9519153e5435886880453c89184ee65af3eb0453a14c687dbbfee836c27eb337

## Task workflow update - 2026-06-03T16:30:20.550Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (240s timeout). Commit: 3a67b84f67ab.
- Pushed task/04-refactor-agentcore-pipeline-dependencies to origin.
- branch 'task/04-refactor-agentcore-pipeline-dependencies' set up to track 'origin/task/04-refactor-agentcore-pipeline-dependencies'.
- Created PR: https://github.com/ineersa/agent-core/pull/87

## Task workflow update - 2026-06-03T16:34:44.570Z
- Moved CODE-REVIEW → DONE.
- Merged task/04-refactor-agentcore-pipeline-dependencies into integration checkout.
- Merge made by the 'ort' strategy.
 .../Application/Pipeline/AdvanceRunHandler.php     |  9 +-
 .../Application/Pipeline/ApplyCommandHandler.php   | 17 ++--
 .../Application/Pipeline/LlmStepResultHandler.php  | 28 ++++---
 .../Application/Pipeline/RunMessageStateTools.php  | 97 ----------------------
 .../Application/Pipeline/StartRunHandler.php       |  5 +-
 .../Application/Pipeline/ToolCallResultHandler.php | 24 +++---
 .../Application/Pipeline/AdvanceRunHandlerTest.php |  8 +-
 .../Pipeline/ApplyCommandHandlerTest.php           | 21 +++--
 .../Pipeline/CommandMailboxPolicyTest.php          | 18 ++--
 .../Pipeline/LlmStepResultHandlerTest.php          |  8 +-
 .../Application/Pipeline/StartRunHandlerTest.php   |  6 +-
 .../Pipeline/ToolCallResultHandlerTest.php         |  8 +-
 12 files changed, 90 insertions(+), 159 deletions(-)
 delete mode 100644 src/AgentCore/Application/Pipeline/RunMessageStateTools.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/04-refactor-agentcore-pipeline-dependencies.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Summary: PR #87 merged by ineersa. Post-merge validation pending.
