---
builtin: true
description: Background process tracking, logs, stop behavior, and related settings.
---

# Background Processes

Long-running shell work can detach into tracked background processes so the conversation loop stays responsive.

There is **no** model-facing `bg_start` tool. Backgrounding is offered from **`bash`** when a
command exceeds `tools.bash.background_prompt_threshold_seconds` (default `15`). The **user**
chooses whether to move the command to the background; the model does not control that choice.
After backgrounding, inspect or stop processes with `bg_status`.

## Model / user tool: `bg_status`

| Action | Meaning |
|---|---|
| `list` | Show accepted background processes for the current session |
| `log` | Read recent log output for an accepted process (`pid` required) |
| `stop` | TERM the accepted process group, wait grace, then KILL if needed (`pid` required) |

## Lifecycle

1. Before launching a command, `bash` records ownership in a random `managed-<nonce>/owner.json` scope under the background storage directory and acquires its writer lease. The shell wrapper and workload inherit that lease. After the wrapper publishes its PID, `bash` inserts the private `background_process` supervision row. No zero-PID launch row is exposed to status resolution.
2. After the threshold, the TUI may prompt the user to background it. On accept, Hatfield marks that existing row `backgrounded_at`; only then is it visible to `bg_status` and eligible for completion notification.
3. Finished private foreground rows remain available for one five-minute scheduler interval, then recurring maintenance removes their exact row-owned sidecars and row.
4. Controller **startup** and **shutdown** stop unfinished accepted process groups and request removal of their exact rows and scopes. Removal is deferred until the last writer lease closes. Recurring maintenance also removes completed accepted jobs after proving that their actual controller owner is absent, without requiring that session to resume. An active owner or missing ownership metadata protects the job.
5. `bg_status stop` is the explicit operator/model path for an accepted job in the current session: `SIGTERM` to the process group, wait `tools.background_process.stop_grace_seconds`, then `SIGKILL` if still alive. Controller lifecycle cleanup can stop the same accepted jobs without a `bg_status` call.
6. If inserting the durable row fails, `bash` verifies the wrapper's launch-time process identity, stops that owned process through the same termination lifecycle and propagates the insertion error. Missing identity or a cleanup failure is logged and retains the managed scope. Producer death before insertion cannot run that handler; the inherited writer lease protects the scope until its surviving writers exit.

Tracking is **session-scoped**. `bg_status` exposes only rows explicitly accepted as background work; private foreground supervision is never listed, tailed, or stopped through that tool. Durable records live in `.hatfield/state.sqlite`; filesystem sidecars (PID/status/log) live under the configured tool path.

### Abandoned artifact recovery

`BackgroundProcessCleanupTask` runs every 300 seconds on the existing default Scheduler transport. Any updated controller's scheduler can recover stopped managed scopes; recovery does not require the original session to restart.

One permanent Symfony flock coordinator serializes managed allocation, lease opening and directory removal. Do not unlink it. Ownership is published before any shell or sidecar exists. Closing the producer descriptor does not explicitly unlock the shell's inherited descriptor. Recovery acquires the scope's writer lease before consulting its row or removing its directory, so a PID file, status file or missing row alone cannot establish completion.

A stopped scope without a row is recoverable after failed insertion or producer death. Finished provisional rows retain the existing five-minute result-reading interval. Completed accepted rows require fresh `RunOperationalState.ownerSessionId` metadata and acquisition of that controller's non-expiring owner lock. Child jobs use their parent controller's identity, not a separate child lock. Recovery holds controller exclusion through row and directory removal.

Live writers remain protected, including writers whose producer died before inserting a row. Recovery does not guess their PID or signal them. Legacy flat sidecars, even those referenced by old rows, have no managed last-writer proof and remain untouched. Missing or invalid ownership records, symlinked scopes and unknown controller ownership also remain protected. `castor clean:cleanup` does not sweep runtime background files.

### PID identity

Launch uses `setsid -f`. The wrapper publishes its PID on the launch pipe and in the `.pid` sidecar before starting the command. Status and stop use that PID, not the transient launcher PID. The command runs in a separate `bash -c`, so command syntax cannot bypass the wrapper's status recording. An unclean exit means the tracked wrapper exited without recording a status. Its result includes log and status paths for inspection, not a claim that the workload succeeded. Do not rerun a side-effecting command merely because its exit status is unavailable.

The launch pipe also records the wrapper's own Linux process start identity before readiness. Failed-insertion cleanup checks that identity before sending a signal, rather than trusting a PID that might have been reused while persistence stalled. Without that proof, cleanup retains live work and logs the uncertainty.

## Settings

| Key | Role | Default |
|---|---|---|
| `tools.bash.background_prompt_threshold_seconds` | When bash may offer backgrounding | `15` |
| `tools.background_process.path` | Storage directory | `.hatfield/tmp/bg` |
| `tools.background_process.stop_grace_seconds` | TERM grace period | `5` |
| `tools.background_process.log_tail_chars` | Default log tail size | `5000` |

See [settings.md](settings.md).

## Safety

- Prefer stop via `bg_status` rather than guessing PIDs.
- Background workloads may outlive a single LLM turn; still stop them when finished.
- Never signal unrelated host processes; tracking is limited to Hatfield-managed records for the session.
