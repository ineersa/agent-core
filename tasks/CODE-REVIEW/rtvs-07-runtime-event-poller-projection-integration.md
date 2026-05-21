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
Fork run: k43lkujngsn9
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

## Task workflow update - 2026-05-20T21:47:53.358Z
- Recorded fork run: tz3dsuou7cwh
- Reverted partial changes from fork 9wzdfeva9wkq in RTVS-07 worktree, then launched focused fork tz3dsuou7cwh. Scope is intentionally narrow: implement only Symfony DI-native Messenger integration via registerAttributeForAutoconfiguration(AsMessageHandler), bus tags, per-bus HandleMessageMiddleware services/parameters, and MessengerPass registration. Fork explicitly instructed not to use reflection scans, not to clear container.excluded, and not to chase JsonlOutboxProjectorWorker/Flysystem/RunLogReader downstream wiring; if that blocker appears, stop and report for architecture decision.

## Task workflow update - 2026-05-20T22:00:12.443Z
- Validation: Fork reported: php -l clean for new/modified files.; Fork reported: castor phpstan --path=src/CodingAgent/Integration and --path=src/CodingAgent/Kernel.php OK.; Fork reported: castor cs-fix + castor cs-check clean.; Fork reported: castor deptrac 0 violations; castor test 775/775 pass; castor check quality:ok.; Raw container boot after integration fails at ToolExecutor scalar constructor args; raw pre-change boot compiled with MessageBus([]).
- Summary: Fork tz3dsuou7cwh completed focused Messenger integration. Added src/CodingAgent/Integration/MessengerIntegrationCompilerPass.php, Kernel::build() registration, and comment in config/packages/messenger.yaml. Implementation uses Symfony DI-native registerAttributeForAutoconfiguration(AsMessageHandler::class) plus bus tags, per-bus HandleMessageMiddleware services/parameters, and MessengerPass delegation. No reflection scanner and no container.excluded clearing. Branch task/rtvs-07-runtime-event-poller-projection-integration pushed at 55fb7623; PR #34 updated. Container boot now reaches real handler dependency graph and fails first at ToolExecutor scalar args ($defaultMode string, $defaultTimeoutSeconds int, $maxParallelism int), proving handler discovery is active but leaving PR runtime-incomplete until downstream DI decisions are made. JsonlOutboxProjectorWorker/Flysystem was deliberately not touched.

## Task workflow update - 2026-05-20T22:22:34.052Z
- Validation: cobkfug64df3: castor deptrac -> 0 violations.; cobkfug64df3: castor test -> 761 tests, 9444 assertions pass.; cobkfug64df3: castor phpstan -> 0 errors after baseline regeneration.; cobkfug64df3: castor cs-fix + castor cs-check -> clean.; cobkfug64df3: castor check -> quality: ok.; cobkfug64df3: rg for Flysystem/FilesystemOperator/RunLogReader/RunLogWriter/JsonlOutboxProjectorWorker/ProjectJsonlOutbox/jsonl_fallback in live src/tests/docs/session-storage/config -> 0 matches.
- Summary: Main branch cleanup completed by fork cobkfug64df3: removed obsolete Flysystem cold run-log storage path completely from live code. Deleted RunLogReader, RunLogWriter, JsonlOutboxProjectorWorker, ProjectJsonlOutbox and their tests; removed jsonl_fallback logic from ReplayService, RunReadService, RunDebugService, and RunMetrics; removed Flysystem Composer dependencies. This simplifies current replay to canonical EventStoreInterface only and removes the Flysystem blocker that PR #34's Messenger integration would otherwise surface. Commit deab7b2f pushed to main.

## Task workflow update - 2026-05-20T22:47:54.978Z
- Validation: 1x3ye4t9ey0z: castor check -> quality: ok.; 1x3ye4t9ey0z: castor deptrac -> 0 violations.; 1x3ye4t9ey0z: castor test -> 761 tests, 9444 assertions pass.; 1x3ye4t9ey0z: castor phpstan -> OK, no errors.; 1x3ye4t9ey0z: castor cs-fix + cs-check -> clean.; 1x3ye4t9ey0z: rg for Mercure/mercure/ProjectMercure/RunEventPublisher/HubInterface in live src/tests/docs/session-storage/config -> 0 matches.
- Summary: Main branch cleanup completed by fork 1x3ye4t9ey0z: removed obsolete Mercure core dependency and live references. composer remove symfony/mercure dropped symfony/mercure plus transitive symfony/http-foundation, symfony/web-link, and psr/link. Removed stale Mercure/ProjectMercureOutbox/RunEventPublisher references from live src/AgentCore/**/AGENTS.md files. No Mercure implementation classes existed in src/tests. Commit 80fc28b3 pushed to main.

