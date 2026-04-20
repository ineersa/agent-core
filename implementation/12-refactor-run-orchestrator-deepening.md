# Stage 12 — Refactor: RunOrchestrator Deepening

## Goal

Break apart the 2031-line `RunOrchestrator` (21-constructor-parameter god class) into focused, deep modules with clear ownership boundaries while preserving the existing message bus topology and CQRS commit semantics.

## Problem Statement

`RunOrchestrator` handles 5 distinct message types (`StartRun`, `ApplyCommand`, `AdvanceRun`, `LlmStepResult`, `ToolCallResult`) with deeply branching logic per type. Key symptoms:

- **21 constructor parameters** — expensive test fixture setup, high coupling
- **`commit()` is 134 lines** — touches event stores, outbox, replay, hooks, step dispatch, metrics, logging
- **`onLlmStepResult` is 282 lines** with 25 callees — tool extraction, schema resolution, policy resolution, batch dispatch
- **`onToolCallResult` is 226 lines** — batch collection, interrupt handling, state mutation
- **Reducer bypass**: `RunReducer::reduce()` is only called from `onStartRun`; `onAdvanceRun` and `onApplyCommand` mutate state directly
- **`hydrateMessage()` duplicated** in 3 places (see stage 14)

## Current Dependency Surface

```
RunOrchestrator
├── RunStoreInterface          (read/write run state)
├── EventStoreInterface        (append events)
├── CommandStoreInterface      (enqueue/mark pending/applied/rejected/superseded)
├── RunReducer                 (only used by onStartRun!)
├── StepDispatcher             (dispatch effects + publish)
├── CommandRouter              (route extension commands)
├── OutboxProjector            (project events in commit)
├── ReplayService              (rebuild hot prompt in commit)
├── MessageIdempotencyService  (dedup all handlers)
├── RunLockManager             (lock per runId)
├── ToolBatchCollector         (register + collect batches)
├── CommandBus                 (dispatch AdvanceRun)
├── HookDispatcher             (dispatch in commit)
├── ToolExecutionPolicyResolver (resolve per-tool in onLlmStepResult)
├── ToolCatalogResolver        (resolve schemas in onLlmStepResult)
├── LoggerInterface
├── RunMetrics
└── RunTracer
```

## Strategy: Handler Extraction + Commit Unit

Rather than a big-bang rewrite, extract one handler at a time with a shared commit protocol.

### Phase 1 — Extract `RunCommit` (the shared commit protocol)

The `commit()` method is the shared seam. Extract it into a dedicated unit that all handlers delegate to.

**New class**: `Application\Orchestrator\RunCommit`

```php
readonly final class RunCommit
{
    public function __construct(
        private RunStoreInterface $runStore,
        private EventStoreInterface $eventStore,
        private EventPayloadNormalizer $eventNormalizer,
        private OutboxProjector $outboxProjector,
        private ReplayService $replayService,
        private HookDispatcher $hookDispatcher,
        private StepDispatcher $stepDispatcher,
        private ?LoggerInterface $logger = null,
        private ?RunMetrics $metrics = null,
        private ?RunTracer $tracer = null,
    ) {}

    /**
     * Atomic commit: CAS write state, persist events, project outbox,
     * rebuild hot prompt, dispatch hooks/effects, record metrics.
     */
    public function commit(RunState $previous, RunState $next, array $events, array $effects = []): bool;
}
```

**Impact**: Removes 10 parameters from `RunOrchestrator` (9 original + `EventPayloadNormalizer` added in stage 11). Each extracted handler only needs `RunCommit` + its specific dependencies.

### Phase 2 — Extract per-message handlers

Extract each `onXxx` method into its own handler class, each depending on `RunCommit` plus its narrow dependencies.

**Extracted handlers**:

| Handler | Dependencies (beyond RunCommit) | Lines extracted |
|---------|--------------------------------|-----------------|
| `StartRunHandler` | RunReducer, CommandStore (enqueue) | ~54 |
| `ApplyCommandHandler` | CommandStore, CommandRouter, maxPendingCommands config | ~119 |
| `AdvanceRunHandler` | CommandStore, CommandBus, steerDrainMode config | ~130 |
| `LlmStepResultHandler` | ToolBatchCollector, ToolExecutionPolicyResolver, ToolCatalogResolver, CommandBus | ~282 |
| `ToolCallResultHandler` | ToolBatchCollector, CommandBus | ~226 |

Each handler implements:
```php
interface RunMessageHandler
{
    public function handle(object $message, RunState $state): HandlerResult;
}
```

Where `HandlerResult` bundles the new `RunState`, events, and effects:
```php
readonly final class HandlerResult
{
    public function __construct(
        public RunState $state,
        public array $events = [],
        public array $effects = [],
        public bool $skipCommit = false,
    ) {}
}
```

