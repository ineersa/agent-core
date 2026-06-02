---
description: Start a tracked task by moving TODO -> IN-PROGRESS and launching a fork
argument-hint: "<task>"
---

/task-start <task>

Start a tracked task in the project task workflow:

1. **Inspect task context**
   - Use `task_list` to find the task file in `tasks/TODO/`.
   - Read the task file to understand what it's about, its body, and acceptance criteria.
   - Read any docs, plans, or referenced artifacts the task body mentions.

2. **Claim the task**
   - Call `move_task` with task slug and `to="IN-PROGRESS"`. This creates a task worktree branch.
   - Record the worktree path returned in the notes.

3. **Create an implementation plan**
   - Read the task file again if moved, then read related code, config, tests, and AGENTS.md rules.
   - Plan the exact changes, files to touch, validation commands, and ordering.
   - Write the plan as a `.pi/plans/<task-slug>.md` file.

4. **Launch a fork/worker**
   - Launch a single fork on the task worktree with `cwd` set to the worktree directory.
   - Include the full plan as the fork task, with exact file paths, edit patterns, and required validation.
   - Do NOT implement directly — the fork implements.

5. **Parent oversight**
   - Wait for the fork to complete.
   - Verify the fork commit exists, inspect `git diff --stat`, and confirm the expected files changed.
   - Run focused Castor validation on the worktree (`castor test --filter=...`, `castor deptrac`, etc.).
   - Record fork run id, summary, and validation results via `update_task`.
   - If the fork failed or produced unacceptable output, re-launch with narrower instructions.

6. **Keep task updated**
   - Use `update_task` with `forkRun`, `summary`, and `validation` after each fork completes.
   - Append work log entries to track progress.
