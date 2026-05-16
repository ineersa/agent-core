# Task board

Repo-local lightweight issue tracker for agent work.

## Status directories

- `TODO/` — accepted but unclaimed tasks
- `IN-PROGRESS/` — claimed tasks with branch/worktree metadata
- `DONE/` — completed tasks merged back into the integration checkout

Use the pi `task_list`, `create_task`, and `move_task` extension tools instead of manually moving task files.

## Workflow

1. Create or pick a task from `tasks/TODO/`.
2. Move it to `IN-PROGRESS` with `move_task`. This creates:
   - branch: `task/<task-slug>`
   - worktree: `../agent-core-worktrees/<task-slug>`
3. Do the work in the worktree and commit it on the task branch.
4. Run validation.
5. Move it to `DONE` with `move_task`. This merges the task branch into the integration checkout. If conflicts occur, the task stays `IN-PROGRESS`.

## Worktree note

IDE tools and semantic search are scoped to the current checkout. Sibling worktrees may not be indexed. Use main-checkout IDE tools for discovery when appropriate, and use absolute-path file tools or a separate pi session rooted in the worktree for implementation.
