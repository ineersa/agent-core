# QH-06 HITL runtime projection payload support

## Goal
Plan: .pi/plans/tui-question-hitl-plan.md
Related plan: .pi/plans/runtime-transcript-vertical-slice-plan.md

Scope:
- Map AgentCore waiting_human to human_input.requested with question payload fields.
- Map accepted/rejected human responses to human_input.answered|rejected and approval equivalents where useful.
- Ensure TranscriptProjector creates question/approval transcript blocks only for HITL, not local TUI prompts.
- Include question_id, request_id, header, prompt, schema, choices, default, allow_other, secret, tool_call_id, and tool_name where available.

Exclusions:
- Do not implement local TUI widgets or input routing.
- Do not bind runtime requests to QuestionCoordinator; QH-07 owns that.
- Do not implement ask_human tool; QH-04/QH-05 own tool and compatibility.

Dependencies: QH-05, RTVS-01, RTVS-02, RTVS-04, RTVS-05.
Parallelizable with: QH-03 if not done.

## Acceptance criteria
- HITL question appears in runtime projection and transcript projection.
- Local TUI question code path cannot create transcript blocks.
- Projector/replay tests cover requested and answered HITL question.
- Runtime payload preserves normalized choices with label and description.
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
- Created: 2026-05-18T00:04:41.447Z
