---
status: completed
date: 2026-05-04
duration: ~90 min execution (incl. test fixes + refactoring)
quality: pass (cs-fix=0, phpstan=0/0, tests=70/7341, index=133)
phases:
  phase-1-bundle-skeleton: completed
  phase-2-api-transport: completed
  phase-3-console-commands: completed
  phase-4-disconnected-infrastructure: completed
  phase-5-dead-contracts-stores: completed
  phase-6-dead-application-infrastructure: completed
  phase-7-dead-domain-messages: completed
  phase-8-baseline-regeneration: completed
  phase-9-rename-orchestrator-to-pipeline: completed
  phase-10-split-runmessagestatetools: completed
---

# Plan 01: Cleanup — Remove Bundle & Non-Core Responsibility

**Goal:** Strip `agent-core` down to a clean library: domain model + pipeline + handlers + hook system + storage contracts + Symfony AI bridge. Everything bundle-specific, schema/API-transport, dead code, and unused abstractions goes.

**Duration:** ~90 min
**Final state:** cs-fix clean, phpstan 0 errors (139 baseline), 70 tests/7341 assertions passing, 133 source PHP files

---

## Phase 1: Delete Bundle Skeleton

| Remove | Reason |
|--------|--------|
| `src/AgentLoopBundle.php` | No kernel bundle |
| `src/DependencyInjection/` (entire dir) | AgentLoopExtension, Configuration — bundle wiring |
| `config/` (entire dir) | services.php, messenger.php, doctrine.php — DI wiring |

---

## Phase 2: Delete API Transport Layer

| Remove | Reason |
|--------|--------|
| `src/Schema/CommandPayloadNormalizer.php` | Dead (zero consumers) |
| `src/Schema/EventNameMap.php` | Only used by RunEventSerializer (removed) |
| `src/Schema/RunEventSerializer.php` | Only used by Command/ + Mercure/ (both removed) |
| `src/Schema/RunStreamEvent.php` | Only used by RunEventSerializer |
| **Keep** `src/Schema/EventPayloadNormalizer.php` | Used by RunLogReader + RunLogWriter |
| **Keep** `src/Schema/SchemaVersion.php` | Used by EventPayloadNormalizer |

---

## Phase 3: Delete Console Commands

| Remove | Reason |
|--------|--------|
| `src/Command/` (entire dir) | Console commands for bundle — no app to serve |

6 files: `AgentLoopHealthCommand`, `AgentLoopResumeStaleRunsCommand`, `AgentLoopRunInspectCommand`, `AgentLoopRunRebuildHotStateCommand`, `AgentLoopRunReplayCommand`, `AgentLoopRunTailCommand`

---

## Phase 4: Delete Disconnected Infrastructure

| Remove | Reason |
|--------|--------|
| `src/Infrastructure/Mercure/` (entire dir) | Streaming with no HTTP controller to connect |
| `src/Infrastructure/Security/` (entire dir) | `AllowAllAuthorizeRun` — only consumer of dead `AuthorizeRunInterface` |
| `src/Infrastructure/Doctrine/Migrations/Version20260418000100.php` | Migration with no Doctrine entities |
| `src/Infrastructure/Doctrine/Migrations/docs/` | Migration docs |

**Keep** `src/Infrastructure/Doctrine/` directory (for future DB stores).

---

## Phase 5: Delete Dead Contracts & Stores

