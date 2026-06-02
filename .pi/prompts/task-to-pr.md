---
description: Prepare an IN-PROGRESS task for PR by reviewing, recording, and moving to CODE-REVIEW
argument-hint: "<task>"
---

/task-to-pr <task>

Prepare a tracked task for code review:

1. **Inspect worktree state**
   - `task_list` or read the task file to confirm it is IN-PROGRESS with worktree metadata.
   - `cd` into the worktree path from task metadata.
   - Run `git status --short --branch` and `git log --oneline --decorate -10`.
   - Inspect `git diff --stat origin/main...HEAD` to understand the full diff.

2. **Review quality**
   - Run the reviewer subagent on the worktree (subagent agent="reviewer" cwd=worktree).
   - If reviewer returns REQUEST CHANGES, analyze the blockers, create exact fork instructions, and launch a fork.
   - Repeat until reviewer returns APPROVED for current HEAD.

3. **Run focused local validation**
   - Run fast Castor validation on the worktree:
     `castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`.
   - Optionally run `castor test --filter=...` for targeted coverage.
   - Do NOT run `LLM_MODE=true castor check` here — `move_task(to="CODE-REVIEW")`
     runs the full Castor quality gate automatically.
   - Report exact validation results.

4. **Update task metadata**
   - Use `update_task` to record the reviewer decision, commit sha, and validation results.
   - Append a work log entry summarizing the fork commits and reviewer outcome.

5. **Move to CODE-REVIEW**
   - Call `move_task` with the task slug and `to="CODE-REVIEW"`. This runs the
     Castor quality gate (`LLM_MODE=true castor check`) on the task branch at its
     current HEAD before pushing and creating or updating the PR.
   - Record the PR URL returned in the notes.
   - If the Castor gate fails, the task remains IN-PROGRESS. Analyze and fix the
     failure, then retry only after the full gate can pass. There is no bypass.
