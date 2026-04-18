# Stage 02 - Runtime Domain and Reducer

## Goal
Implement a reducer-driven orchestration core to keep loop logic deterministic, testable, and small enough to maintain.

## Why Reducer Here
- The orchestrator will grow quickly with retries, steering, cancel, HITL, and parallel tools.
- Reducer separates transition logic from side effects.
- Enables replay from JSONL events and idempotent command handling.

## Domain Objects
- `RunState`
- `TurnState`
- `ToolBatchState`
- `PendingCommandQueue`
- `PromptWindowState`

## Commands (Inputs)
- `StartRun`
- `AdvanceRun`
- `ApplySteerCommand`
- `ApplyFollowUpCommand`
- `ApplyCancelCommand`
- `ApplyHumanResponseCommand`
- `ApplyExtensionCommand`
- `LlmStepCompleted`
- `LlmStepFailed`
- `ToolCallCompleted`
- `ToolCallFailed`
- `ToolBatchCompleted`

## Effects (Outputs)
- `EmitEventEffect`
- `EmitExtensionEventEffect`
- `ExecuteLlmEffect`
- `ExecuteToolBatchEffect`
- `RequestHumanInputEffect`
- `FinalizeRunEffect`
- `ScheduleNextAdvanceEffect`

## Run Status Model
- `queued`
- `running`
- `waiting_human`
- `cancelling`
- `completed`
- `failed`
- `cancelled`

## Transition Rules
1. `queued -> running` on `StartRun`.
2. `running -> waiting_human` on interrupt tool outcome.
3. `running -> cancelling` on `ApplyCancelCommand`.
4. `cancelling -> cancelled` on next boundary commit.
5. `running -> completed` on loop exit with no follow-up.
6. `running -> failed` on unrecoverable orchestrator error.
7. `waiting_human -> running` on `ApplyHumanResponseCommand`.

## Reducer Contract

```php
final class ReduceResult
{
    public function __construct(
        public RunState $state,
        /** @var list<Effect> */
        public array $effects,
    ) {}
}

interface RunReducerInterface
{
    public function reduce(RunState $state, Command $command): ReduceResult;
}
```

## Orchestrator Responsibilities
- Acquire lock and version-checked state.
- Route mailbox command envelopes through built-in handlers and extension command handlers.
- Invoke reducer.
- Persist state transition and event append atomically.
- Dispatch effects.
- Never run heavy I/O inline except minimal commit path.

## Command Routing Model
- `ApplyCommand` loads pending mailbox item (`kind`, `payload`, `options`, `idempotency_key`).
- If kind is core, map to built-in reducer command.
- If kind matches `ext:` prefix, route through registered extension command handlers.
- For extension commands, `options.cancel_safe` defaults to `false` and is only honored when handler capability declares cancel-safe support.
- Extension command handlers must return deterministic reducer input(s) or explicit rejection; they cannot commit state directly.
- Missing handler for `ext:` kind becomes deterministic rejection event and `agent_commands.status=rejected`.

## Idempotency Rules
- Every command has `idempotency_key`.
- If key already applied for `(run_id, kind, step_id)`, no-op and ack.
- Late executor responses are ignored when `step_id` is stale.

## Concurrency Rules
- Single writer per run.
- Any worker may process different runs concurrently.
- Tool workers execute in parallel, but cannot mutate run state.

## Deliverables
- Command and effect class hierarchy.
- Reducer implementation for the base happy path.
- Orchestrator handler that applies reducer output.
- Command router with extension handler registry.
- Idempotency store and stale-response guards.

## Acceptance Criteria
- Reducer unit tests cover all status transitions.
- Replay tests produce same final state from same command sequence.
- Duplicate command delivery does not duplicate effects.
- Extension command with `ext:` kind can be routed, reduced, and persisted without changing reducer core transitions.
