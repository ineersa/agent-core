---
description: Start a tracked task by moving TODO -> IN-PROGRESS and launching a fork
argument-hint: "<task>"
---

Start tracked task: `$ARGUMENTS`

If the task argument is empty or still the literal placeholder `<task>`, ask the user for the task slug instead of guessing. Otherwise, start the tracked task named by `$ARGUMENTS` in the project task workflow:

1. **Inspect task context**
   - Use `task_list` to find the task file (typically in `tasks/TODO/`).
   - Read the task file to understand what it's about, its body, and acceptance criteria.
   - Read any docs, plans, or referenced artifacts the task body mentions.

2. **Claim the task**
   - Call `move_task` with the task slug from `$ARGUMENTS` and `to="IN-PROGRESS"`. This creates a task worktree branch.
   - Record the worktree path returned in the notes.

3. **Prepare exact fork instructions**
   - Read the task file again if moved, then collect the required code, config, test, and docs context.
   - Launch scout subagents when useful to gather focused codebase context before implementation.
   - Use the researcher subagent for web searches or web-based research when up-to-date external information is needed.
   - Create exact implementation instructions for the fork: files to touch, old/new patterns, validation commands, and boundaries.
   - Record useful context or updates on the task with `update_task` when helpful.

4. **Launch a fork/worker**
   - Launch a single fork on the task worktree with `cwd` set to the worktree directory.
   - Include the exact implementation plan as the fork task, with file paths, edit patterns, and required validation.
   - Do NOT implement directly — the fork implements.
   - Do not wait idle for the fork; it will return a report when finished.

5. **Handle fork report**
   - When the fork report arrives, verify the commit exists, inspect `git diff --stat`, and confirm the expected files changed.
   - Record fork run id, summary, and validation results via `update_task`.
   - If the fork failed or produced unacceptable output, re-launch with narrower instructions.

6. **STOP — do not proceed to PR or code review**
   - Your responsibility ends with implementation and recording the fork result.
   - Do NOT run `castor check`, `move_task(to="CODE-REVIEW")`, `gh pr create`, `git push`, or any review/gate step.
   - Do NOT run the reviewer subagent.
   - PR preparation, Castor quality gate, review, and push are handled by the `task-to-pr` prompt — not this one.
   - Inform the user the implementation is done and they should run `task-to-pr` when ready.