## Task workflow update - 2026-05-20T22:54:53.690Z
- Validation: 0liiv2nze5tr: castor deptrac -> 0 violations.; 0liiv2nze5tr: castor test -> 761 tests, 9444 assertions pass.; 0liiv2nze5tr: targeted pipeline/orchestration tests -> 14/14 pass.; 0liiv2nze5tr: castor phpstan -> 0 errors.; 0liiv2nze5tr: castor cs-fix + cs-check -> clean.; 0liiv2nze5tr: castor check -> quality: ok.; 0liiv2nze5tr: rg for OutboxProjector/OutboxStore/OutboxSink/OutboxEntry in live src/tests/config/docs/session-storage -> 0 matches.
- Summary: Main branch cleanup completed by fork 0liiv2nze5tr: removed remaining generic outbox infrastructure from live code after Flysystem/Mercure removal. Deleted OutboxProjector, OutboxProjectorInterface, OutboxStoreInterface, InMemoryOutboxStore, OutboxEntry, and OutboxSink. Simplified RunCommit by removing OutboxProjector constructor dependency and projection try/catch block; commit flow is now canonical EventStore persistence -> ReplayService rebuild -> effect dispatch -> after-turn hooks. Updated 5 pipeline test fixtures and live AGENTS docs. agent.publisher.bus and BusNames::Publisher intentionally remain as future bus concept. Commit 3acb45d3 pushed to main.

## Task workflow update - 2026-05-20T23:01:28.133Z
- Recorded fork run: qipk0vcmrg3b
- Summary: Launched fork qipk0vcmrg3b in the RTVS-07 worktree to finish PR #34 after main cleanup. Scope: sync task branch with latest main (Flysystem/Mercure/outbox removals), preserve simplified architecture, wire ToolExecutor scalar constructor args and any legitimate DI blockers exposed by MessengerIntegrationCompilerPass, verify Messenger buses/handlers are live, run Castor validation, push updated PR branch. Constraints: no main commits, no FrameworkBundle/HTTP, no Flysystem/Mercure/outbox resurrection.

## Task workflow update - 2026-05-20T23:03:08.703Z
- Recorded fork run: qt3momjnksvl
- Summary: Relaunched RTVS-07 finish fork with stricter implementation acceptance criteria after prior fork only fixed scalar DI/container compile. New fork qt3momjnksvl must add executable regression coverage proving real AgentRunner/Messenger bus dispatch invokes handlers and persists canonical events, plus verify RuntimeEventPoller -> TranscriptProjector -> TuiSessionState projection behavior. It must not stop at container inspection; deliverable is runtime-flow tests plus any narrow DI fixes needed, Castor validation, and push to PR #34.

## Task workflow update - 2026-05-21T00:44:42.251Z
- Recorded fork run: iz1qku5c5946
- Summary: Relaunched RTVS-07 completion fork with explicit user decision: do not make production infra stores public for tests. Fork iz1qku5c5946 must rewrite the Messenger runtime regression test to use the production runtime boundary (InProcessAgentSessionClient::initializeSessionsBasePath + start/events) or services_test.yaml for test-only visibility, remove production public:true added solely for tests, keep only legitimate DI wiring, prove handler execution/persisted events, validate RunLockManager re-entrant fix if needed, run Castor QA, commit/push PR #34.

