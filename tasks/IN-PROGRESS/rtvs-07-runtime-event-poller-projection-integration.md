# RTVS-07 RuntimeEventPoller projection integration

## Goal
Plan: .pi/plans/runtime-transcript-vertical-slice-plan.md

Scope:
- Refactor RuntimeEventPoller to stop formatting raw RuntimeEvent values directly into one-line TranscriptEntry values.
- Poll runtime events, persist them to runtime-events.jsonl, feed them into TranscriptProjector, and update TuiSessionState/transcript display using TranscriptBlock rendering.
- Preserve existing sequence de-duplication and working status behavior.
- Ensure the 'Processing...' placeholder is removed at the right time with the new projection path.

Exclusions:
- Do not implement new event mappings; RTVS-05 owns mapper normalization.
- Do not implement session replay; RTVS-08 owns resume/rebuild behavior.
- Do not build rich widgets.

Dependencies: RTVS-03, RTVS-04, RTVS-05, RTVS-06.
Parallelizable with: none after dependencies; avoid concurrent edits with RTVS-08.

## Acceptance criteria
- RuntimeEventPoller feeds events through TranscriptProjector before updating transcript UI.
- runtime-events.jsonl persistence still works and sequence de-duplication is preserved.
- Transcript display updates for streaming assistant text and basic tool/HITL/cancel blocks.
- Existing poller tests are updated or new focused tests cover the projection integration path.
- castor deptrac passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/rtvs-07-runtime-event-poller-projection-integration
Worktree: /home/ineersa/projects/agent-core-worktrees/rtvs-07-runtime-event-poller-projection-integration
Fork run:
PR URL:
PR Status:
Started: 2026-05-20T03:20:17.851Z
Completed:

## Work log
- Created: 2026-05-17T22:17:06.633Z

## Task workflow update - 2026-05-20T03:20:17.851Z
- Moved TODO → IN-PROGRESS.
- Created branch task/rtvs-07-runtime-event-poller-projection-integration.
- Created worktree /home/ineersa/projects/agent-core-worktrees/rtvs-07-runtime-event-poller-projection-integration.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/rtvs-07-runtime-event-poller-projection-integration.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/rtvs-07-runtime-event-poller-projection-integration.
- Summary: Starting RTVS-07: wire RuntimeEventPoller through TranscriptProjector and TranscriptBlock rendering, preserving runtime-events.jsonl persistence, sequence de-duplication, working status, and existing session behavior.