| Remove | Reason |
|--------|--------|
| `src/Contract/Api/AuthorizeRunInterface.php` | HTTP API auth — no API exists |
| `src/Contract/RunAccessStoreInterface.php` | No consumer (InMemoryRunAccessStore never wired) |
| `src/Contract/ArtifactStoreInterface.php` | No consumer (LocalArtifactStore never wired, ReplayService doesn't use it) |
| `src/Contract/Hook/FollowUpMessagesProviderInterface.php` | Unused hook interface — no code checks for it |
| `src/Contract/Hook/SteeringMessagesProviderInterface.php` | Unused hook interface — no code checks for it |
| `src/Infrastructure/Storage/InMemoryRunAccessStore.php` | Impl of dead interface |
| `src/Infrastructure/Storage/LocalArtifactStore.php` | Impl of dead interface |
| `src/Domain/Artifact/ArtifactMetadata.php` | Only used by dead ArtifactStoreInterface + LocalArtifactStore |
| `src/Domain/Artifact/` (entire dir if only ArtifactMetadata) | Empty after removal |

---

## Phase 6: Delete Dead Application Infrastructure

| Remove | Reason |
|--------|--------|
| `src/Application/Handler/RunEventDispatcher.php` | Never called — events flow through OutboxProjector, not this |
| `src/Application/Handler/EventSubscriberRegistry.php` | Only used by RunEventDispatcher |
| `src/Application/Handler/MercureOutboxProjectorWorker.php` | Dispatches to removed Mercure infrastructure |
| `src/Utils/StringUtils.php` | `normalizeNullable()` — zero callers |

---

## Phase 7: Delete Dead Domain Messages

| Remove | Reason |
|--------|--------|
| `src/Domain/Message/CollectToolBatch.php` | No `AsMessageHandler` consumer (confirmed in AGENTS.md) |
| `src/Domain/Message/ProjectMercureOutbox.php` | Mercure is removed |

**Keep** `src/Domain/Message/ProjectJsonlOutbox.php` (consumed by JsonlOutboxProjectorWorker).

---

## Phase 8: Remove Dead Code Baseline & Verify

1. Delete `phpstan-baseline.neon` (all 122 suppressions — many are classes being removed)
2. Run `LLM_MODE=true castor dev:check` — regenerate phpstan baseline for remaining legit issues only
3. Delete tests that reference removed classes:
   - `tests/Command/*` — all console command tests
   - `tests/Infrastructure/Mercure/*` — Mercure tests
   - `tests/Schema/` — Schema tests (or trim to EventPayloadNormalizer only)
   - Any test using removed interfaces

---

## Phase 9: Rename for Clarity

| From | To | Reason |
|------|----|--------|
| `src/Application/Orchestrator/` | `src/Application/Pipeline/` | "Pipeline" describes what it does |
| `src/Application/Handler/` | keep as-is | These are infrastructure services, not message handlers |

---

## Phase 10: Split RunMessageStateTools

`src/Application/Pipeline/RunMessageStateTools.php` (297 lines, 8 responsibilities) → extract:

| New class | Responsibilities |
|-----------|-----------------|
| `src/Domain/Event/EventFactory.php` | `event()`, `eventsFromSpecs()`, `incrementStateVersion()` |
| `src/Domain/Message/AgentMessageNormalizer.php` | `assistantMessage()`, `assistantMessagePayload()`, `humanResponseMessage()`, `toolMessage()` |
| `src/Application/Pipeline/ToolCallExtractor.php` | `extractToolCalls()`, `normalizeToolCalls()`, `interruptPayloadFromToolResult()` |
| Keep `RunMessageStateTools` as facade delegating to above, or delete it and inject the split classes |

---

## What Remains (the keep list)

```
src/
├── Contract/
│   ├── AgentRunnerInterface.php
│   ├── CommandStoreInterface.php
│   ├── EventStoreInterface.php
│   ├── OutboxProjectorInterface.php
│   ├── OutboxStoreInterface.php
│   ├── PromptStateStoreInterface.php
│   ├── RunStoreInterface.php
│   ├── Extension/
│   │   ├── CommandHandlerInterface.php
│   │   ├── EventSubscriberInterface.php
│   │   └── HookSubscriberInterface.php
│   ├── Hook/
│   │   ├── BeforeProviderRequestHookInterface.php
│   │   ├── CancellationTokenInterface.php
│   │   ├── ConvertToLlmHookInterface.php
│   │   ├── NullCancellationToken.php
│   │   └── TransformContextHookInterface.php
│   └── Tool/
│       ├── ModelResolverInterface.php
│       ├── PlatformInterface.php
│       ├── ToolExecutorInterface.php
│       └── ToolIdempotencyKeyResolverInterface.php
├── Domain/
│   ├── Command/CoreCommandKind.php
│   ├── Event/
│   │   ├── BoundaryHookEvent.php
│   │   ├── BoundaryHookName.php
│   │   ├── CoreLifecycleEventType.php
│   │   └── RunEvent.php
│   ├── Extension/AfterTurnCommitHookContext.php
│   ├── Message/
│   │   ├── AbstractAgentBusMessage.php
│   │   ├── AdvanceRun.php
│   │   ├── AgentBusMessageInterface.php
│   │   ├── AgentMessage.php
│   │   ├── ApplyCommand.php
│   │   ├── ExecuteLlmStep.php
│   │   ├── ExecuteToolCall.php
│   │   ├── LlmStepResult.php
│   │   ├── ProjectJsonlOutbox.php
│   │   ├── StartRun.php
│   │   ├── StartRunPayload.php
│   │   └── ToolCallResult.php
│   ├── Run/
│   │   ├── PromptState.php
│   │   ├── RunAccessScope.php
│   │   ├── RunMetadata.php
│   │   ├── RunState.php
│   │   ├── RunStatus.php
│   │   └── StartRunInput.php
│   └── Tool/ (12 value objects — keep all)
├── Application/
│   ├── Pipeline/ (renamed from Orchestrator/)
│   │   ├── AdvanceRunHandler.php
│   │   ├── AgentRunner.php
│   │   ├── ApplyCommandHandler.php
│   │   ├── CommandMailboxPolicy.php
│   │   ├── HandlerResult.php
│   │   ├── LlmStepResultHandler.php
│   │   ├── RunCommit.php
│   │   ├── RunMessageHandler.php
│   │   ├── RunMessageProcessor.php
│   │   ├── RunMessageStateTools.php (or split — see Phase 10)
│   │   ├── RunOrchestrator.php
│   │   ├── StartRunHandler.php
│   │   └── ToolCallResultHandler.php
│   ├── Handler/
│   │   ├── CommandHandlerRegistry.php
│   │   ├── CommandRouter.php
│   │   ├── ExecuteLlmStepWorker.php
│   │   ├── ExecuteToolCallWorker.php
│   │   ├── HookDispatcher.php
│   │   ├── HookSubscriberRegistry.php
│   │   ├── JsonlOutboxProjectorWorker.php
│   │   ├── LatencyHistogram.php
│   │   ├── MessageIdempotencyService.php
│   │   ├── OutboxProjector.php
│   │   ├── ReplayService.php
│   │   ├── RunDebugService.php
│   │   ├── RunLockManager.php
│   │   ├── RunMetrics.php
│   │   ├── RunTracer.php
│   │   ├── StepDispatcher.php
│   │   ├── ToolBatchCollector.php
│   │   ├── ToolBatchCollectOutcome.php
│   │   ├── ToolExecutionPolicyResolver.php
│   │   ├── ToolExecutionResultStore.php
│   │   └── ToolExecutor.php
│   ├── RunReadService.php
│   └── Dto/ (6 snapshot DTOs — keep all)
├── Infrastructure/
│   ├── Doctrine/ (empty dir, kept for future)
│   ├── Storage/
│   │   ├── HotPromptStateStore.php
│   │   ├── InMemoryCommandStore.php
│   │   ├── InMemoryOutboxStore.php
│   │   ├── InMemoryPromptStateStore.php
│   │   ├── InMemoryRunStore.php
│   │   ├── RunEventStore.php
│   │   ├── RunLogReader.php
│   │   └── RunLogWriter.php
│   └── SymfonyAi/ (7 files — keep all)
└── Schema/
    ├── EventPayloadNormalizer.php
    └── SchemaVersion.php
```

---

## Verification Gates

After each phase, run:

```bash
LLM_MODE=true castor dev:check
```

Fix any breakage before moving to next phase. The CS-fixer, PHPStan, and test suite must stay green throughout.

After Phase 8 (baseline regeneration), the phpstan-baseline.neon should shrink dramatically — from 122 suppressions to only legit issues (constructor injection in DI-discovered classes flagged as dead by ShipMonk, etc.).

---

## Risks

- **Tests may break hard on Phase 1.** The DI extension removal will break any test that uses `TestKernel` or loads the bundle. Delete those tests, don't fix them.
- **Ai-index files go stale.** PHPStan regeneration will update them. Don't manually edit `.toon` files — let `castor dev:check` regenerate.
- **`RunOrchestrator` uses `#[AsMessageHandler]` attributes.** These are Symfony attributes. They'll stay — the library still depends on Symfony Messenger for bus routing. The consumer provides the bus transport, the library provides the handlers.
