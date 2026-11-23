# Hatfield 360 reporter

This package implements Hatfield 360's v1 Unix socket protocol. The Hatfield host can call `StatusClient` in-process; the Claude Code plugin invokes the same library through `h360-report`. Neither the library nor the CLI boots Hatfield.

## Install and run

On Unix, install the package's Composer dependencies (`composer install --no-dev` from this directory). The PHP CLI must be on `PATH`, with the mbstring and POSIX extensions enabled. Run `bin/h360-report` directly or use the Composer-installed binary. To try the Claude plugin locally, pass this installed directory to `claude --plugin-dir`. A deployed plugin must have its dependencies installed before launching Claude; hooks never run Composer or install packages.

The desktop launcher supplies `H360_STATUS_ENDPOINT`, `H360_STATUS_LAUNCH_ID`, and `H360_STATUS_TOKEN`. Do not put credentials in the payload or command arguments. Outside a desktop launch, Claude hooks do nothing. The CLI's explicit mode reads exactly one JSON event object from stdin, using the v1 event fields at the top level. It supplies `version`, `eventId`, `launchId` and `token` itself; any such fields in the payload are rejected.

```sh
printf '%s\n' '{"kind":"session.started","sessionId":"a-conversation","sequence":1,"sessionEpoch":1,"provider":"hatfield","capabilities":[]}' | bin/h360-report
```

In explicit mode the caller owns sequencing and correlation. Exit 0 means a successful protocol reply (including stale or duplicate no-ops); 1 means delivery failed or credentials were absent; 2 means the event was invalid. Do not mix explicit reporting with Claude hook reporting under the same launch credentials. For manual Hatfield registration, the host supplies a verified owner identity to `StatusClient::registerManual()`; the library does not discover or infer process ownership.

## Claude hook coverage

The plugin reports explicitly launched Claude session selections (`startup`, `resume`, `clear`) and terminal session-end reasons. It excludes compaction and forks, which do not reliably indicate a new foreground conversation. A `UserPromptSubmit` carrying a UUID `prompt_id` starts a turn, and `StopFailure` ends it only when the same session and prompt still match. Older Claude Code versions or events without `prompt_id` omit turn telemetry. The plugin deliberately does not report successful completion, subagent lifecycle, waits, scripts or manual registration. Consequently normal success does not clear a reported working turn; a later turn, session selection, or session end replaces or clears it.

The reporter holds a nonblocking per-launch lock in the desktop app's private socket directory. State contains only session/turn identifiers and sequence numbers, never credentials or prompt text. An overlapping hook may be dropped. Hook execution order is not a causal guarantee: Claude can start some session hooks in the background and supplies no selection sequence. A delayed startup cannot replace an already observed selection, but overlapping `/clear` and resume hooks without a causal marker are only best effort. The hook command exits quietly on malformed input or delivery failure.

Mapping reference: [Claude Code hooks](https://code.claude.com/docs/en/hooks#hook-events) and [Hatfield 360 v1 protocol](https://github.com/ineersa/hatfield-360/blob/main/docs/agents/agent-status-protocol.md).
