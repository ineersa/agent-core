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
Status: CODE-REVIEW
Branch: task/rtvs-07-runtime-event-poller-projection-integration
Worktree: /home/ineersa/projects/agent-core-worktrees/rtvs-07-runtime-event-poller-projection-integration
Fork run: 9wzdfeva9wkq
PR URL: https://github.com/ineersa/agent-core/pull/34
PR Status: open
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

## Task workflow update - 2026-05-20T20:11:55.189Z
- Recorded fork run: luxrhtkqpoae
- Launched fork luxrhtkqpoae in RTVS-07 worktree to finish projection integration. Scope: preserve existing salvage changes, introduce boundary-safe TranscriptProjector contract/adapter rather than depfile exception, wire RuntimeEventPoller through projection into TranscriptBlockWidget/ChatScreen/TuiSessionState, preserve runtime-events.jsonl persistence, lastSeq dedupe and seq=0 transient behavior, update tests, validate with Castor, commit/push task branch.

## Task workflow update - 2026-05-20T20:12:13.011Z
- Fork run luxrhtkqpoae failed before doing work because the fork tool requires TMUX_PANE and this Pi session does not have TMUX_PANE set. No worktree changes came from that run. Continuing via pi-subagents worker instead, in the RTVS-07 worktree.

## Task workflow update - 2026-05-20T20:12:38.196Z
- Attempted pi-subagents worker fallback, but this installation has no `worker` agent configured (available agents appear to be scout/reviewer/researcher/architect/browser/librarian). Proceeding in the RTVS-07 worktree directly instead of launching another agent.

## Task workflow update - 2026-05-20T20:20:38.800Z
- Validation: castor test --filter=RuntimeEventPollerProjectionTest — OK (10 tests, 38 assertions); castor deptrac — 0 violations; castor phpstan --path=src/Tui --path=src/CodingAgent/Runtime — OK; castor cs-fix && castor check — quality: ok; Castor PHPUnit run passes 775 tests / 9498 assertions with 1 pre-existing PHPUnit notice in OutboxProjectionWorkerTest mock expectation; debug-only raw vendor/bin/phpunit --display-all-issues showed TUI e2e snapshot drift from intentional TranscriptBlockWidget prefixes and pre-existing/footer content changes; castor test excludes tui-e2e per project rules
- Summary: RTVS-07 implementation completed directly in worktree after fork/subagent fallback issues. Branch task/rtvs-07-runtime-event-poller-projection-integration now has commit 1ff0dceb (feat: wire runtime events into transcript block projection) pushed to origin. Key changes: added boundary-safe TranscriptProjectorInterface in Runtime/Contract and made ProjectionPipeline/TranscriptProjector implement it; RuntimeEventPoller now depends on the contract, persists runtime-events.jsonl, feeds RuntimeEvent arrays into the projector, preserves seq dedupe including seq=0 transient events, extracts footer usage from assistant.message_completed, removes Processing placeholder, and synchronizes projected TranscriptBlock updates back into TuiSessionState; TuiSessionState transcript now stores TranscriptBlock DTOs; ChatScreen renders with TranscriptBlockWidget; SubmitListener/InteractiveMode/SessionInitializer convert local user/system/command/resume messages to TranscriptBlock via new TranscriptBlockFactory while preserving transcript.jsonl writes for user/system/local messages; TickPollListener updates display with setTranscriptBlocks; added RuntimeEventPollerProjectionTest coverage.

## Task workflow update - 2026-05-20T20:21:04.854Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/rtvs-07-runtime-event-poller-projection-integration to origin.
- branch 'task/rtvs-07-runtime-event-poller-projection-integration' set up to track 'origin/task/rtvs-07-runtime-event-poller-projection-integration'.
- Created PR: https://github.com/ineersa/agent-core/pull/34
- Validation: castor test --filter=RuntimeEventPollerProjectionTest — OK (10 tests, 38 assertions); castor deptrac — 0 violations; castor phpstan --path=src/Tui --path=src/CodingAgent/Runtime — OK; castor cs-fix && castor check — quality: ok; 775 Castor PHPUnit tests pass with 1 pre-existing PHPUnit notice in OutboxProjectionWorkerTest; debug-only raw PHPUnit with tui-e2e showed expected snapshot drift due TranscriptBlockWidget prefixes/footer content; castor test excludes tui-e2e per project rules
- Summary: RTVS-07 completed and pushed at commit 1ff0dceb. RuntimeEventPoller now projects stable RuntimeEvents through TranscriptProjectorInterface into TranscriptBlock DTOs, synchronizes block updates into TuiSessionState, preserves runtime-events.jsonl persistence and lastSeq/seq=0 behavior, and TUI display now uses TranscriptBlockWidget via ChatScreen::setTranscriptBlocks(). Local UI/system/user messages are converted with TranscriptBlockFactory while preserving existing transcript.jsonl writes. Boundary-safe projector contract avoids TuiRuntime depending on ProjectionPipeline concrete classes.

## Task workflow update - 2026-05-20T20:34:12.901Z
- Recorded fork run: 39x9hc0dv8ma
- Launched fork 39x9hc0dv8ma to debug smoke-test failure where TUI shows no LLM responses after RTVS-07. Fork instructed to inspect ANSI snapshot .hatfield/tmp/tui/snapshots/snapshot-ansi-20260520-163153.ansi, session/runtime logs, run targeted tests plus castor test:llm-real and castor run:agent-test if available, identify exact failing path, implement fix/tests if safe, validate with Castor, commit and push branch.

## Task workflow update - 2026-05-20T21:03:36.271Z
- Recorded fork run: 9wzdfeva9wkq
- Launched fork 9wzdfeva9wkq in RTVS-07 worktree to implement minimal Symfony Messenger compiler-pass wiring without FrameworkBundle/HTTP. Scope: replace dead MessageBus([]) with synchronous HandleMessageMiddleware/HandlersLocator integration for agent.command.bus, agent.execution.bus, and agent.publisher.bus; register #[AsMessageHandler] autoconfiguration/tagging; add MessengerPass; add integration test proving dispatch reaches handlers/session events; validate with Castor and push branch if successful.
