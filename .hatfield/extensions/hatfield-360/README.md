# Hatfield 360 reporter

This package implements Hatfield 360's v1 Unix socket protocol. The Hatfield host can call `StatusClient` in-process; the Claude Code plugin invokes the same library through `h360-report`. Neither the library nor the CLI boots Hatfield.

## Install and run

On Unix, install the package's Composer dependencies (`composer install --no-dev` from this directory). The PHP CLI must be on `PATH`, with the mbstring and POSIX extensions enabled. Run `bin/h360-report` directly or use the Composer-installed binary. To try the Claude plugin locally, pass this installed directory to `claude --plugin-dir`. A deployed plugin must have its dependencies installed before launching Claude; hooks never run Composer or install packages.

The desktop launcher supplies `H360_STATUS_ENDPOINT`, `H360_STATUS_LAUNCH_ID`, and `H360_STATUS_TOKEN`. Do not put credentials in the payload or command arguments. Outside a desktop launch, Claude hooks do nothing. The CLI's explicit mode reads exactly one JSON event object from stdin, using the v1 event fields at the top level. It supplies `version`, `eventId`, `launchId` and `token` itself; any such fields in the payload are rejected.

```sh
printf '%s\n' '{"kind":"session.snapshot","sessionId":"a-conversation","sequence":1,"sessionEpoch":1,"provider":"hatfield","turn":null,"attention":null,"activeSubagents":null,"backgroundScripts":null,"tool":null}' | bin/h360-report
```

In explicit mode the caller owns sequencing and correlation. Exit 0 means an accepted event or an exact duplicate; 1 means the event was stale, delivery failed, or credentials were absent; 2 means the event was invalid. Do not mix explicit reporting with Claude hook reporting under the same launch credentials. For manual Hatfield registration, the host supplies a verified owner identity to `StatusClient::registerManual()`; the library does not discover or infer process ownership.

## Snapshot delivery

Each snapshot replaces the entire selected conversation status. A later snapshot
can establish an epoch even when the first was lost. Hatfield coalesces unsent
snapshots. Neither adapter replays historical observations, retries in the
background, or guarantees eventual delivery. `session.ended` requires an already
live epoch and does not imply process exit.

## Send an additional notification

In Hatfield, enter `/h360-notify Review available | The report is ready.` in the
selected conversation. The first `|` separates the title and body. The command
reports whether the desktop confirmed acceptance, not whether it showed a toast.

For Claude, run the following from an environment that already has **that same
desktop launch's** credentials, after the plugin has selected a conversation:

```sh
/path/to/hatfield-360/bin/h360-report --claude-notify <<'JSON'
{"title":"Review available","body":"The report is ready."}
JSON
```

For dynamic text, generate the JSON with an encoder and pipe it on stdin; do not
interpolate text into a shell command. There is no bundled Claude slash command:
the CLI does not depend on a prompt or shell expansion to pass user arguments.
This mode shares the hooks' lock and sequence. Exit 0 means accepted or duplicate;
1 means unconfirmed (including missing or ended selection or a busy lock); 2
means invalid input. Both commands require nonempty plain text and limit the
title to 200 Unicode scalar values and the body to 2,000.

These are opt-in notices only. Neither adapter forwards provider lifecycle or
owner messages. The desktop still derives lifecycle notices from snapshots and
applies its normal toast suppression to additional notifications.

## Hatfield coverage

Foreground observations report work, final response, failure or cancellation,
correlated foreground and child question/approval waits and tool names (not tool inputs or results).
Tool completion or failure clears the matching progress marker, not the response.
Native child execution and accepted Bash/code-mode background work contribute
independent counts, including work routed from nested children. A terminal child
observation must follow the recorded start. Cancellation requests and synthetic
parent-side artifact settlement do not count as native exits. Counts are
best effort; resume/attach does not reconstruct already-running work and sends
`null` for the unavailable counts. Final interactive quit attempts
`session.ended`; drafts, switches, reloads and abrupt exits do not send it. Quit
never initiates manual Linux bootstrap.

## Claude Code coverage

Explicit launches report startup, clear and resume selections and terminal exit
hooks. A foreground `UserPromptSubmit` starts a response; a matching final
`Stop` ends it as completed, and a matching `StopFailure` marks it failed.
Subagent start/stop hooks track an approximate active count by agent ID, without
parent links or execution IDs. Reused IDs and overlapping hooks can make the
count approximate; resume cannot reconstruct existing work and reports `null`.
A matching elicitation request/result pair with a server and elicitation ID
reports external-input attention. Selected built-in tool hooks report only
names, correlated by tool-use ID, never inputs or results. `AskUserQuestion`
indicates question attention at its correlated pre-tool hook. `PermissionRequest`
does not carry a tool-use ID, so it reports attention only when the current
prompt has exactly one pending supported tool with the same name and exact
bounded input fingerprint. The fingerprint is keyed and stays in private state.
Matching post-tool success/failure, a final response, or a new prompt clears the
wait. The plugin never approves or denies a request. Esc and denials without
matching terminal hooks cannot be recognized immediately.

A matching foreground `Stop` samples running shell tasks from `background_tasks`.
An explicit empty array reports zero; missing or malformed observations report
`null`. Only the count is sent, never task IDs, commands, or descriptions. The
count is the last sample, not a live census. Repeated or superseded Stop hooks
do not refresh it: the recorded capture does not establish a reliable causal
marker for background-only refresh. Manual Claude owner registration is not wired.

A nonblocking launch lock protects bounded state in the private socket directory.
A competing hook can be dropped rather than waiting behind socket I/O. Once a
selection is observed, a delayed startup cannot replace it; hooks carrying an
old session ID cannot update it, and foreground hooks must match the current
prompt. Claude supplies no causal sequence for competing clear/resume selections,
so a late selection hook can still replace the current selection; the same
session ID also cannot distinguish a prior selection of that conversation.
State stores no credentials, prompts or results. Hook failures do not interrupt
Claude; caught input/state exceptions log only a fixed diagnostic code to stderr.

See [Claude Code hooks](https://code.claude.com/docs/en/hooks#hook-events) and the
[Hatfield 360 snapshot protocol](https://github.com/ineersa/hatfield-360/blob/main/docs/agents/agent-status-protocol.md).
