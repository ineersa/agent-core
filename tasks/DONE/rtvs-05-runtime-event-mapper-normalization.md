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
Status: DONE
Branch: task/rtvs-05-runtime-event-mapper-normalization
Worktree: /home/ineersa/projects/agent-core-worktrees/rtvs-05-runtime-event-mapper-normalization
Fork run: 4ep1khmw3x94
PR URL: https://github.com/ineersa/agent-core/pull/32
PR Status: merged
Started: 2026-05-19T21:59:11.393Z
Completed: 2026-05-20T02:50:32.417Z

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

## Task workflow update - 2026-05-20T00:20:54.701Z
- Review feedback: current RTVS-05 stream/mapping implementation is architecturally too manual. RuntimeEventStreamObserver uses a central instanceof match over Symfony AI DeltaInterface types, and RuntimeEventMapper uses many private normalize* array functions plus extractAssistantText() custom payload walking. Desired refactor: use Symfony EventDispatcher/EventSubscriberInterface for stream delta and RunEvent normalization pipelines, split handlers/subscribers by event family/type, use semantic DTOs for normalized runtime payloads and Symfony Serializer for DTO→array conversion, and reuse Symfony AI types/methods (e.g. AssistantMessage::asText(), ToolCall accessors) at the source boundary instead of reparsing normalized arrays where possible. Keep RuntimeEvent as the transport DTO, but avoid monolithic mapper/observer logic.

## Task workflow update - 2026-05-20T00:21:20.293Z
- Recorded fork run: 4ep1khmw3x94
- Launched corrective fork run 4ep1khmw3x94 in RTVS-05 worktree to refactor PR #32 away from monolithic instanceof/array-normalization implementation. Target: Symfony EventDispatcher/EventSubscriberInterface pipelines for stream deltas and RunEvent→RuntimeEvent mapping, split subscribers by family/type, payload DTOs + Symfony Serializer normalization where practical, reuse Symfony AI AssistantMessage::asText()/ToolCall types at source boundaries, preserve transient delta semantics and existing protocol behavior.

## Task workflow update - 2026-05-20T01:48:20.503Z
- Recorded fork run: 4ep1khmw3x94
- Validation: castor test: 735/735 tests passed, 9400 assertions (1 pre-existing notice).; castor test --filter=RuntimeEventMapperTest: 32 tests, 103 assertions OK.; castor test --filter=StreamDeltaSubscriberTest: 15 tests, 46 assertions OK.; castor deptrac: 0 violations.; castor phpstan on touched paths: no errors.; castor cs-fix/cs-check: clean.; castor check: quality ok.; php bin/console list --no-interaction: container compiles.
- Summary: Fork run 4ep1khmw3x94 completed corrective RTVS-05 refactor. PR #32 now uses Symfony EventDispatcher/EventSubscriberInterface architecture for both stream deltas and RunEvent→RuntimeEvent mapping. Replaced monolithic RuntimeEventStreamObserver with LlmStreamDispatchObserver plus AssistantTextStreamSubscriber, AssistantThinkingStreamSubscriber, ToolCallStreamSubscriber and stream event DTOs. Refactored RuntimeEventMapper from ~420-line god mapper into ~75-line facade dispatching RunEventMappingEvent to five family subscribers: RunLifecycleMappingSubscriber, AssistantMessageMappingSubscriber, ToolExecutionMappingSubscriber, HitlMappingSubscriber, CancelAndFallbackMappingSubscriber. Added source-side text key to llm_step_completed via AssistantMessage::asText(), with legacy fallback retained. Commit 1a0ad044 pushed to task/rtvs-05-runtime-event-mapper-normalization; PR #32 updated.

## Task workflow update - 2026-05-20T02:48:01.223Z
- Validation: rm -rf var/cache/dev && php bin/console list --no-interaction: OK.; grep compiled container: RuntimeEventMapper receives shared event_dispatcher; mapping and stream subscribers registered as EventDispatcher listeners.
- Summary: Parent sanity check after fork 4ep1khmw3x94: verified container wiring after cache clear. Compiled AgentCommand constructs RuntimeEventMapper with shared event_dispatcher and InProcessAgentSessionClient with InMemoryRuntimeEventSink. Compiled event_dispatcher registers mapping subscribers for run_started/turn_advanced/agent_end/llm_step_completed and stream subscribers for llm_stream.start and Symfony AI TextDelta FQCN. This confirms the subscriber refactor is wired, not only unit-tested.

