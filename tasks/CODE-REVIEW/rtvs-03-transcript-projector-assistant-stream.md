# RTVS-03 TranscriptProjector assistant and user stream support

## Goal
Plan: .pi/plans/runtime-transcript-vertical-slice-plan.md

Scope:
- Add TranscriptProjector under src/CodingAgent/Runtime/Projection.
- Consume ordered RuntimeEvent instances and maintain current transcript block state.
- Implement user.message_submitted -> UserMessage block.
- Implement assistant.message_started/text_started/text_delta/text_completed/thinking_started/thinking_delta/thinking_completed/message_completed/message_failed.
- Ensure projector can be replayed from the beginning to rebuild the same block list.

Exclusions:
- Do not implement tool/HITL/cancel handling; that is RTVS-04.
- Do not modify RuntimeEventMapper; that is RTVS-05.
- Do not modify RuntimeEventPoller integration; that is RTVS-07.

Dependencies: RTVS-01, RTVS-02.
Parallelizable with: RTVS-04, RTVS-05.

## Acceptance criteria
- Projector creates and updates user, assistant text, assistant thinking, and assistant error blocks from runtime events.
- Streaming deltas append deterministically without duplicating text on replay.
- Message completion marks assistant/thinking blocks non-streaming.
- Focused projector tests cover text delta replay, thinking delta replay, and assistant failure.
- castor deptrac passes.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/rtvs-03-transcript-projector-assistant-stream
Worktree: /home/ineersa/projects/agent-core-worktrees/rtvs-03-transcript-projector-assistant-stream
Fork run: m9zuvvqdpw58
PR URL: https://github.com/ineersa/agent-core/pull/30
PR Status: open
Started: 2026-05-19T14:11:57.488Z
Completed:

## Work log
- Created: 2026-05-17T22:16:34.749Z

## Task workflow update - 2026-05-19T14:11:57.488Z
- Moved TODO → IN-PROGRESS.
- Created branch task/rtvs-03-transcript-projector-assistant-stream.
- Created worktree /home/ineersa/projects/agent-core-worktrees/rtvs-03-transcript-projector-assistant-stream.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/rtvs-03-transcript-projector-assistant-stream.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/rtvs-03-transcript-projector-assistant-stream.

## Task workflow update - 2026-05-19T14:13:02.228Z
- Recorded fork run: m9zuvvqdpw58
- Summary: Launched implementation fork for RTVS-03 in worktree `/home/ineersa/projects/agent-core-worktrees/rtvs-03-transcript-projector-assistant-stream`. Scope: TranscriptProjector assistant/user stream support; focused tests and deptrac; commit on task branch.

## Task workflow update - 2026-05-19T14:31:30.224Z
- Recorded fork run: m9zuvvqdpw58
- Validation: castor test --filter=TranscriptProjectorTest: OK (26 tests, 96 assertions); castor deptrac: OK (0 violations); castor test: OK (648 tests, 9111 assertions; 1 pre-existing PHPUnit notice)
- Summary: RTVS-03 implementation complete on branch `task/rtvs-03-transcript-projector-assistant-stream` at commit `d98fbc19`. Added `TranscriptProjector` for user message and assistant text/thinking stream projection using array runtime events to preserve `AppRuntimeProjection: ~` deptrac boundary. Added focused `TranscriptProjectorTest` coverage for user blocks, assistant text/thinking deltas, replay determinism, completion, failure, unknown/orphan events, reset, and metadata. Notes: RTVS-04 will likely conflict additively in `TranscriptProjector::accept()` and `TranscriptProjectorTest.php`; projector intentionally uses raw event type strings rather than importing protocol enum.

## Task workflow update - 2026-05-19T14:31:46.882Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/rtvs-03-transcript-projector-assistant-stream to origin.
- branch 'task/rtvs-03-transcript-projector-assistant-stream' set up to track 'origin/task/rtvs-03-transcript-projector-assistant-stream'.
- Created PR: https://github.com/ineersa/agent-core/pull/30
