# Task board (legacy in-repo snapshot)

> ⚠️ **The active task board has moved outside this repository.**
> Current task files live at `/home/ineersa/projects/agent-core-tasks/`
> (configured in `.pi/settings.json` → `taskWorkflow.taskRoot`).
>
> The `tasks/` directory in this repo is a **legacy snapshot** of the task board
> at the time of migration. It is no longer the source of truth for task state.
> New task operations (`move_task`, `update_task`, `create_task`, `task_list`)
> use the external task board.
>
> **Task status/metadata moves no longer commit to this git repository.**

## External task board at `/home/ineersa/projects/agent-core-tasks`

Same structure: `TODO/`, `IN-PROGRESS/`, `CODE-REVIEW/`, `DONE/`.

Same workflow: use pi extension tools (`task_list`, `create_task`, `move_task`, `update_task`).

## Workflow

1. Create or pick a task.
2. Move it to `IN-PROGRESS` with `move_task`. This creates:
   - branch: `task/<task-slug>`
   - worktree: `../agent-core-worktrees/<task-slug>`
   - copies `vendor/`, `.vera/`, and `.idea/` (with path rewriting) into the worktree
3. Do the work in the worktree and commit it on the task branch.
4. Record fork run IDs, validation results, and other metadata with `update_task`.
5. When ready for review, the parent/orchestrator/user moves the task to `CODE-REVIEW` with `move_task`. This:
   - pushes the task branch to the remote (`git push -u origin <branch>`)
   - creates a GitHub PR via the `gh` CLI unless `pushOnly: true`
   - stores the PR URL and status in the task file
6. After PR review and approval, move it to `DONE` with `move_task`. This merges the task branch into the integration checkout.

## Tools

| Tool | Purpose |
|------|---------|
| `task_list` | List tasks, optionally filtered by status |
| `create_task` | Create a new task in TODO |
| `update_task` | Update metadata without changing status |
| `move_task` | Move task between statuses with side effects |

## Worktree note

IDE tools are scoped to the current checkout. `move_task` copies `.vera/` and `.idea/` (with path rewriting) into new worktrees so semantic search and IDE indexing work there.
