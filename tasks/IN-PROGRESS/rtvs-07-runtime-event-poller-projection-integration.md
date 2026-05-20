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
Fork run: 5ntyvwxaig26
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

## Task workflow update - 2026-05-20T03:20:35.895Z
- Recorded fork run: hs07lnbvek7t
- Launched fork hs07lnbvek7t in worktree /home/ineersa/projects/agent-core-worktrees/rtvs-07-runtime-event-poller-projection-integration to wire RuntimeEventPoller through TranscriptProjector and TranscriptBlock rendering, preserving runtime-events.jsonl persistence, seq de-duplication, transient seq=0 handling, and working status behavior.

## Task workflow update - 2026-05-20T03:22:38.383Z
- Recorded fork run: 5ntyvwxaig26
- Previous RTVS-07 fork hs07lnbvek7t superseded at user request. Worktree was verified clean on branch task/rtvs-07-runtime-event-poller-projection-integration before relaunch. Relaunched implementation fork 5ntyvwxaig26 with narrower instructions: keep change clean, preserve runtime-events.jsonl persistence and seq de-dup/transient seq=0 behavior, feed RuntimeEvents through TranscriptProjector, update TUI using TranscriptBlock rendering, no new mappings/replay/rich widgets, Castor-only validation.

## Task workflow update - 2026-05-20T04:01:16.644Z
- User requested no additional fork launches; prepare/preserve the RTVS-07 task context instead. Main/integration checkout was cleaned after failed fork contamination; RTVS-07 worktree remains with salvageable uncommitted partial changes only: modified src/Tui/Runtime/RuntimeEventPoller.php and new tests/Tui/Runtime/RuntimeEventPollerProjectionTest.php. Important caveat for the next implementer: the partial poller change injects concrete CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector directly into TuiRuntime, which likely violates deptrac because TuiRuntime currently allows AppRuntimeContract/AppRuntimeProjection/AppSession/TuiTranscript/TuiScreen/TuiTheme/SymfonyTui but not AppRuntimeProjectionPipeline. Preferred task direction: introduce/choose a boundary-safe contract or adapter (e.g. AppRuntimeContract interface implemented by ProjectionPipeline facade, or a TuiRuntime-local projection coordinator wired from Application) instead of adding an ad-hoc depfile exception. Also ensure ChatScreen/TuiSessionState/SubmitListener/TickPollListener are updated coherently to render TranscriptBlockWidget while preserving old TranscriptEntry paths for initial/resume/local command messages until RTVS-08 replaces replay. Do not relaunch a fork unless explicitly asked.
