# Hatfield 360 for Claude Code

This plugin handles Claude hooks in Node.js and sends the Hatfield 360 v1
JSON messages directly over the desktop app’s Unix socket. It does not need
PHP, Composer or the Hatfield executable.

## Setup

Requires Node.js 18.14+ and `flock` from util-linux on PATH (used for
crash-safe cross-process locking). From a checked-out plugin directory, run
`npm ci --omit=optional --ignore-scripts`, then point Claude Code at the
prepared directory with `claude --plugin-dir /path/to/hatfield-360`.
The Git marketplace ships the source; it does not provision `node_modules`.
Clean marketplace install/update with title lookup is not yet supported out of
box. Hooks never install dependencies. Without the SDK, status still works,
but titles not supplied by a hook cannot be looked up.

The desktop sets the socket endpoint and scoped launch credentials. Do not
pass credentials in arguments or input. Reload the Claude plugin or restart
Claude after updating it; Hatfield’s `/reload` does not affect Claude.

## Titles and notices

The reporter prefers the hook’s `session_title`. Otherwise, on selection,
prompt submission and matched Stop, it asks the official SDK for the exact
session and uses only `customTitle` (which can include the generated title).
A failed lookup does not invent a name or replace an already known title in
the same selection. It never uses prompts or summaries as titles.

To send an opt-in notice after the plugin has selected a conversation, run
this from the same desktop launch context:

```sh
node /path/to/hatfield-360/src/hook.mjs --notify <<'JSON'
{"title":"Review available","body":"The report is ready."}
JSON
```

Exit 0 means accepted or duplicate; 1 means unconfirmed; 2 means invalid input. Use a
JSON encoder for dynamic text, not shell interpolation. Notices do not
replace the desktop’s lifecycle notifications.

## Claude Code coverage

Explicit and verified Linux managed launches report startup, clear and resume selections and terminal exit
hooks. A foreground `UserPromptSubmit` starts a response; the adapter treats a
matching `Stop` with `stop_hook_active: false` as completed, and a matching
`StopFailure` as failed. The Stop flag does not guarantee that another hook
will not block the stop and continue the response. This integration has no
authoritative post-hook continuation or interruption signal.
Subagent start/stop hooks track an approximate active count by agent ID, without
parent links or execution IDs. Reused IDs and overlapping hooks can make the
count approximate. Resume starts with an unknown baseline. A validated
parent-scoped `background_tasks` sample from a current matching foreground `Stop`
can establish zero only when it contains no subagent
entries and no observed child is still live. Missing or malformed samples do
not establish zero. Registry task IDs are not treated as agent IDs; any
subagent entry prevents zero regardless of its status. Untracked `SubagentStop`
hooks are ignored. A tracked stop removes only that observed membership; its
registry may add uncertainty but cannot clear an unknown baseline. Neither
processing under the lock nor a matching prompt establishes sample freshness.
Unknown membership therefore remains unknown until a later current matching
foreground Stop supplies valid evidence. Pending-start uncertainty remains
sticky. `SubagentStop` does not complete the main response, and the registry
may still include the stopping child in that hook's sample.
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
Accepted child membership changes and new prompts invalidate the old script
count to `null`: child registry samples have no causal freshness for an exact
count. Ignored child hooks change neither agents nor scripts. A later accepted
foreground Stop can supply a fresh count. Scripts never gate completion, and
invalidating their count does not create a second response completion.
An explicit empty array reports zero; missing or malformed observations report
`null`. Only the count is sent, never task IDs, commands, or descriptions. The
count is the last sample, not a live census. Repeated or superseded Stop hooks
do not refresh it: the recorded capture does not establish a reliable causal
marker for background-only refresh.

A launch lock serializes hooks and associated socket delivery in the private
socket directory. A competing hook waits up to 650 ms with explicit credentials
or 350 ms for a managed launch. Input is limited to 1 MiB. Reading stdin and each socket exchange are capped
at 250 ms within a shared 900 ms Node budget, including any registration retry.
If a subagent start times out, subsequent snapshots conservatively report an unknown
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
For `--notify` in a managed launch, the invocation must also be a descendant
of the verified Claude owner, not a sibling command from the pane shell.

See [Claude Code hooks](https://code.claude.com/docs/en/hooks#hook-events) and the
[Hatfield 360 snapshot protocol](https://github.com/ineersa/hatfield-360/blob/main/docs/agents/agent-status-protocol.md).
