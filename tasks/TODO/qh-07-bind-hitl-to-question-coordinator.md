# QH-07 Bind HITL runtime requests to TUI question coordinator

## Goal
Plan: .pi/plans/tui-question-hitl-plan.md
Related plan: .pi/plans/runtime-transcript-vertical-slice-plan.md

Scope:
- Runtime event poller/coordinator detects human_input.requested.
- Create QuestionRequest(source=agent_core, transcript=true) and show QuestionWidget/ApprovalWidget.
- Disable or reroute composer input while HITL is pending.
- Submit answer through AgentSessionClient::send(... answer_human ...).
- Clear/update widget on answer, cancellation, or rejection.

Exclusions:
- Do not implement session replay; QH-08 owns resume behavior.
- Do not import AgentCore internals into Tui.
- Do not persist local TUI questions as transcript blocks.

Dependencies: QH-03, QH-06, RTVS-07.
Parallelizable with: none after dependencies; avoid concurrent edits to runtime polling/input routing.

## Acceptance criteria
- A model/tool call to ask_human pauses the run and shows a TUI question.
- Composer cannot accidentally send a new user prompt while HITL is pending.
- Answering HITL sends answer_human and resumes the run.
- Action-required status is visible while HITL is pending.
- TUI does not import AgentCore internals and castor deptrac passes.

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
- Created: 2026-05-18T00:04:48.256Z
