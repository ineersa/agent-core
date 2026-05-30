# QH-01 Question request DTOs and coordinator queue

## Goal
Plan: .pi/plans/tui-question-hitl-plan.md

Scope:
- Add QuestionRequest, QuestionOption, QuestionSource, QuestionKind, and QuestionStatus under src/Tui/Question/.
- Add QuestionCoordinator with one active request and a small FIFO queue.
- Support local callbacks and HITL request metadata, but do not send runtime commands yet.
- Expose actionRequired/current request read methods for widgets/status.

Exclusions:
- Do not implement widgets/rendering; QH-02 owns that.
- Do not implement input routing; QH-03 owns that.
- Do not implement ask_human; QH-04 owns that.
- Do not persist transcript/runtime events.

Dependencies: none.
Parallelizable with: QH-04.

## Acceptance criteria
- Local request can be enqueued, activated, answered, and cleared.
- Multiple requests are displayed one at a time in FIFO order.
- DTOs do not depend on AgentCore internals.
- Tests cover queueing and source-aware routing decisions without rendering.
- castor deptrac passes.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/qh-01-question-dtos-coordinator
Worktree: /home/ineersa/projects/agent-core-worktrees/qh-01-question-dtos-coordinator
Fork run: lzw8ic1phy3k
PR URL: https://github.com/ineersa/agent-core/pull/76
PR Status: open
Started: 2026-05-30T21:47:52.959Z
Completed:

## Work log
- Created: 2026-05-18T00:04:09.620Z

## Task workflow update - 2026-05-30T21:47:52.959Z
- Moved TODO → IN-PROGRESS.
- Created branch task/qh-01-question-dtos-coordinator.
- Created worktree /home/ineersa/projects/agent-core-worktrees/qh-01-question-dtos-coordinator.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/qh-01-question-dtos-coordinator.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/qh-01-question-dtos-coordinator.

## Task workflow update - 2026-05-30T21:51:12.952Z
- Recorded fork run: lzw8ic1phy3k
- Validation: castor deptrac → 0 violations; castor test --filter=Question → 28 tests, 114 assertions, 0 failures
- Summary: Implemented QH-01: 6 source files (3 enums, 2 DTOs, 1 coordinator) under src/Tui/Question/, 2 test files with 28 tests/114 assertions, deptrac TuiQuestion layer added. All validation passes.

## Task workflow update - 2026-05-30T21:51:26.436Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/qh-01-question-dtos-coordinator to origin.
- branch 'task/qh-01-question-dtos-coordinator' set up to track 'origin/task/qh-01-question-dtos-coordinator'.
- Created PR: https://github.com/ineersa/agent-core/pull/76
