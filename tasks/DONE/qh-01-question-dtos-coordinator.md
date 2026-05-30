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
Status: DONE
Branch: task/qh-01-question-dtos-coordinator
Worktree: /home/ineersa/projects/agent-core-worktrees/qh-01-question-dtos-coordinator
Fork run: lzw8ic1phy3k
PR URL: https://github.com/ineersa/agent-core/pull/76
PR Status: merged
Started: 2026-05-30T21:47:52.959Z
Completed: 2026-05-30T22:04:33.874Z

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

## Task workflow update - 2026-05-30T22:04:33.874Z
- Moved CODE-REVIEW → DONE.
- Merged task/qh-01-question-dtos-coordinator into integration checkout.
- Merge made by the 'ort' strategy.
 depfile.yaml                                   |   7 +
 src/Tui/Question/QuestionCoordinator.php       | 180 +++++++++++++
 src/Tui/Question/QuestionKind.php              |  20 ++
 src/Tui/Question/QuestionOption.php            |  21 ++
 src/Tui/Question/QuestionRequest.php           |  49 ++++
 src/Tui/Question/QuestionSource.php            |  20 ++
 src/Tui/Question/QuestionStatus.php            |  19 ++
 tests/Tui/Question/QuestionCoordinatorTest.php | 343 +++++++++++++++++++++++++
 tests/Tui/Question/QuestionRequestTest.php     | 114 ++++++++
 9 files changed, 773 insertions(+)
 create mode 100644 src/Tui/Question/QuestionCoordinator.php
 create mode 100644 src/Tui/Question/QuestionKind.php
 create mode 100644 src/Tui/Question/QuestionOption.php
 create mode 100644 src/Tui/Question/QuestionRequest.php
 create mode 100644 src/Tui/Question/QuestionSource.php
 create mode 100644 src/Tui/Question/QuestionStatus.php
 create mode 100644 tests/Tui/Question/QuestionCoordinatorTest.php
 create mode 100644 tests/Tui/Question/QuestionRequestTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/qh-01-question-dtos-coordinator.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Summary: PR #76 merged. QH-01 complete: 6 source files (3 enums, 2 DTOs, coordinator with FIFO queue + duplicate guard + try/finally), 29 tests/115 assertions, deptrac clean.
