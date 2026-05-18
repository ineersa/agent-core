# QH-08 Resume pending HITL question from session replay

## Goal
Plan: .pi/plans/tui-question-hitl-plan.md
Related plan: .pi/plans/runtime-transcript-vertical-slice-plan.md

Scope:
- On resume, rebuild transcript blocks and active HITL question state from runtime events/session state.
- If latest run is still waiting for human input, show the pending question widget again.
- Ensure answer after resume still sends answer_human and continues the run.
- Do not restore local TUI questions.

Exclusions:
- Do not implement the initial HITL binding; QH-07 owns that.
- Do not implement local question persistence.
- Do not implement fork/branch replay.

Dependencies: QH-07, RTVS-08.
Parallelizable with: QH-09 documentation prep only; avoid concurrent edits to session replay code.

## Acceptance criteria
- Resume while waiting shows the pending HITL question again.
- Answer after resume sends answer_human and continues the run.
- Local questions are not restored.
- Replay tests cover pending HITL reconstruction.
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
- Created: 2026-05-18T00:04:55.029Z
