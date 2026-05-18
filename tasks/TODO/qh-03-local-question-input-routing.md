# QH-03 Local TUI question input routing and action-required status

## Goal
Plan: .pi/plans/tui-question-hitl-plan.md

Scope:
- Route editor submit/keybindings to the active local QuestionRequest callback.
- Support y/n shortcuts for confirm/approval local prompts.
- Allow Esc to cancel local questions when a cancellation callback exists.
- Expose action-required status/footer/title state while any question is active.
- Ensure local questions never append runtime events or transcript blocks.

Exclusions:
- Do not implement HITL answer_human routing; QH-07 owns that.
- Do not implement ask_human tool.
- Do not write transcript/runtime projection data.

Dependencies: QH-01, QH-02.
Parallelizable with: QH-04, QH-05.

## Acceptance criteria
- Local TUI question can be answered through input routing.
- Normal prompt submission is blocked/rerouted while a local question is active.
- Local cancellation works when configured.
- Tests prove no runtime command/transcript write happens for local questions.
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
- Created: 2026-05-18T00:04:22.230Z
