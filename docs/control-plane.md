# Control Plane

The agent loop allows dynamic run management via the Command Bus. These control-plane commands (sent via `ApplyCommand`) manage steering, follow-ups, cancellation, and HITL (Human-in-the-Loop) resolution.

## Command Mailbox & Lifecycle

Commands are pushed into a per-run mailbox (persisted in `agent_commands`) and evaluated by the Orchestrator at specific boundaries (e.g., before an LLM call or after a turn completes) to ensure state integrity.

### Core Command Kinds
- `steer`: Injects instructions into an active run without interrupting an in-flight tool call.
- `follow_up`: Appends a user message if the run would otherwise stop, seamlessly continuing execution.
- `cancel`: Aborts the run. Transitions to `cancelling`, stops at the next safe boundary, and emits `agent_end`.
- `human_response`: Resolves a HITL `interrupt` tool by providing the user's answer.
- `continue`: Explicitly resumes a run after a failure (e.g., if the LLM threw a retryable exception).
- `ext:*`: Custom extension-defined commands.

### Conflict & Priority Policy
- **Deduplication**: Handled via `idempotency_key`. Duplicates act as no-op acknowledgments.
- **Cancellation Priority**: Once `cancel` is requested, new `steer`, `follow_up`, or `continue` commands are rejected. 
- **Extension Safety**: Only extension commands (`ext:*`) with the `options.cancel_safe=true` flag are allowed to process during a cancellation (used for cleanup).
- **Steering Drain Mode**: If multiple steering commands queue up, they are processed based on the drain mode (either applying all FIFO, or superseding older ones with the latest).

## Resiliency & Crash Recovery

If the worker crashes, the system can self-heal:
1. A scanner command (`agent-loop:resume-stale-runs`) finds runs stuck in `running` status past a threshold.
2. It acquires a lock and rebuilds the "hot prompt" state from the immutable event log (falling back to JSONL if needed).
3. It dispatches an `AdvanceRun` message to seamlessly continue exactly where it left off, avoiding duplicate commits.
