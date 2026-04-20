# Stage 12 — RunOrchestrator Rewrite (Breaking)

## Goal

Replace `RunOrchestrator` with a clean, modular architecture.

This is a **rewrite**, not a compatibility refactor.

---

## Compatibility Policy

We are **not** preserving compatibility with the old implementation details.

Allowed in this stage:

- breaking internal APIs
- deleting transitional abstractions
- replacing old tests with new behavior-focused tests
- changing tracing/log structure if needed
- removing legacy helper methods and dead paths

Only hard requirement: the system remains functionally correct for current product use-cases after rewrite.

---

## Current Baseline (for scope sizing)

`src/Application/Orchestrator/RunOrchestrator.php` currently:

- ~2031 LOC
- 20 constructor dependencies
- 5 message entry points
- large branching handlers (`onLlmStepResult`, `onToolCallResult`)
- mixed concerns: lock/idempotency, command policy, state mutation, persistence, projection, replay, effects, observability

This stage replaces that design.

---

## Target Architecture

## 1) Thin bus entrypoint

Keep `RunOrchestrator` only as message-bus entrypoint with `#[AsMessageHandler]` methods and tracing root spans.

All business logic moves out.

## 2) Message processor core

Add `RunMessageProcessor` to own common flow:

1. lock by `runId`
2. idempotency check
3. load state
4. route to dedicated handler
5. persist via `RunCommit`
6. run post-commit actions
7. mark handled

## 3) Dedicated handlers per message type

- `StartRunHandler`
- `ApplyCommandHandler`
- `AdvanceRunHandler`
- `LlmStepResultHandler`
- `ToolCallResultHandler`

## 4) Unified commit unit

Add `RunCommit` as the single commit lifecycle owner.

```php
readonly final class RunCommit
{
    public function __construct(
        private RunStoreInterface $runStore,
        private EventStoreInterface $eventStore,
        private EventPayloadNormalizer $eventPayloadNormalizer,
        private CommandStoreInterface $commandStore,
        private OutboxProjector $outboxProjector,
        private ReplayService $replayService,
        private StepDispatcher $stepDispatcher,
        private ?HookDispatcher $hookDispatcher = null,
        private ?LoggerInterface $logger = null,
        private ?RunMetrics $metrics = null,
        private ?RunTracer $tracer = null,
    ) {}

    /**
     * @param list<RunEvent> $events
     * @param list<object> $effects
     */
    public function commit(RunState $previous, RunState $next, array $events, array $effects = []): bool;
}
```

## 5) Command mailbox extracted now (not later)

Extract immediately into `CommandMailboxPolicy`:

- turn-start boundary application
- stop-boundary application
- steer superseding logic
- extension command application at boundary

No staged postponement for this rewrite.

## 6) Remove reducer indirection

Delete `RunReducer` usage from runtime path.

`StartRun` state transition logic moves into `StartRunHandler`.

---

## Core Contracts

## Handler contract

```php
interface RunMessageHandler
{
    public function supports(object $message): bool;

    public function handle(object $message, RunState $state): HandlerResult;
}
```

## Handler result

```php
readonly final class HandlerResult
{
    public function __construct(
        public ?RunState $nextState = null,
        public array $events = [],              // list<RunEvent>
        public array $effects = [],             // list<object>: durable-transition effects, dispatched by RunCommit
        public array $postCommitEffects = [],   // list<object>: fire-and-forget effects dispatched by processor only when commit succeeds
        public array $postCommit = [],          // list<callable(): void>: callbacks executed only when commit succeeds
        public bool $markHandled = true,
    ) {}
}
```

This explicitly models two post-persistence layers:
- `effects`: commit-owned dispatch as part of commit lifecycle
- `postCommitEffects`/`postCommit`: processor-owned actions that run only after `RunCommit::commit(...)` returns `true`

## Processor flow (explicit)

