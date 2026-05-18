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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-18T00:04:09.620Z
