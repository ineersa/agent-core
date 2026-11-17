---
description: List GitHub tasks and unfinished local task workspaces
argument-hint: "[todo|in-progress|code-review|done] [page]"
disable-model-invocation: true
---

Call the task-workflow `run_command` tool and show its output verbatim, without
starting any work.

Arguments: `$ARGUMENTS`

- No status: `command: "tasks"`, `args`: the page number if given.
- `todo`, `in-progress`, `code-review` or `done`: `command: "tasks-<status>"`,
  `args`: the page number if given.
