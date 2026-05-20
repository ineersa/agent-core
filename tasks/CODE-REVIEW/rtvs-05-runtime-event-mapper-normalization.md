# RTVS-05 RuntimeEventMapper normalization

## Goal
Plan: .pi/plans/runtime-transcript-vertical-slice-plan.md

Scope:
- Update RuntimeEventMapper to map important AgentCore RunEvent types/payloads into stable runtime event names from RTVS-01.
- Cover first-slice events for user messages, assistant stream/result, tool lifecycle, waiting_human/HITL, cancellation, and errors where current AgentCore events expose enough data.
- Preserve raw AgentCore event type/payload as debug metadata only where useful.
- Keep TUI independent from AgentCore internals.

Exclusions:
- Do not create TranscriptBlock DTOs; RTVS-02 owns that.
- Do not implement projector/rendering/poller integration.
- Do not guess AI-13 usage/cost payloads beyond fields already exposed.

Dependencies: RTVS-01.
Parallelizable with: RTVS-02, RTVS-03, RTVS-04.

## Acceptance criteria
- RuntimeEventMapper emits stable event names for the vertical slice instead of relying only on raw passthrough.
- Mapping tests cover representative AgentCore events including waiting_human and cancellation.
- Raw event details, if preserved, are nested as debug metadata and not required by TUI rendering.
- No Tui code imports AgentCore Application/Infrastructure/Messenger namespaces.
- castor deptrac passes.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/rtvs-05-runtime-event-mapper-normalization
Worktree: /home/ineersa/projects/agent-core-worktrees/rtvs-05-runtime-event-mapper-normalization
Fork run: 1uirucdcjzc3
PR URL: https://github.com/ineersa/agent-core/pull/32
PR Status: open
Started: 2026-05-19T21:59:11.393Z
Completed:

## Work log
- Created: 2026-05-17T22:16:52.560Z

## Task workflow update - 2026-05-19T21:59:11.393Z
- Moved TODO → IN-PROGRESS.
- Created branch task/rtvs-05-runtime-event-mapper-normalization.
- Created worktree /home/ineersa/projects/agent-core-worktrees/rtvs-05-runtime-event-mapper-normalization.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/rtvs-05-runtime-event-mapper-normalization.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/rtvs-05-runtime-event-mapper-normalization.
- Summary: Starting implementation. RTVS-05 remains needed after the Symfony projection pipeline because the merged projector subscribes to stable `RuntimeEventTypeEnum` names, while current `RuntimeEventMapper` still passes raw AgentCore RunEvent type strings through unchanged. Scope is to normalize AgentCore events into stable runtime event names/payloads for the vertical slice while keeping TUI independent from AgentCore internals.

## Task workflow update - 2026-05-19T21:59:47.700Z
- Recorded fork run: layq6f28tvog
- Summary: Launched implementation fork in RTVS-05 worktree. Scope: normalize AgentCore RunEvent types/payloads in `RuntimeEventMapper` into stable `RuntimeEventTypeEnum` events for the merged Symfony transcript projection pipeline; add focused mapper tests; validate with Castor.

## Task workflow update - 2026-05-19T22:21:59.897Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/rtvs-05-runtime-event-mapper-normalization to origin.
- branch 'task/rtvs-05-runtime-event-mapper-normalization' set up to track 'origin/task/rtvs-05-runtime-event-mapper-normalization'.
- Created PR: https://github.com/ineersa/agent-core/pull/32
- Validation: castor test --filter=RuntimeEventMapperTest: OK (30 tests, 99 assertions); castor test --filter=TranscriptProjectorTest: OK (61 tests, 227 assertions); castor test --filter=TranscriptBlockTest: OK (30 tests, 154 assertions); castor test: OK (713 tests, 9341 assertions; 1 pre-existing PHPUnit notice); castor deptrac: OK (0 violations); castor phpstan --path=src/CodingAgent/Runtime: OK (0 errors); castor phpstan --path=tests/CodingAgent/Runtime: only pre-existing PHPUnit assertion static-analysis noise; castor cs-fix --path=src/CodingAgent/Runtime/Protocol --path=tests/CodingAgent/Runtime then castor cs-check: clean; git push origin task/rtvs-05-runtime-event-mapper-normalization: pushed
- Summary: RTVS-05 implementation complete on branch `task/rtvs-05-runtime-event-mapper-normalization` at commit `69ca92db`. `RuntimeEventMapper` now normalizes AgentCore `RunEvent` names/payloads into stable `RuntimeEventTypeEnum` runtime events for the Symfony projection pipeline, returns `?RuntimeEvent` so internal bookkeeping events can be skipped, and sends unknown/non-rendered events to stable `status.updated` fallback with debug metadata. Updated `InProcessAgentSessionClient` to skip null mappings and updated `RuntimeEventPoller` to match normalized `RuntimeEventTypeEnum` values. Added focused `RuntimeEventMapperTest` coverage for lifecycle, assistant result/failure/cancel, tool execution, HITL waiting_human, command/cancellation, skipped internal events, fallback debug metadata, and `toRunEventData()`. Key limitation documented: AgentCore currently does not emit fine-grained streaming deltas or dedicated user-message events, so mapper cannot produce `assistant.text_delta` or `user.message_submitted` until AgentCore event emission grows.

