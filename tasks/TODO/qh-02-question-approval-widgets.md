# QH-02 Basic QuestionWidget and ApprovalWidget rendering

## Goal
Plan: .pi/plans/tui-question-hitl-plan.md

Scope:
- Add simple TUI widgets for text, confirm, choice, and approval questions.
- Render QuestionOption as "label — description" when description exists.
- Use theme tokens; no rich forms or JSON Schema renderer.
- Add focused rendering tests or snapshots for representative requests.

Exclusions:
- Do not own queueing; QH-01 owns coordinator behavior.
- Do not implement input routing; QH-03 owns that.
- Do not dispatch runtime commands or answer_human.

Dependencies: QH-01.
Parallelizable with: QH-04, QH-05.

## Acceptance criteria
- Static question/approval requests render clearly.
- Choice options include descriptions.
- Widgets do not own queueing, answer submission, or runtime command dispatch.
- Focused rendering tests or snapshots cover text, confirm, choice, and approval requests.
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
- Created: 2026-05-18T00:04:15.305Z