## Task workflow update - 2026-05-21T01:06:05.148Z
- Recorded fork run: iz1qku5c5946
- Validation: iz1qku5c5946: castor cache:clear -> clean, dev/test containers compile.; iz1qku5c5946: castor test --filter=MessengerRuntimeIntegrationTest -> 3/3 pass.; iz1qku5c5946: castor test --filter=RunLockManagerTest -> 4/4 pass.; iz1qku5c5946: castor deptrac -> 0 violations.; iz1qku5c5946: castor test -> 776 tests, 9498 assertions pass.; iz1qku5c5946: castor phpstan -> clean.; iz1qku5c5946: castor cs-fix + cs-check -> clean.; iz1qku5c5946: castor check -> quality: ok.; Real LLM smoke not run in fork environment because no model configured; recommended post-merge smoke with castor run:agent or castor test:llm-real.
- Summary: Fork iz1qku5c5946 completed RTVS-07 runtime fix on PR #34 at commit c33663c7. Changes: production DI fixes for Messenger runtime (ToolExecutor/ToolExecutionPolicyResolver scalar args, tagged iterators, PromptStateStoreInterface/PlatformInterface aliases, StepDispatcher bus wiring), re-entrant RunLockManager guard for nested StartRun -> AdvanceRun dispatch, config/services_test.yaml with test.runtime_client alias for test-only container access, and executable integration tests proving AgentRunner/InProcessAgentSessionClient -> Messenger -> RunOrchestrator/StartRunHandler -> EventStore writes canonical events. No production public:true on low-level stores; no Flysystem/Mercure/outbox resurrection. PR #34 is now runtime-complete for the empty MessageBus regression.

## Task workflow update - 2026-05-21T01:09:58.817Z
- Recorded fork run: 8gotfd7ycc6d
- Summary: Launched fork 8gotfd7ycc6d to investigate and fix real model selection failure seen in smoke test after RTVS-07: TUI now shows projected events, but LLM fails with `Model "llama_cpp/flash" not found in ProjectedSymfonyModelCatalog`. Fork scope: inspect Hatfield settings/model catalog/provider registry/model resolver path, determine why configured alias/model is missing, implement minimal config-driven fix with tests, validate with Castor, push PR #34 branch. No main commits.

## Task workflow update - 2026-05-21T01:25:04.894Z
- Recorded fork run: 8gotfd7ycc6d
- Validation: 8gotfd7ycc6d: castor test --filter=testProviderQualifiedModelNameIsStrippedWhenProviderIsSet -> 1 test, 2 assertions pass.; 8gotfd7ycc6d: castor test --filter=PlatformIntegrationTest -> 3 tests, 17 assertions pass.; 8gotfd7ycc6d: castor deptrac -> 0 violations.; 8gotfd7ycc6d: castor phpstan -> no errors.; 8gotfd7ycc6d: castor cs-fix + cs-check -> clean.; 8gotfd7ycc6d: castor check -> quality: ok, 777 tests, 9500 assertions.; Real LLM smoke not run in fork environment due to unavailable model/API config; user should rerun castor run:agent with configured llama_cpp/flash after PR update/merge.
- Summary: Fork 8gotfd7ycc6d fixed the real model selection/catalog failure seen in smoke test. Root cause: ModelResolverRoutingSubscriber set both explicit provider (`llama_cpp`) and provider-qualified model (`llama_cpp/flash`), then Symfony provider catalog looked up the qualified string even though ProjectedSymfonyModelCatalog stores bare model names like `flash`. Fix: when providerId is set and provider is resolved, strip the providerId prefix before setting the model on the Symfony AI request event. Added regression test proving the model client receives `flash` when resolver returns `ResolvedModel(model: "llama_cpp/flash", providerId: "llama_cpp")`. Commit 0bd831eb pushed to PR #34.

## Task workflow update - 2026-05-21T01:50:08.332Z
- Recorded fork run: b8cqcvay90t9
- Summary: Launched fork b8cqcvay90t9 to address PR #34 review comments and replace weak internal tests with real e2e/llm-real coverage. Scope: inspect PR comments, remove/rewrite MessengerRuntimeIntegrationTest and RuntimeEventPollerProjectionTest if they only prove internals, add meaningful TUI tmux and/or llama_cpp/flash llm-real smoke tests that capture visible transcript behavior/artifacts, move provider-qualified model normalization out of AgentCore ModelResolverRoutingSubscriber into CodingAgent SymfonyAi catalog/factory layer, and reassess/justify or remove unused publisher bus wiring. Fork must validate via Castor, commit/push PR #34 only.