## Task workflow update - 2026-05-20T02:50:32.417Z
- Moved CODE-REVIEW → DONE.
- Merged task/rtvs-05-runtime-event-mapper-normalization into integration checkout.
- Merge made by the 'ort' strategy.
 config/services.yaml                               |  21 +
 depfile.yaml                                       |   6 +-
 .../Application/Pipeline/LlmStepResultHandler.php  |   1 +
 .../Contract/Hook/LlmStreamObserverInterface.php   |  58 +++
 .../SymfonyAi/LlmPlatformAdapter.php               |  71 ++-
 .../Runtime/Contract/RuntimeEventSinkInterface.php |  22 +
 .../Runtime/InProcess/InMemoryRuntimeEventSink.php |  60 +++
 .../InProcess/InProcessAgentSessionClient.php      |  14 +-
 .../Mapping/AssistantMessageMappingSubscriber.php  | 141 ++++++
 .../Mapping/CancelAndFallbackMappingSubscriber.php | 100 ++++
 .../Runtime/Mapping/HitlMappingSubscriber.php      |  56 +++
 .../Mapping/RunLifecycleMappingSubscriber.php      |  89 ++++
 .../Mapping/ToolExecutionMappingSubscriber.php     |  75 +++
 .../Runtime/Process/JsonlRuntimeEventSink.php      |  47 ++
 .../Runtime/Protocol/RunEventMappingEvent.php      |  36 ++
 .../Runtime/Protocol/RuntimeEventMapper.php        |  49 +-
 .../Stream/AssistantTextStreamSubscriber.php       | 117 +++++
 .../Stream/AssistantThinkingStreamSubscriber.php   | 153 ++++++
 .../Runtime/Stream/LlmStreamDispatchObserver.php   |  68 +++
 .../Runtime/Stream/RuntimeStreamDeltaEvent.php     |  34 ++
 .../Runtime/Stream/RuntimeStreamLifecycleEvent.php |  21 +
 .../Runtime/Stream/ToolCallStreamSubscriber.php    | 132 +++++
 src/Tui/Runtime/RuntimeEventPoller.php             |  72 ++-
 .../InProcess/InMemoryRuntimeEventSinkTest.php     |  85 ++++
 .../CodingAgent/Runtime/RuntimeEventMapperTest.php | 538 +++++++++++++++++++++
 .../Runtime/Stream/StreamDeltaSubscriberTest.php   | 287 +++++++++++
 26 files changed, 2323 insertions(+), 30 deletions(-)
 create mode 100644 src/AgentCore/Contract/Hook/LlmStreamObserverInterface.php
 create mode 100644 src/CodingAgent/Runtime/Contract/RuntimeEventSinkInterface.php
 create mode 100644 src/CodingAgent/Runtime/InProcess/InMemoryRuntimeEventSink.php
 create mode 100644 src/CodingAgent/Runtime/Mapping/AssistantMessageMappingSubscriber.php
 create mode 100644 src/CodingAgent/Runtime/Mapping/CancelAndFallbackMappingSubscriber.php
 create mode 100644 src/CodingAgent/Runtime/Mapping/HitlMappingSubscriber.php
 create mode 100644 src/CodingAgent/Runtime/Mapping/RunLifecycleMappingSubscriber.php
 create mode 100644 src/CodingAgent/Runtime/Mapping/ToolExecutionMappingSubscriber.php
 create mode 100644 src/CodingAgent/Runtime/Process/JsonlRuntimeEventSink.php
 create mode 100644 src/CodingAgent/Runtime/Protocol/RunEventMappingEvent.php
 create mode 100644 src/CodingAgent/Runtime/Stream/AssistantTextStreamSubscriber.php
 create mode 100644 src/CodingAgent/Runtime/Stream/AssistantThinkingStreamSubscriber.php
 create mode 100644 src/CodingAgent/Runtime/Stream/LlmStreamDispatchObserver.php
 create mode 100644 src/CodingAgent/Runtime/Stream/RuntimeStreamDeltaEvent.php
 create mode 100644 src/CodingAgent/Runtime/Stream/RuntimeStreamLifecycleEvent.php
 create mode 100644 src/CodingAgent/Runtime/Stream/ToolCallStreamSubscriber.php
 create mode 100644 tests/CodingAgent/Runtime/InProcess/InMemoryRuntimeEventSinkTest.php
 create mode 100644 tests/CodingAgent/Runtime/RuntimeEventMapperTest.php
 create mode 100644 tests/CodingAgent/Runtime/Stream/StreamDeltaSubscriberTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/rtvs-05-runtime-event-mapper-normalization.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Validation: PR #32 state verified via gh: MERGED at 2026-05-20T02:50:03Z, merge commit f084179ff9f55ba96a3b26a36f2a3b35ea6288b8.; Fork validation before merge: castor check quality ok; 735/735 tests passed; deptrac 0 violations; PHPStan clean; CS clean; container compiles.
- Summary: RTVS-05 completed and PR #32 merged. RuntimeEventMapper normalization plus transient Symfony AI stream delta transport are now merged, including the corrective Symfony EventDispatcher/EventSubscriberInterface refactor for stream deltas and RunEvent→RuntimeEvent mapping. PR #32 merge commit: f084179ff9f55ba96a3b26a36f2a3b35ea6288b8.
