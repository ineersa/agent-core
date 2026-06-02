---
description: Finish an IN-PROGRESS task, run validation, and move to CODE-REVIEW
argument-hint: "<task>"
---

/task-finish <task>

Prepare a tracked task for code review:

1. **Inspect worktree state**
   - `task_list` or read the task file to confirm it is IN-PROGRESS with worktree metadata.
   - `cd` into the worktree path from task metadata.
   - Run `git status --short --branch` and `git log --oneline --decorate -10`.
   - Inspect `git diff --stat origin/main...HEAD` to understand the full diff.

2. **Review quality**
   - Run the reviewer subagent on the worktree (subagent agent="reviewer" cwd=worktree).
   - If reviewer returns REQUEST CHANGES, analyze the blockers, create a plan, and launch a fork.
   - Repeat until reviewer returns APPROVED for current HEAD.

3. **Run validation**
   - Run `LLM_MODE=true castor check` in the worktree when prerequisites are available (tmux, llama.cpp).
   - If prerequisites are missing, run the subset that works:
     `castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`.
   - Report exact validation results.

4. **Update task metadata**
   - Use `update_task` to record the reviewer decision, commit sha, and validation results.
   - Append a work log entry summarizing the fork commits and reviewer outcome.

5. **Move to CODE-REVIEW**
   - Call `move_task` with the task slug and `to="CODE-REVIEW"`. When PR #83 extension is active, this runs the Castor quality gate automatically.
   - Record the PR URL returned in the notes.
   - If the Castor gate fails (pre-existing flake), document it and report to parent.