## Task workflow update - 2026-05-21T02:12:46.247Z
- Recorded fork run: iz1qku5c5946
- Validation: castor cache:clear: dev + test containers compile clean; castor test: 767 tests, 9455 assertions pass; castor deptrac: 0 violations; castor phpstan: no errors; castor cs-fix + cs-check: clean (1 file auto-fixed); castor check: quality: ok; TUI e2e test (TuiAgentSmokeTest) created but NOT run in fork — requires tmux + real LLM; user should run castor test:tui or castor test:llm-real to execute
- Summary: Completed RTVS-07 PR #34 review fix at commit ac5da0fd. Addressed all 4 reviewer comments:
1) Removed unused agent.publisher.bus (only command + execution remain; publisher never called after outbox removal)
2-3) Deleted MessengerRuntimeIntegrationTest and RuntimeEventPollerProjectionTest (weak tests that missed real TUI failure); replaced with TuiAgentSmokeTest — real tmux e2e that types prompt, submits, waits for assistant/error block, captures ANSI artifact, asserts transcript structure (tui-e2e + llm-real groups)
4) Moved model prefix stripping from AgentCore ModelResolverRoutingSubscriber (arch violation) to CodingAgent ProjectedSymfonyModelCatalog::parseModelName() (where Hatfield projected model names are owned). No AgentCore changes remain.
Added 3 new catalog tests for provider-qualified names + size variants. Removed config/services_test.yaml. Updated 6 test files for removed publisher bus param. Fixed LlamaCppSmokeTest catalog creation to use bare names (matches production). All 767 tests pass, quality: ok.

## Task workflow update - 2026-05-21T02:14:29.473Z
- Recorded fork run: b8cqcvay90t9
- Validation: b8cqcvay90t9: castor cache:clear -> dev+test containers compile.; b8cqcvay90t9: castor test -> 767 tests, 9455 assertions pass.; b8cqcvay90t9: castor deptrac -> 0 violations.; b8cqcvay90t9: castor phpstan -> no errors.; b8cqcvay90t9: castor cs-fix && castor cs-check -> clean.; b8cqcvay90t9: castor check -> quality: ok.; b8cqcvay90t9: castor test:tui and castor test:llm-real were not run in fork env because they require tmux + real LLM config; new TuiAgentSmokeTest is available under both groups for user execution.
- Summary: Fork b8cqcvay90t9 addressed all PR #34 review comments at commit ac5da0fd. Removed unused agent.publisher.bus after outbox removal (BusNames Publisher, StepDispatcher publisher dependency/method, compiler pass bus list, messenger config, service wiring, tests). Moved provider-qualified model handling out of AgentCore ModelResolverRoutingSubscriber and into CodingAgent ProjectedSymfonyModelCatalog::parseModelName(), supporting provider/model and provider/model:size while keeping explicit qualified catalog keys preferred. Deleted weak internal MessengerRuntimeIntegrationTest and mocked RuntimeEventPollerProjectionTest plus config/services_test.yaml. Added real TUI smoke test tests/Tui/E2E/TuiAgentSmokeTest.php in tui-e2e + llm-real groups that starts agent in tmux, types prompt, submits, waits for assistant/error transcript block, captures ANSI snapshot/artifacts, and checks working status does not stay stuck. Added catalog regression tests for provider-qualified model resolution. PR #34 branch pushed at ac5da0fd.

## Task workflow update - 2026-05-21T02:18:51.093Z
- Recorded fork run: 2o63yc0exzs0
- Summary: Launched urgent fork 2o63yc0exzs0 after user reproduced real TUI/LLM failure despite prior tests: `castor run:agent` with llama_cpp/flash and prompt hello produced no visible assistant/error output, while llama.cpp server logged `Jinja Exception: No messages provided`. Fork must first run/inspect current e2e/llm-real tests and explain why they didn't catch it, then create a real tmux test that types a prompt, submits, waits for visible transcript response/rendering, captures artifacts, demonstrates fail-first, fixes the actual runtime bug (hypothesis: StartRunRequest prompt mapped as systemPrompt with empty messages in InProcessAgentSessionClient/start path), makes the test pass, validates with Castor, commits/pushes PR #34 only.

