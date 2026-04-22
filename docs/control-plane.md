# Control Plane

The control plane is implemented through `ApplyCommand` messages on `agent.command.bus`. It allows mid-run steering, cancellation, follow-ups, and HITL continuation without breaking run consistency.

## Command mailbox and boundaries

Commands are queued in `CommandStoreInterface` (default: `InMemoryCommandStore`) and applied at safe orchestration boundaries:

- **turn-start boundary** (`applyPendingTurnStartCommands`)
- **stop boundary** after model output/tool completion (`applyPendingStopBoundaryCommands`)

This keeps state transitions deterministic and compatible with retries/idempotency.

## Supported command kinds

Core command kinds (`CoreCommandKind`) are:

- `steer`
- `follow_up`
- `cancel`
- `human_response`
- `continue`

Extension commands must start with `ext:`.

## API command validation rules

`POST /agent/runs/{runId}/commands` enforces:

- `kind`: non-empty string, core kind or `ext:*`
- `idempotency_key`: required non-empty string
- `payload`: JSON object
- `options`: JSON object; currently only `cancel_safe` is accepted
- `cancel_safe` is **reserved for extension commands** (core commands cannot set it)

## Conflict and priority policy

- **Idempotency**: duplicates are safely ignored/acknowledged by idempotency handling.
- **Cancellation precedence**: once cancel is in play, conflicting commands can be rejected.
- **Extension safety during cancellation**: only extension commands with `options.cancel_safe=true` are eligible when cancellation constraints apply.
- **Steer drain mode**: configurable via `agent_loop.commands.steer_drain_mode` (`one_at_a_time` or `all`).

## Recovery and stale-run resume

`agent-loop:resume-stale-runs` scans stale `running` runs (`resume_stale_after_seconds`), locks each run, rebuilds hot prompt state when needed, and dispatches `AdvanceRun` so processing resumes at a safe boundary.
