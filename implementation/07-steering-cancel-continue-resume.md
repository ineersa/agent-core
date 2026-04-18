# Stage 07 - Steering, Cancel, Continue, Resume

## Goal
Implement control-plane commands with JS-compatible semantics and production-safe recovery behavior.

## Command Mailbox
Use `agent_commands` as the only input channel for live run controls.

Command kinds:
- `steer`
- `follow_up`
- `cancel`
- `human_response`
- `continue` (explicit resume after failures)
- `ext:*` (extension-defined commands)

Command envelope (all kinds):
- `kind`
- `payload`
- `idempotency_key`
- optional `options` (reserved metadata owned by core runtime)

Reserved option for extension commands:
- `options.cancel_safe: boolean` (default `false`)
- only valid for `ext:*` commands
- for core command kinds, providing `options.cancel_safe` is rejected as invalid input
- unknown `options` keys are rejected (strict schema)

## Steering Semantics
- Applied only at boundaries:
  - before LLM call at turn start,
  - after current turn completion.
- Never interrupts an in-flight tool call in the same turn.
- Drain mode configurable:
  - `one_at_a_time`
  - `all`

## Follow-up Semantics
- Checked only when run would otherwise stop.
- If present, injected as pending user messages and run continues.

## Cancel Semantics
1. API sets `cancel_requested=true` via `cancel` command.
2. Orchestrator transitions to `cancelling`.
3. Boundary checks enforce stop.
4. Emit `agent_end` with aborted/cancelled reason.

## Continue Semantics
- Continue allowed only from valid last message role (`user` or `tool`/`ToolCallMessage`).
- If last assistant failed with retryable condition, `continue` schedules next `AdvanceRun`.
- If invalid role, command is rejected with explicit error event.

## Command Conflict and Queue Policy
- Deduplicate by `idempotency_key`; duplicates are no-op acknowledgements.
- `cancel` has highest priority:
  - once accepted, new `steer`, `follow_up`, and `continue` commands are rejected,
  - once accepted, new `ext:*` commands are rejected unless both:
    - command has `options.cancel_safe=true`, and
    - matched extension handler is registered as cancel-safe for that kind,
  - pending `continue` commands are marked `rejected`.
- `continue` is allowed only for retryable failure states; reject in `running`, `completed`, and `cancelled` states.
- Steering supersede behavior before boundary apply:
  - with drain mode `one_at_a_time`, keep the latest pending `steer` and mark older pending `steer` commands as `superseded`,
  - with drain mode `all`, apply in FIFO order.
- Enforce per-run pending mailbox cap (configurable); reject non-cancel commands when cap is exceeded.

## Extension Command Policy
- Extension commands must use kind prefix `ext:`.
- Processing point is the same boundary mailbox apply phase as core commands.
- If no handler is registered for an `ext:` command kind, mark command `rejected` and emit deterministic rejection event.
- Missing/invalid `options.cancel_safe` is treated as `false`.
- Cancel-safe extension commands are for cleanup/finalization tasks only; they must not schedule new LLM or tool execution steps.
- Extension command handlers cannot bypass reducer/orchestrator commit path.

## Resume on Restart
- On worker restart:
  - find stale runs in `running` (for example `updated_at` older than threshold),
  - acquire lock,
  - rebuild hot prompt from canonical events (fallback JSONL) if missing,
  - dispatch `AdvanceRun`.

## Stale Response Handling
- Any `LlmStepResult` or `ToolCallResult` with stale `step_id` is ignored.
- Persist a `stale_result_ignored` trace event for auditability.

## Deliverables
- Command handlers for all control commands.
- Command application policy and conflict rules.
- Resume scanner command (`agent-loop:resume-stale-runs`).

## Acceptance Criteria
- Steering/follow-up parity tests match JS behavior.
- Cancel requests are visible quickly and terminate at next safe boundary.
- Crash/restart resumes active runs without duplicate turn commits.
- Conflict-policy tests cover cancel-vs-continue, duplicate steer, supersede behavior, and queue-cap rejection.