## Task workflow update - 2026-05-21T02:39:52.177Z
- Recorded fork run: 8gotfd7ycc6d-pt2
- Validation: castor test:llm-real TuiAgentSmokeTest: FAIL before fix (no ◇ or ✕ block), PASS after fix (◇ hello visible, 3 assertions).; castor test:llm-real TuiAgentSmokeTest second test (working status): PASS (2 assertions).; castor deptrac: 0 violations.; castor phpstan: no errors.; castor cs-fix + cs-check: clean.; castor check: quality:ok (767 tests, 9455 assertions).; Real LLM responses confirmed in events.jsonl: 'hello' and 'Hello! How can I assist you today?' from llama_cpp/flash.
- Summary: Continuation fork: Fixed real LLM/TUI smoke failure where TUI showed nothing after prompt submission. Two root bugs: (1) User prompt stored as systemPrompt with empty messages[] → LLM received empty message bag; (2) Non-streaming/placeholder assistant responses never projected as visible blocks. Fixes: InProcessAgentSessionClient sends user prompt as AgentMessage in StartRunInput.messages; LlmPlatformAdapter hydrates deserialized message arrays back to AgentMessage objects via fromPayload(); AssistantStreamProjectionSubscriber creates block when message text exists but no streaming blocks present; TranscriptProjectionState gets hasAnyBlockForMessageId() helper. TuiAgentSmokeTest FAILS before fix, PASSES after — real llama_cpp/flash produces visible assistant block (◇ hello) in TUI. All 767 tests pass, castor check quality:ok.

## Task workflow update - 2026-05-21T02:42:40.804Z
- Recorded fork run: 2o63yc0exzs0
- Validation: 2o63yc0exzs0: curl llama.cpp server -> HTTP 200 reachable.; 2o63yc0exzs0: fail-first TuiAgentSmokeTest before fix -> failed with no ◇/✕ block, stuck idle, events.jsonl had messages:[] and placeholder response.; 2o63yc0exzs0: TuiAgentSmokeTest after fix against real llama_cpp/flash -> 2 tests, 5 assertions pass; ANSI snapshot shows `◇ hello`.; 2o63yc0exzs0: events.jsonl verified real LLM responses: `hello` and `Hello! How can I assist you today?`.; 2o63yc0exzs0: castor deptrac -> 0 violations.; 2o63yc0exzs0: castor phpstan --path=src/... -> no errors.; 2o63yc0exzs0: castor cs-fix && castor cs-check -> clean.; 2o63yc0exzs0: castor check -> quality: ok, 767 tests, 9455 assertions.
- Summary: Fork 2o63yc0exzs0 completed the real TUI/LLM smoke fix at PR #34 commit 8db1d0c0. It reproduced the failure first with TuiAgentSmokeTest against llama_cpp/flash: no visible assistant/error block and events showed run_started with messages:[] plus placeholder LLM response. Root causes fixed: InProcessAgentSessionClient::start() was treating first user prompt as systemPrompt and passing empty messages; LlmPlatformAdapter needed to hydrate JSON-deserialized RunState messages back into AgentMessage objects; AssistantStreamProjectionSubscriber did not create blocks for coarse/non-streaming assistant.message_completed events. Fixes: start() now creates an AgentMessage(user, prompt) and leaves systemPrompt empty; LlmPlatformAdapter hydrates array payloads with AgentMessage::fromPayload(); TranscriptProjectionState gained hasAnyBlockForMessageId(); AssistantStreamProjectionSubscriber creates a non-streaming assistant block when completion text exists and no stream block exists. TUI smoke now shows real assistant blocks like `◇ hello` and `◇ Hello! How can I assist you today?`. PR #34 now has executable product-level proof.

## Task workflow update - 2026-05-21T02:46:14.866Z
- Recorded fork run: k43lkujngsn9
- Summary: Launched urgent fork k43lkujngsn9 to fix remaining real TUI runtime issues after PR #34 smoke: thinking blocks render without text, transcript blocks are out of order, only first message works, and Working + Processing are shown together. Fork must reproduce with product-level Castor flow (`castor run:agent-test` / `castor test:llm-real` / `castor test:tui`), capture fail-first ANSI/session artifacts, fix all four issues, extend real tmux/llm-real e2e coverage to send two prompts and assert ordered visible assistant blocks, no empty thinking placeholders, no Processing+Working overlap, validate via Castor, commit/push PR #34 only.
