# Task board

Repo-local lightweight issue tracker for agent work.

## Status directories

- `TODO/` — accepted but unclaimed tasks
- `IN-PROGRESS/` — claimed tasks with branch/worktree metadata
- `CODE-REVIEW/` — tasks with pushed branches and linked GitHub PRs awaiting review
- `DONE/` — completed tasks merged back into the integration checkout

Use the pi `task_list`, `create_task`, `move_task`, and `update_task` extension tools instead of manually moving task files.

## Workflow

1. Create or pick a task from `tasks/TODO/`.
2. Move it to `IN-PROGRESS` with `move_task`. This creates:
   - branch: `task/<task-slug>`
   - worktree: `../agent-core-worktrees/<task-slug>`
   - isolated `vendor/` copy when `vendor/` exists in the integration checkout
   - `.vera` index copy when `.vera/` exists in the integration checkout
3. Do the work in the worktree and commit it on the task branch.
4. Record fork run IDs, validation results, and other metadata with `update_task`.
5. When ready for review, the parent/orchestrator/user moves the task to `CODE-REVIEW` with `move_task`. This:
   - pushes the task branch to the remote (`git push -u origin <branch>`)
   - creates a GitHub PR via the `gh` CLI unless `pushOnly: true`
   - stores the PR URL and status in the task file
   - reuses an existing PR if one already exists for the branch
6. After PR review and approval, move it to `DONE` with `move_task`. This merges the task branch into the integration checkout. If conflicts occur, the task stays in its current status.

`move_task` requires a clean integration checkout before merging to `DONE`. If it reports only stale `AD` entries (staged additions that were deleted from the worktree), retry with `cleanupStaleIndexEntries: true` to reset those index entries. Do not auto-commit unrelated staged changes just to complete the workflow.

To remove an abandoned worktree, use Castor so Git metadata is cleaned up too:

```bash
castor worktree:remove <task-slug-or-path> --force
castor worktree:remove <task-slug-or-path> --force --delete-branch
```

## Tools

| Tool | Purpose |
|------|---------|
| `task_list` | List tasks, optionally filtered by status |
| `create_task` | Create a new task in TODO |
| `update_task` | Update metadata (fork run, PR info, validation, work log) without changing status |
| `move_task` | Move task between statuses with side effects (worktree creation, push/PR, merge) |

## Worktree note

IDE tools are scoped to the current checkout. `move_task` copies `.vera/` into new worktrees when the index exists, so semantic search can work there, but use main-checkout IDE tools for discovery when appropriate, and use absolute-path file tools or a separate pi session rooted in the worktree for implementation.
