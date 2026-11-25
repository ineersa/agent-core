# Hatfield 360 for Claude Code

This plugin handles Claude hooks in Node.js and sends the Hatfield 360 v1
JSON messages directly over the desktop app’s Unix socket. It does not need
PHP, Composer or the Hatfield executable.

## Setup

Requires Node.js 18.14+ on macOS or Linux and npm on PATH. Add
`ineersa/agent-core` as a Git marketplace and install `hatfield-360@hatfield`.
Use a Claude Code version that supports [cached plugin dependency installs](https://code.claude.com/docs/en/plugins/loading.md#nodejs-package-dependencies).
Claude Code installs the locked npm dependencies with package scripts disabled
when it creates the cached plugin copy. Hooks never install dependencies. A
relative plugin loaded in place from a local-path marketplace is not provisioned
this way; use the Git marketplace for normal installs.

Launch Claude from the desktop app on macOS or Linux to receive the socket
endpoint and scoped credentials. Manual desktop-shell registration remains
Linux-only. Do not pass credentials in arguments or input. Restart Claude
after updating from the `flock` reporter so old and new hooks don't write
the same state under different locks. Hatfield’s `/reload` does not affect Claude.

The JavaScript state lock retries every 100 ms for a five-second contention
window, including while another hook sends its report. The reporter budgets
seven seconds total for input, locking, reporting and cleanup; Claude allows
eight seconds. If contention exhausts the wait, the hook logs a diagnostic and
lets Claude continue without reporting that update. A skipped child start leaves
a marker that prevents reporting a definite zero. After an abrupt exit, an
abandoned lock can block updates until its 10-second stale timeout.

The Agent SDK still declares optional platform-specific binary packages. Claude
Code's documented cached installer disables scripts but does not document
omitting optional packages. A network policy blocking those downloads can
prevent a clean marketplace install; binary-free automatic installation is
not verified. The title lookup itself does not require those optional binaries.

## Titles and notices

The reporter sends the name Claude shows for the session in `/resume`: the
hook's `session_title`, else the session's custom or generated title, else its
latest prompt, read through the official SDK's `getSessionInfo` on startup,
clear, resume, prompt submission and matched Stop. The name is cut to one line
of 200 characters. The lookup starts before the state lock and has 1.5 seconds;
a failed or late lookup logs `hook_title_unavailable` or `hook_title_timeout`
and keeps the name already known for the same selection.

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
can establish zero only when it contains no in-flight `subagent` or `teammate`
entries and there are no tracked starts left. Missing or malformed samples do
not establish zero. Registry task IDs are not treated as agent IDs. Untracked
`SubagentStop` hooks are ignored. A tracked stop while the main response is
working removes that agent; its registry can add uncertainty but cannot clear it.
A tracked stop after the main response stopped keeps the agent counted until the
next prompt, because Claude then wakes with a new prompt to handle the child's
result (recorded with Claude Code 2.1.331): the session stays working instead of
settling or going unknown before that response. Another hook can block the stop and
keep the child working, so this heuristic can report completion early.
Pending-start uncertainty remains sticky.
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

### Schedules and loops

Session cron jobs (`/loop 5m …`, `CronCreate`) and self-paced loops (`/loop …`
with no interval, `ScheduleWakeup`) are tracked from their `PostToolUse`
results, as recorded with Claude Code 2.1.331:

- `CronCreate` adds a job with its `humanSchedule` label (or the cron
  expression), recurrence, `nextFireAt` and, for recurring jobs, the
  `maxAgeMs` expiry. `CronDelete` removes it. A `CronList` result replaces
  the whole list. At most 16 jobs are tracked.
- `ScheduleWakeup` replaces the pending wakeup, shown as one "Self-paced loop"
  entry.
- A run is an ordinary `UserPromptSubmit` whose prompt equals the job's or
  wakeup's prompt. It counts as a scheduled turn (`turn.trigger: "schedule"`)
  only when the prompt matches and the run is due (at most 90 seconds before
  the expected time). The same prompt typed earlier stays a user turn, so a
  run can't be told from a typed copy within that window.
- After a recurring run, the next time is the next local-time cron match plus
  the job's fixed jitter, learned from its first `nextFireAt`. A one-shot job
  is removed when it runs. A wakeup run that finishes without scheduling
  another wakeup ends the loop.

Only keyed hashes of prompts are stored, and only the label, recurrence and
next time are sent; prompts and cron fields stay private. Hooks never see a
missed run or a recurring job's expiry, so expired jobs and one-shot jobs a day
overdue are dropped on the next hook. A clear or resume starts an empty list
until the next `CronList`. Snapshots include `schedules` only while the list is
non-empty, so sessions without schedules keep working with Hatfield 360 builds
that predate the field.

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
