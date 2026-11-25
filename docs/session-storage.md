---
builtin: true
description: Resume and name sessions, navigate history, repair interrupted work, and understand backup limits.
---

# Sessions and history

Each session has one identity: `session_id === run_id`. Child agents have their
own sessions. A new empty interactive draft may not create storage until its first
message.

## Daily commands

| Command | Action |
|---|---|
| `/new` | Start a new conversation |
| `/resume` | Pick a saved session and continue it |
| `/rename` | Change its display name, not its identity |
| `/history` | Select a conversation position; does not revert files |
| `/reload` | Reload the current session |
| `/repair` | Apply explicit repair to the current session; no arguments |

Resume refreshes the system prompt, project instructions, skills and agent catalog.
Conversation messages and compaction summaries remain. This does not start a model
turn or refresh existing children. Changed instructions/tool schemas can reduce
provider prompt-cache reuse.

During restoration, execution input stays blocked until the transcript is verified
and caught up. Failed restoration leaves the session unattached; use reload,
resume, new session or Escape rather than assuming an empty transcript is usable.
Pending human questions are cancelled on attach, not silently answered.

## List sessions from the CLI

Use `session:list` to discover persisted sessions without starting Hatfield or
requiring an integration to have observed them running:

```sh
hatfield session:list --json --cwd /projects/example --limit 5
hatfield session:list --json --since 2026-11-11T00:00:00Z --limit 20 --offset 5
hatfield session:list --json --query restoration
```

`--cwd` defaults to the invocation directory and selects the same store as
`hatfield --cwd <directory> --resume=<id>`. Only that store is listed; linked
worktrees are separate scopes when they use separate stores. `sessions.path`
locates the transcripts, not a second catalog. In JSON, `scope.cwd` is the
canonical resume context. Each row's `cwd` is the directory recorded in that
session, which may differ or no longer exist. Use `(scope.cwd, sessionId)` as
the scoped identity; `sessionId` is a string.

Filters combine before paging: `--since` is inclusive, `--until` is exclusive
(both require RFC3339 timestamps with timezones), `--query` is a case-insensitive
literal substring of the picker title or ID. Results
sort by last activity, then numeric ID, both descending. The default `--limit`
is 50 (1–200); `--offset` defaults to zero. Offset pages are not a snapshot:
deduplicate by scoped identity and reset paging when refreshing.

On success, `--json` writes one object with `version: 1`, `scope: {cwd}`,
`sessions` (rows with `sessionId`, `displayTitle`, `cwd`, `createdAt` and
`updatedAt`), and `nextOffset` (an integer or `null` when exhausted). Times are
in UTC; `updatedAt` describes persisted activity, not live or closed state.
The command lists the existing catalog; it does not start or resume a session.
Normal Hatfield CLI initialization still applies, including loading configuration
and extensions. The selected project needs an initialized session database;
CLI errors use the normal Hatfield diagnostics and nonzero exit status.

## History selection

`/history` lists user prompts and final assistant answers, not reasoning or
intermediate tool responses. Use `Older entries...` / `Newer entries...` to page.

- Selecting a **user prompt** positions context before it and restores that prompt
  to the editor.
- Selecting an **assistant answer** keeps the answer in context and loads the next
  user prompt, if present.
- Selection alone neither runs the model nor discards forward history. A later
  context-changing action can discard the forward branch.
- File changes are never undone. Check Git separately.

Resume preserves the selected position. Draft edits made during attach are not
overwritten by restored text. There is no session-tree browser or `/tree` command;
`fork` launches a child agent, not a Git branch.

## Storage and backups

Default storage is project-local:

```text
.hatfield/state.sqlite                # application metadata and coordination
.hatfield/sessions/<id>/events.jsonl   # canonical conversation history
.hatfield/sessions/<id>/artifacts/     # child handoffs, when present
```

`sessions.path` can override the session root. Keep runtime files out of Git.
Do not edit event logs, queues or runtime metadata while a controller is live.
Stop Hatfield before a consistent backup and preserve both session directories
and application state; events alone do not recover all pending work.

If catalog rows are lost but valid numeric session directories remain, startup can
recover basic session entries from events. It cannot recover DB-only renames,
pending questions, child supervision, queues or background records. SQLite WAL
with `synchronous=NORMAL` can lose recent transactions on machine/power failure.

Large tool outputs are temporary inspection files, not permanent attachments.
Their notices can remain in history after files expire or are cleared on
controller restart/shutdown. Save important output into a project file explicitly.

## Repair safely

`/repair` applies repair; it is not a preview. It may redispatch reconstructable
current work, so first check whether a command or remote tool already performed
its external action. Repair does not roll back effects or promise exactly-once
execution. Restarting a worker alone is not repair of a stranded effect.

Repair refuses unsafe/ambiguous cases and does not redispatch human-input waits.
Abandoned child waits can be cancelled and settled without launching replacements;
artifacts and completed child results remain. A failed send can be partial: inspect
state before retrying. Do not force recovery by editing queue rows.

Related: [terminal](terminal-usage.md), [compaction](compaction.md),
[human input](human-input.md), [agents](agents.md), [settings](settings.md).