## Task workflow update - 2026-05-19T22:26:35.876Z
- Scout investigation after RTVS-05: Symfony AI does emit `DeltaInterface` values in `LlmPlatformAdapter::consumeStream()` (`TextDelta`, `ThinkingDelta`, `ToolCallStart`, `ToolInputDelta`, etc.). They are accumulated into `PlatformInvocationResult::$deltas` and used to build the final `AssistantMessage`, but `ExecuteLlmStepWorker` does not forward `$response->deltas()` into `LlmStepResult`; `LlmStepResult` has no deltas property, so `LlmStepResultHandler` can only emit coarse `llm_step_completed/failed/aborted` events. Existing hooks/subscribers are before-provider or after-turn-commit and cannot observe individual streaming deltas. Real streaming transcript updates need a new source-side stream observer/sink/hook at the platform invocation boundary, with careful sequencing/persistence design.

## Task workflow update - 2026-05-19T22:40:21.114Z
- Scope clarification before follow-up implementation: Symfony AI stream deltas should be treated as transient transport/presentation events, not canonical session storage. Do not persist token deltas into AgentCore events.jsonl. For in-process interactive transport, use an in-memory RuntimeEvent sink/queue. For process/headless/subagent transport, reuse the existing JSONL stdin/stdout protocol to emit transient RuntimeEvent values over stdout. Final/coarse RunEvent commits remain the durable replay source. Implementation target: add an AgentCore-facing stream observer interface, invoke it from LlmPlatformAdapter::consumeStream(), implement CodingAgent-side mapping from Symfony AI DeltaInterface values to RuntimeEventTypeEnum values, and route through transport-specific sinks without violating AgentCore/CodingAgent/TUI boundaries.

## Task workflow update - 2026-05-19T22:40:40.897Z
- Recorded fork run: 1uirucdcjzc3
- Launched fork run 1uirucdcjzc3 in RTVS-05 worktree to implement ephemeral Symfony AI stream delta transport: AgentCore stream observer interface, LlmPlatformAdapter observer callbacks, CodingAgent RuntimeEvent sink(s), in-process queueing, JSONL stdout sink for process/headless transport, delta→RuntimeEventTypeEnum mapping, DI wiring, focused tests, and Castor validation.

## Task workflow update - 2026-05-19T23:03:54.968Z
- Recorded fork run: 1uirucdcjzc3
- Validation: castor test: 736/736 tests passed, 9405 assertions (1 pre-existing PHPUnit notice reported by fork).; castor deptrac: 0 violations.; castor phpstan on touched source paths: 0 errors.; castor cs-fix/cs-check: clean.; castor check: full pipeline quality ok.
- Summary: Fork run 1uirucdcjzc3 completed RTVS-05 stream follow-up: implemented transient Symfony AI stream delta transport without canonical delta persistence. Added AgentCore LlmStreamObserverInterface, observer callbacks in LlmPlatformAdapter, CodingAgent RuntimeEventSinkInterface, InMemoryRuntimeEventSink, JsonlRuntimeEventSink, RuntimeEventStreamObserver delta→RuntimeEventTypeEnum mapping, InProcessAgentSessionClient transient drain, RuntimeEventPoller seq=0 handling, deptrac layer updates, and focused tests. Commit 6aa462cb pushed to branch task/rtvs-05-runtime-event-mapper-normalization; PR #32 updated.

## Task workflow update - 2026-05-20T00:02:14.072Z
- Validation: php bin/console list --no-interaction after cache clear: container compiles; compiled AgentCommand now constructs InProcessAgentSessionClient with InMemoryRuntimeEventSink.; castor test --filter='RuntimeEventStreamObserverTest|InMemoryRuntimeEventSinkTest': 23 tests, 64 assertions OK.; castor deptrac: 0 violations.; castor cs-check: clean.
- Summary: Parent follow-up after fork: found runtime DI wiring gap while sanity-checking compiled container — observer/sink services were removed as unused and InProcessAgentSessionClient was constructed without a transient sink. Patched PR #32 with explicit service aliases in config/services.yaml: RuntimeEventSinkInterface -> InMemoryRuntimeEventSink, LlmStreamObserverInterface -> RuntimeEventStreamObserver, plus concrete service definitions. Committed/pushed fix as 542fe738 on task/rtvs-05-runtime-event-mapper-normalization.
