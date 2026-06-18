# Move agent-core task board to external repo-local task store

## Goal
Goal: keep Markdown task specs/state git-backed, but remove mutable task-board churn from the agent-core code repository and its worktree/PR flow.

Context:
- Current `tasks/` directory lives inside `/home/ineersa/projects/agent-core` and task workflow commits metadata/status moves to `main`.
- This caused worktree/rebase/push pain when code tasks update task status while PR branches and main move independently.
- User wants a separate directory/repo at `/home/ineersa/projects/agent-core-tasks/` containing the current task board.

Implementation notes:
- Bootstrap `/home/ineersa/projects/agent-core-tasks/` by copying the current task board (`TODO`, `IN-PROGRESS`, `CODE-REVIEW`, `DONE`) there.
- Refactor the pi task workflow extension/tools so the task board root is configurable and can point outside the code repo.
- Split the task workflow implementation up from one large file; DRY shared operations.
- Stop committing/pushing task metadata/status moves as part of task operations. That commit behavior was a workaround for dirty worktree checks and becomes unnecessary/harmful once tasks live outside the code repo.
- Keep code branch/worktree/PR operations in the agent-core repo.
- Update task workflow docs and skills to describe the external task board path and new no-task-commit behavior.
- Worktree creation improvement: copy `.idea/` into the created worktree when present, and rewrite path references from the integration checkout path (e.g. `/home/ineersa/projects/agent-core`) to the new worktree path so IDE indexing points at the worktree, not main.

Open design details to decide during implementation:
- Exact config key/env var for the task board root.
- Whether `/home/ineersa/projects/agent-core-tasks/` is initialized as an independent git repo and whether task tools should auto-commit there or leave commits manual.
- Whether current in-repo `tasks/` remains as a snapshot/spec archive, is removed, or is ignored after migration.

## Acceptance criteria
- `/home/ineersa/projects/agent-core-tasks/` contains a copy of the current task board with TODO/IN-PROGRESS/CODE-REVIEW/DONE.
- Task tools can list/create/update/move tasks from the external task board path while running in `/home/ineersa/projects/agent-core`.
- Task metadata/status moves no longer commit/push changes to the agent-core code repository.
- Task branch/worktree/PR operations still run against the agent-core code repository.
- Task workflow implementation is split/DRY enough that task-board filesystem operations are separate from code-repo git/worktree operations.
- Docs/skills/project instructions are updated to explain the external task board and no task-status commits to code main.
- Worktree creation copies `.idea/` when present and rewrites absolute path references from integration checkout path to worktree path.
- Validation covers task list/create/update/move behavior against an external task root and worktree creation behavior including `.idea` path rewrite.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/task-workflow-external-task-store
Worktree: /home/ineersa/projects/agent-core-worktrees/task-workflow-external-task-store
Fork run:
PR URL:
PR Status:
Started: 2026-06-18T02:02:21.061Z
Completed:

## Work log
- Created: 2026-06-18T01:54:42.880Z

## Task workflow update - 2026-06-18T02:02:21.061Z
- Moved TODO → IN-PROGRESS.
- Created branch task/task-workflow-external-task-store.
- Created worktree /home/ineersa/projects/agent-core-worktrees/task-workflow-external-task-store.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/task-workflow-external-task-store.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/task-workflow-external-task-store.
- Validation: Pre-start: `/home/ineersa/projects/agent-core-tasks` exists, contains copied current task board, and is initialized as a clean standalone git repo at commit b024b77.; Pre-start: `/home/ineersa/projects/agent-core` main checkout clean/synced with origin.
- Summary: Starting implementation. Goal: move mutable task board out of agent-core into `/home/ineersa/projects/agent-core-tasks`, refactor `.pi/extensions/task-workflow.ts` into a configurable external task-root workflow with no task metadata commits to the agent-core code repo, update docs/skills/prompts, and copy/rewrite `.idea` into task worktrees.