**Dispatcher**: `RunOrchestrator` becomes a thin dispatcher:
```php
final readonly class RunOrchestrator
{
    public function __construct(
        private RunStoreInterface $runStore,
        private RunLockManager $runLockManager,
        private MessageIdempotencyService $idempotency,
        private RunCommit $commit,
        /** @var array<string, RunMessageHandler> */
        private array $handlers,
        private ?RunTracer $tracer = null,
    ) {}

    public function onStartRun(StartRun $message): void    { $this->process($message, 'start'); }
    public function onApplyCommand(ApplyCommand $message): void { $this->process($message, 'apply_command'); }
    public function onAdvanceRun(AdvanceRun $message): void { $this->process($message, 'advance'); }
    public function onLlmStepResult(LlmStepResult $message): void { $this->process($message, 'llm_result'); }
    public function onToolCallResult(ToolCallResult $message): void { $this->process($message, 'tool_result'); }

    private function process(object $message, string $type): void
    {
        $runId = $message->runId();
        $this->runLockManager->synchronized($runId, function () use ($message, $type) {
            if ($this->idempotency->wasHandled($message->idempotencyKey())) {
                return;
            }
            $state = $this->runStore->get($message->runId());
            $result = $this->handlers[$type]->handle($message, $state);
            if ($result->skipCommit) return;
            $committed = $this->commit->commit($state, $result->state, $result->events, $result->effects);
            if ($committed) {
                $this->idempotency->markHandled($message->idempotencyKey());
            }
        });
    }
}
```

### Phase 3 — Restore reducer consistency

Currently `RunReducer` is only used by `onStartRun`. After extraction:
- Each handler produces `HandlerResult` (state + events + effects) — this IS the reducer pattern
- `RunReducer::reduce()` can be removed or inlined into `StartRunHandler`
- The state transition logic stays co-located with the handler that owns it

### Phase 4 — Extract command mailbox policy (see stage 13)

The `applyPendingTurnStartCommands` / `applyPendingStopBoundaryCommands` / `supersededSteerKeys` methods (~224 lines) form a coherent "command mailbox" policy. These can be extracted into a `CommandMailboxPolicy` service used by `AdvanceRunHandler` and `LlmStepResultHandler`.

## Migration Order

1. **Phase 1** — Extract `RunCommit` (non-breaking, `RunOrchestrator` delegates to it)
2. **Phase 1.5** — Extract `HandlerResult` value object
3. **Phase 2** — Extract one handler at a time, starting with the simplest (`StartRunHandler`)
4. **Phase 2.5** — After each extraction, verify existing tests still pass (they test through `RunOrchestrator`)
5. **Phase 3** — Remove `RunReducer` indirection once all handlers are extracted
6. **Phase 4** — Extract `CommandMailboxPolicy` (may happen in parallel with Phase 2)

## What Changes

- `RunOrchestrator` shrinks from ~2031 to ~80 lines (thin dispatcher)
- 5 new handler classes in `Application/Orchestrator/Handler/`
- 1 new `RunCommit` class in `Application/Orchestrator/`
- 1 new `HandlerResult` value object in `Application/Orchestrator/`
- Constructor parameters drop from 21 to ~7
- Each handler is independently testable with 3-5 dependencies

## What Does NOT Change

- Message bus topology (same messages, same routing)
- CQRS commit semantics (CAS write, event persistence, outbox projection)
- The `commit()` behavior — just relocated, not redesigned
- Public API surface (`AgentRunner` → message bus → `RunOrchestrator` handlers)
- Existing test suite continues to work (tests through `RunOrchestrator`)

## Test Strategy

- **Phase 1**: Existing orchestrator tests pass unchanged (delegation is transparent)
- **Phase 2**: Add per-handler unit tests (fewer mocks, focused assertions)
- **Phase 3**: Existing `RunReducerTransitionTest` inlined into `StartRunHandler` tests
- **Boundary test**: Each handler gets a boundary test that verifies state → events → effects without mocking internals

## Risks

- **Lock scope**: Currently the lock wraps the entire handler. After extraction, the lock still wraps `process()` in the thin dispatcher — same behavior.
- **Idempotency ordering**: `markHandled` must only be called after successful commit. The thin dispatcher enforces this.
- **State version increment**: Currently spread across `onLlmStepResult` and `onToolCallResult`. After extraction, each handler is responsible for calling `incrementStateVersion` — or `RunCommit` can do it based on event count.

## Open Questions

- Should `RunMessageHandler` be a new contract in `Contract/` or stay in `Application/Orchestrator/`? (Recommend: Application-internal for now, promote to Contract if extensions need custom handlers)
- Should `RunCommit` dispatch hooks/effects, or should handlers do it directly? (Recommend: RunCommit owns the full commit lifecycle for consistency)
- Should the command mailbox extraction (Phase 4) happen before or after all handlers are extracted? (Recommend: after, since it's used by two handlers)
