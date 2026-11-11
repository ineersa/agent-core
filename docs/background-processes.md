---
builtin: true
description: Background Bash, PHP scripts and child calls; inspect or stop work; enable and use recurring schedules.
---

# Background and recurring work

## Start background work

Direct `bash`, `code_mode`, `subagent`, `fork` and `agent_resume` calls accept
`backgrounded: true`. Default `false` waits for their result. Background launch
returns an acknowledgement after startup; fast completion can win that race.
There is no `bg_start` tool.

You can also press **Ctrl+B** while eligible foreground work runs. Select a call
if several are eligible; Enter/Ctrl+B confirms and Escape cancels. In child live
view it targets the child's work. Dialogs retain their handling; without eligible
work Ctrl+B keeps its cursor-left behavior.

Completion arrives automatically. Continue independent work, or end the turn;
do not sleep or poll just to wait. Inspect output only for a concrete decision,
diagnosis or requested progress check. Backgrounding does not reset timeouts.
Nested `tool()` calls inside code mode cannot background Bash or another script.

## Inspect and stop

| Interface | Scope |
|---|---|
| `/bg` | Main-view picker for accepted Bash jobs and background PHP scripts, including owned children |
| `bg_status list` | Accepted Bash jobs for the calling session only |
| `bg_status log` with `pid` | Bounded Bash log tail and full-log reference |
| `bg_status stop` with `pid` | Stop that accepted Bash process group |
| `/agents-live` or Ctrl+backslash | Child status/live view; agents and forks are not `/bg` entries |

`/bg` asks for confirmation, default No, then requests stop without blocking the
terminal through termination grace. Its list is a snapshot: reopen to refresh.
An unavailable record/reply is an unknown outcome, not confirmed termination or
permission to signal a guessed PID. Stops still deliver normal completion notices.

Active work appears below the editor; children needing input are marked without
stealing focus. Completed work leaves this active area; artifacts remain available
through `agent_retrieve`.

### Ownership and recovery limits

- Bash detaches the same process, never launches a second copy. Its original
  deadline continues. Only explicitly accepted jobs are visible to `bg_status`.
- Accepted Bash survives worker recycling, not controller shutdown/restart.
  Shutdown stops owned jobs but retains native evidence for unresolved replay.
  Missing exit status does not mean an external action did not happen.
- Background code mode keeps its PHP stack/socket and original host worker.
  `/bg` can request cooperative stop. Worker loss cannot restore a lost PHP stack;
  repair must not rerun the script as a replacement.
- Background child launch/resume releases the parent's wait; the same child keeps
  its deadline and later supplies a handoff. Retrieve its existing artifact rather
  than launching a duplicate. A child Bash completion can be forwarded to the root
  parent after its owner settles; it does not automatically resume the child.
- Use Hatfield stop controls, not arbitrary signals to host processes.

Bash settings: `tools.background_process.path` (`.hatfield/tmp/bg`),
`stop_grace_seconds` (`5`) and `log_tail_chars` (`5000`). See [settings](settings.md).

## Recurring work: `schedule`

Enable the optional built-in extension with `hatfield extensions:setup`: select
**Scheduling (built in)**, enable for the parent, save and restart Hatfield.
No Composer package is needed. If `schedule` is absent from the current tool
catalog, explain this prerequisite; do not assume availability or change settings
without user approval.

Example tool arguments:

```json
{"action":"create","tool":"bash","arguments":{"command":"git status --short"},"every":"5 minutes","for_seconds":3600}
```

Use `action: list` to inspect definitions and `action: cancel` with the returned
`id` to cancel one. `/schedule` provides the terminal list/cancellation picker.
Intervals accept forms such as `30 seconds`, `5 minutes` or `PT1H`.
`code_mode` is also a target when enabled; pass its ordinary `script` arguments.

- First execution is immediately eligible. `next` describes the next recurring
  occurrence, not an initial delay.
- Without `for_seconds`, a definition lasts until cancellation or Hatfield closes.
  Definitions require the live process controller and are not restored on reopen.
- Cancellation/expiry prevents future invocations, not work already running.
- Results arrive automatically. Do not use `backgrounded: true`, sleep or polling.
- Scheduled work is noninteractive: approval-required calls and human questions
  are denied, even if the main session has an approval channel.
- There is no caller card to detach, and scheduled invocations are not stoppable
  `/bg` entries. Use execution timeouts or cancel the owning run.

Related: [tools](tools.md), [agents](agents.md), [approvals](approvals.md).
