---
builtin: true
description: Background process tracking, logs, stop behavior, and related settings.
---

# Background Processes

Long-running shell work can detach into tracked background processes so the conversation loop stays responsive.

Press **Ctrl+B** while a Bash, subagent or fork tool runs to move it to background.
If several eligible invocations are running in the visible session, choose one in the
picker. Enter or Ctrl+B confirms; Escape cancels. In child live view, the shortcut
targets that child's Bash tool rather than its parent's invocation.
Questions, modal dialogs and completion overlays retain their keyboard handling.
Without an eligible invocation, Ctrl+B keeps its editor cursor-left behavior.
There is no model-facing background parameter or `bg_start` tool.
After backgrounding, open `/bg` to inspect or stop jobs yourself, or ask the agent to use `bg_status`.

## Child invocations

Backgrounding a subagent or fork releases the parent's wait with one acknowledgment.
The same children continue under their original deadlines and cancellation supervision.
A parallel subagent call remains one invocation: its aggregate handoff arrives as a
follow-up after all children settle, not as a second result for the original tool call.
`agent_resume` invocations do not support detachment. Use `agent_retrieve` for existing
artifact details and handoff history. `/bg` and `bg_status` continue to list Bash processes,
not child invocations.

A child can also background its own Bash tool. If a completion reaches an active owner,
it uses that owner's existing follow-up mailbox. If the owner has finished or is cancelling,
the completion goes to the root parent instead. The forwarded note identifies the original
child's name, agent/artifact ID and run ID. It never resumes the child or rewrites a completed
handoff. The parent can explicitly use `agent_resume` if more child work is needed.

## Background process picker: `/bg`

The picker lists accepted unfinished jobs for the visible session, with command, PID,
elapsed seconds and status. Jobs in termination grace appear as `stopping`.
Select a job and press Enter to open a Yes/No stop confirmation, defaulting to No.
Escape returns to the original list; Escape again closes it. Confirming Yes closes
the picker and requests a stop of the exact selected record without waiting for exit.
The terminal remains responsive during grace. The controller observes exit or escalates
to KILL, even if the caller dies. Reopen `/bg` to inspect the current status.
Listing and stopping do not call the model.

The list is a snapshot fetched once on opening. It never refreshes while open, even
after confirmation cancellation or process events. Reopen `/bg` for a fresh snapshot.
An already-finished or removed selection never targets another job with a reused PID.

## Model / user tool: `bg_status`

| Action | Meaning |
|---|---|
| `list` | Show accepted background processes for the current session |
| `log` | Read recent log output for an accepted process (`pid` required) |
| `stop` | TERM the accepted process group, wait grace, then KILL if needed (`pid` required) |

## Lifecycle

1. `bash` starts each command with a private `background_process` supervision row and `.pid` / `.status` / `.log` sidecars so it can poll, stop, and read the exact foreground result.
2. Ctrl+B requests backgrounding for one exact running invocation. Its foreground worker checks completion before accepting the request. Acceptance resolves the original tool call with an acknowledgment and marks the existing row `backgrounded_at`; only then is it visible to `bg_status` and eligible for completion notification. The command is not launched again.
3. Finished private foreground rows remain available for one five-minute scheduler interval, then recurring maintenance removes their exact row-owned sidecars and row.
4. Accepted background rows and their exact sidecars live only while the controller owns the session. Controller **startup** and **shutdown** both stop unfinished accepted process groups and remove their rows/sidecars (startup repairs crash leftovers; shutdown cleans the live session). The provisional 300s sweep does **not** handle accepted rows.
5. `bg_status stop` is the explicit operator/model path for an accepted job in the current session: `SIGTERM` to the process group, wait `tools.background_process.stop_grace_seconds`, then `SIGKILL` if still alive. Controller lifecycle cleanup can stop the same accepted jobs without a `bg_status` call.

Backgrounding preserves the original monotonic timeout deadline. The existing controller
completion poller enforces it after detachment, with its two-second polling granularity
and the configured termination grace period. Timeout handling sends TERM without blocking
the controller; subsequent polls observe exit or send KILL after grace. Explicit stop and
shutdown still wait for termination. Completion uses the existing follow-up queue.

Successful explicit stops do not send another completion follow-up: the stop result
already acknowledges the outcome. Natural completion, failure and timeout still
notify. Stopping an already-finished job preserves its ordinary completion notification.

Tracking is **session-scoped**. `bg_status` exposes only rows explicitly accepted as background work; private foreground supervision is never listed, tailed, or stopped through that tool. Durable records live in `.hatfield/state.sqlite`; filesystem sidecars (PID/status/log) live under the configured tool path.

### PID identity

Launch uses `setsid -f`. The wrapper publishes its PID on the launch pipe and in the `.pid` sidecar before starting the command. Status and stop use that PID, not the transient launcher PID. The command runs in a separate `bash -c`, so command syntax cannot bypass the wrapper's status recording. An unclean exit means the tracked wrapper exited without recording a status. Its result includes log and status paths for inspection, not a claim that the workload succeeded. Do not rerun a side-effecting command merely because its exit status is unavailable.

## Settings

| Key | Role | Default |
|---|---|---|
| `tools.background_process.path` | Storage directory | `.hatfield/tmp/bg` |
| `tools.background_process.stop_grace_seconds` | TERM grace period | `5` |
| `tools.background_process.log_tail_chars` | Default log tail size | `5000` |

See [settings.md](settings.md).

## Safety

- Prefer stop via `bg_status` rather than guessing PIDs.
- Background workloads may outlive a single LLM turn; still stop them when finished.
- Never signal unrelated host processes; tracking is limited to Hatfield-managed records for the session.