```php
if (null !== $result->nextState) {
    $committed = $runCommit->commit($state, $result->nextState, $result->events, $result->effects);
    if (!$committed) {
        return;
    }

    if ([] !== $result->postCommitEffects) {
        $stepDispatcher->dispatchEffects($result->postCommitEffects);
    }

    foreach ($result->postCommit as $callback) {
        $callback();
    }

    if ($result->markHandled) {
        $idempotency->markHandled($scope, $runId, $idempotencyKey);
    }

    return;
}

// no-commit path (duplicates/no-ops): post-commit actions are intentionally skipped
if ($result->markHandled) {
    $idempotency->markHandled($scope, $runId, $idempotencyKey);
}
```

---

## Dependency Ownership (rewrite decision)

`StepDispatcher` remains infrastructure-level and is owned by processor/commit flow.

Handlers do **not** depend on `StepDispatcher` directly.

| Handler | Dependencies |
|---|---|
| `StartRunHandler` | start-run policy + message hydration helper |
| `ApplyCommandHandler` | `CommandStoreInterface`, `CommandRouter`, command limits/policy, optional `MessageBusInterface` for follow-up advance scheduling |
| `AdvanceRunHandler` | `CommandMailboxPolicy`, optional metrics/tracer helpers |
| `LlmStepResultHandler` | `ToolBatchCollector`, `ToolExecutionPolicyResolver`, `ToolCatalogResolver`, `CommandMailboxPolicy`, optional `MessageBusInterface` |
| `ToolCallResultHandler` | `ToolBatchCollector`, optional metrics |

---

## Rewrite Plan (Big-Bang)

## Phase A — Build new architecture alongside old

1. Add new contracts/classes:
   - `RunMessageProcessor`
   - `RunCommit`
   - `HandlerResult`
   - handler classes
   - `CommandMailboxPolicy`
2. Add scenario tests for new flow (see Test Strategy).

## Phase B — Switch orchestration entrypoint

3. Rewire `RunOrchestrator` to delegate all runtime logic to `RunMessageProcessor`.
4. Remove direct state-mutation logic from `RunOrchestrator`.

## Phase C — Delete legacy paths

5. Remove old private orchestration helpers from `RunOrchestrator`.
6. Remove `RunReducer` from runtime wiring.
7. Remove obsolete tests that lock old structure.

## Phase D — Cleanup and docs

8. Update service wiring in `config/services.php`.
9. Update architecture docs (`src/Application/AGENTS.md`, `docs/request-flow.md`).
10. Run full quality gates.

---

## Deletions Expected in this Stage

- `RunOrchestrator` private helpers that are absorbed by handlers/policies/commit unit
- reducer-based runtime usage (`RunReducer` no longer required by orchestrator)
- legacy mailbox methods in orchestrator
- duplicated effect-dispatch branching in handlers

---

## Test Strategy (rebaseline)

We do not preserve old test structure for compatibility reasons.

We keep/introduce high-value scenario tests:

1. start → advance → llm complete (no tools) → completed
2. llm tool-call fanout → ordered tool commit
3. stale llm/tool result handling
4. cancel during llm/tool execution
5. continue/human response flow
6. commit failure rollback + retry
7. duplicate delivery storms do not duplicate durable events

Per-handler unit tests should verify:

- state transition
- event list
- effect list
- post-commit effects/callbacks
- markHandled flag

Run gates:

```bash
LLM_MODE=true castor dev:check
```

---

## Documentation Updates Required

- `src/Application/AGENTS.md`
  - new command/message handler mapping
  - commit ownership and flow
- `docs/request-flow.md`
  - remove reducer-centric narrative
- any docs mentioning monolithic orchestrator internals

---

## Completion Criteria

Stage is complete when:

1. Orchestrator logic is modularized into processor + handlers + commit unit
2. Mailbox logic is extracted and reused
3. Reducer runtime dependency is removed from orchestrator path
4. New scenario tests pass
5. `LLM_MODE=true castor dev:check` passes
