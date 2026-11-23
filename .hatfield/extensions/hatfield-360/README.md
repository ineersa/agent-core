# Hatfield 360 reporter

This package implements Hatfield 360's v1 Unix socket protocol. The Hatfield host can call `StatusClient` in-process; the Claude Code plugin invokes the same library through `h360-report`. Neither the library nor the CLI boots Hatfield.

## Install and run

On Unix, install the package's Composer dependencies (`composer install --no-dev` from this directory). The PHP CLI must be on `PATH`, with the mbstring and POSIX extensions enabled. Run `bin/h360-report` directly or use the Composer-installed binary. To try the Claude plugin locally, pass this installed directory to `claude --plugin-dir`. A deployed plugin must have its dependencies installed before launching Claude; hooks never run Composer or install packages.

The desktop launcher supplies `H360_STATUS_ENDPOINT`, `H360_STATUS_LAUNCH_ID`, and `H360_STATUS_TOKEN`. Do not put credentials in the payload or command arguments. Outside a desktop launch, Claude hooks do nothing. The CLI's explicit mode reads exactly one JSON event object from stdin, using the v1 event fields at the top level. It supplies `version`, `eventId`, `launchId` and `token` itself; any such fields in the payload are rejected.

```sh
printf '%s\n' '{"kind":"session.started","sessionId":"a-conversation","sequence":1,"sessionEpoch":1,"provider":"hatfield","capabilities":[]}' | bin/h360-report
```

In explicit mode the caller owns sequencing and correlation. Exit 0 means a successful protocol reply (including stale or duplicate no-ops); 1 means delivery failed or credentials were absent; 2 means the event was invalid. Do not mix explicit reporting with Claude hook reporting under the same launch credentials. For manual Hatfield registration, the host supplies a verified owner identity to `StatusClient::registerManual()`; the library does not discover or infer process ownership.

## Hatfield graceful quit

Hatfield advertises `session-end` and makes a bounded, best-effort attempt to
report `session.ended` for the selected conversation on final interactive quit.
It discards unsent activity and does not initiate manual registration during quit.
Switches, drafts, reloads and abrupt termination do not send this event.
Hatfield 360 still observes process exit independently.

## Claude hook coverage

The plugin reports explicitly launched Claude session selections (`startup`, `resume`, `clear`) and terminal session-end reasons. It excludes compaction and forks, which do not reliably indicate a new foreground conversation. A foreground `UserPromptSubmit` carrying a UUID `prompt_id` starts a turn. Subsequent hooks must match that prompt and the selected session; child hooks are ignored. `Stop` with `stop_hook_active: false` ends the foreground turn as `unknown`, never as `completed`; `StopFailure` reports a correlated failure. `PreToolUse` reports working activity for that turn without tool names, inputs, or results.

The `waits` capability covers only MCP `Elicitation`/`ElicitationResult` pairs whose hooks both supply matching `mcp_server_name`, `elicitation_id`, and current `prompt_id`. This is `external-input`, not a permission wait. In particular, common form-mode examples omit `elicitation_id`; those do not produce telemetry. Only a hash of the server/request pair is sent, never a message, URL, schema, or response. At most 16 distinct pairs per turn are tracked. There is no inferred cancellation, idle, permission, subagent, script, or manual Claude registration telemetry.

The reporter holds a nonblocking per-launch lock in the desktop app's private socket directory. State contains only session/turn identifiers and sequence numbers, never credentials or prompt text. An overlapping hook may be dropped. Hook execution order is not a causal guarantee: Claude can start some session hooks in the background and supplies no selection sequence. A delayed startup cannot replace an already observed selection, but overlapping `/clear` and resume hooks without a causal marker are only best effort. The hook command exits successfully without stdout on delivery or input failure. Caught input and state exceptions write only a fixed JSON diagnostic to stderr (`component: hatfield_360`, `event_type: hook_input_failed` or `hook_state_failed`); normally filtered hooks are silent.

Mapping reference: [Claude Code hooks](https://code.claude.com/docs/en/hooks#hook-events) and [Hatfield 360 v1 protocol](https://github.com/ineersa/hatfield-360/blob/main/docs/agents/agent-status-protocol.md).

Both host adapters retain only the latest unacknowledged session selection and retry its original frame on a later eligible observation before sending dependent events. Only `accepted` and `duplicate` confirm registration. A newer selection replaces the checkpoint and drops older queued observations. There is no background retry loop or historical replay; transient bootstrap or socket failures remain fail-open.
