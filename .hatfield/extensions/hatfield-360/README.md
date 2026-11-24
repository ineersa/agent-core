# Hatfield 360 reporter

This package implements Hatfield 360's v1 Unix socket protocol. The Hatfield host can call `StatusClient` in-process; the Claude Code plugin invokes the same library through `h360-report`. Neither the library nor the CLI boots Hatfield.

## Install and run

The standalone Claude plugin requires PHP 8.5 with mbstring and POSIX, and Node.js 18.14 or later for title lookup. Both executables must be on `PATH`. The release ZIP includes its locked PHP and Node dependencies; it does not require the Hatfield executable, Composer, or npm at runtime. Hooks never install dependencies.

The marketplace archive will be available after the first tagged release that publishes `hatfield-360.zip`. For local development, run `castor distribution:claude-plugin --release-version=v0.0.0` from the repository root and pass `var/tmp/dist/hatfield-360.zip` to `claude --plugin-dir` on Claude versions supporting ZIPs, or extract it and pass the plugin directory. Build again to test local changes.

**Hatfield:** Enable the built-in Hatfield 360 extension in `hatfield setup`. A project-level `extensions.enabled` list replaces the global list; if the project defines one, keep `Ineersa\HatfieldExt\Hatfield360\Hatfield360Extension` in that list alongside the other enabled extensions. Preserve existing settings and list entries. Reload Hatfield with `/reload` or restart it after changing enablement.

**Claude Code:** After updating the marketplace plugin, reload it or restart Claude Code to use the downloaded version. Update behavior for the moving release URL has not yet been verified. If the active plugin is missing `vendor/autoload.php`, the hook emits `hook_dependencies_missing` on stderr and exits successfully without reporting status. Hatfield's `/reload` does not affect the Claude plugin.

The desktop launcher supplies `H360_STATUS_ENDPOINT`, `H360_STATUS_LAUNCH_ID`, and `H360_STATUS_TOKEN`. Do not put credentials in the payload or command arguments. Without an explicit or Linux managed launch context, Claude hooks do nothing. The CLI's explicit mode reads exactly one JSON event object from stdin, using the v1 event fields at the top level. It supplies `version`, `eventId`, `launchId` and `token` itself; any such fields in the payload are rejected.

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

Snapshots can include a nullable `title` of at most 1,024 Unicode characters.
Hatfield reads its picker title on selection and at the next foreground turn
after a rename. Claude Code prefers the hook's custom `session_title`, then
looks up only `customTitle` (which can also contain an AI-generated title) via
the official Agent SDK on selection, prompt submission, and a matched final
`Stop`. This optional lookup requires Node.js 18.14 or later on `PATH` and the
locked npm dependencies in the active plugin (`npm ci` for a local checkout).
Hooks never install dependencies. A missing title or lookup failure produces
`null`, not a prompt or summary. Neither adapter watches for rename-only updates.

## Send an additional notification

In Hatfield, enter `/h360-notify Review available | The report is ready.` in the
selected conversation. The first `|` separates the title and body. The command
reports whether the desktop confirmed acceptance, not whether it showed a toast.

For Claude, run the following from an environment that already has **that same
desktop launch's** context, after the plugin has selected a conversation:

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
The existing TUI operational poll reports separate counts of owned nonterminal
children and Bash/code-mode work. Counts start unknown and reconcile after
session readiness, including on resume. An unavailable read reports `null`.
A zero sampled before the foreground response finishes cannot settle it; a
subsequent poll must confirm zero children. Scripts do not gate completion.
Final interactive quit attempts `session.ended`; drafts, switches, reloads and
abrupt exits do not send it. Quit
never initiates manual Linux bootstrap.

## Claude Code coverage

Explicit and verified Linux managed launches report startup, clear and resume selections and terminal exit
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
marker for background-only refresh.

A launch lock serializes hooks and associated socket delivery in the private
socket directory. A competing hook waits up to 650 ms with explicit credentials
or 350 ms for a managed launch. Each socket exchange has its own 250 ms deadline
(managed reporting can need two). If a
subagent start times out, subsequent snapshots conservatively report an unknown
subagent count only for that admitted owner instance. A completed snapshot also
reports an unknown count if a start has announced itself but is still waiting for
the launch lock. This check runs just before publication; it cannot account for a
hook that has not yet announced itself or recall an already-sent snapshot. The first
startup, resume or clear can establish a selection without a previous boundary.
Afterward, switching requires a matching outgoing `SessionEnd` from the current
selection. Resume pairs by prompt ID; clear consumes one clear boundary because
Claude 2.1.330 omits the incoming prompt ID. A delayed incoming clear can still
be mistaken for a different clear if it arrives while that boundary is armed.
The reporter rejects reuse of recorded boundary prompt IDs, and fails closed on
further switches after 128 boundaries in one launch. A missing or out-of-order
boundary does not authorize a switch. Foreground hooks still require the current
session and prompt; Esc is not inferred.
State stores no credentials, prompts or results. Hook failures do not interrupt
Claude; caught input/state exceptions log only a fixed diagnostic code to stderr.

### Linux managed Claude launches

The desktop-managed shell supplies the status endpoint and bootstrap token. If
an explicit credential variable is set, even empty or malformed, bootstrap is
never attempted. Keep the native `claude` executable on `PATH`: its resolved
device and inode must identify exactly one ancestor with a controlling terminal
in the hook's PID namespace. Wrappers that do not resolve to the running native
executable, unreadable or inconsistent relevant `/proc` observations, and
ambiguous ancestry fail open. The hook itself is never the owner. Desktop
independently verifies the reported process beneath its pinned terminal.

Managed hooks use a stable per-owner instance and bounded private state in the
app's socket directory. Credentials returned by registration live only in the
calling process; they are not persisted or exported. Each outgoing observation
re-registers idempotently, so busy hooks can hit the desktop's bootstrap budget
or exhaust the lock wait and miss delivery. A later eligible observation sends
its full current snapshot; missed notices and historical frames are not replayed.
For `--claude-notify` in a managed launch, the invocation must also be a descendant
of the verified Claude owner, not a sibling command from the pane shell.

See [Claude Code hooks](https://code.claude.com/docs/en/hooks#hook-events) and the
[Hatfield 360 snapshot protocol](https://github.com/ineersa/hatfield-360/blob/main/docs/agents/agent-status-protocol.md).
