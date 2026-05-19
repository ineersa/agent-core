# RTVS-04 TranscriptProjector tool, HITL, and cancellation support

## Goal
Plan: .pi/plans/runtime-transcript-vertical-slice-plan.md
Related plan: .pi/plans/tui-question-hitl-plan.md

Scope:
- Extend TranscriptProjector with tool_call.started/arguments_delta/arguments_completed and tool_execution.started/output_delta/completed/failed/cancelled.
- Create small preview/final blocks for tool execution; do not build rich widgets.
- Project human_input.requested and approval.requested into question/approval transcript blocks.
- Project human_input.answered/rejected and approval.approved/rejected into block status updates.
- Project turn/run cancellation and operation cancellation into cancelled/error blocks and mark active streaming blocks incomplete.

Exclusions:
- Do not implement local TUI question widgets or HITL input binding; those belong to the TUI question plan.
- Do not implement bash/background tools.
- Do not modify RuntimeEventPoller integration; that is RTVS-07.

Dependencies: RTVS-01, RTVS-02.
Parallelizable with: RTVS-03, RTVS-05.

## Acceptance criteria
- Projector creates stable tool preview/final blocks keyed by tool_call_id.
- Projector creates HITL question/approval blocks only from AgentCore HITL runtime events, not local TUI questions.
- Cancellation events create visible cancelled blocks and mark active streaming blocks as incomplete/non-streaming.
- Focused tests cover tool lifecycle, HITL requested/answered, approval requested/rejected, and run cancellation.
- castor deptrac passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/rtvs-04-transcript-projector-tool-hitl-cancel
Worktree: /home/ineersa/projects/agent-core-worktrees/rtvs-04-transcript-projector-tool-hitl-cancel
Fork run: l7miiivya34a
PR URL:
PR Status:
Started: 2026-05-19T14:12:07.707Z
Completed:

## Work log
- Created: 2026-05-17T22:16:45.183Z

## Task workflow update - 2026-05-19T14:12:07.707Z
- Moved TODO → IN-PROGRESS.
- Created branch task/rtvs-04-transcript-projector-tool-hitl-cancel.
- Created worktree /home/ineersa/projects/agent-core-worktrees/rtvs-04-transcript-projector-tool-hitl-cancel.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/rtvs-04-transcript-projector-tool-hitl-cancel.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/rtvs-04-transcript-projector-tool-hitl-cancel.

## Task workflow update - 2026-05-19T14:13:02.228Z
- Recorded fork run: l7miiivya34a
- Summary: Launched implementation fork for RTVS-04 in worktree `/home/ineersa/projects/agent-core-worktrees/rtvs-04-transcript-projector-tool-hitl-cancel`. Scope: TranscriptProjector tool/HITL/approval/cancellation support; focused tests and deptrac; commit on task branch.
