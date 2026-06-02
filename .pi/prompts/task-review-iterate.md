---
description: Respond to PR review comments with analysis, fork, and re-review
argument-hint: "<task-or-pr>"
---

/task-review-iterate <task-or-pr>

Address code review feedback on a task PR:

1. **Read all PR comments**
   - Use `gh pr view <number> --comments` or the task's PR URL from task metadata.
   - Read every inline review comment — do not guess or summarize from memory.
   - Identify the task slug from the PR branch name (pattern: `task/<slug>`).

2. **Classify feedback**
   - Separate into blockers (bugs, design flaws, safety issues) and nice-to-have.
   - Note which comments intersect with already-identified blockers on the task.
   - If a comment references external docs (e.g. Doctrine release notes, Symfony changelog), read those too.

3. **Create a plan**
   - Write a concise implementation plan covering each actionable comment.
   - Group nearby changes into single edits where possible.
   - Specify exact files, exact old/new text patterns, and validation steps.

4. **Launch a fork**
   - Launch a single fork with `cwd` set to the task worktree (from task metadata).
   - Include the implementation plan and exact edit instructions.
   - Do NOT implement directly — the fork implements.

5. **Verify fork output**
   - Confirm the fork committed (check git log on worktree).
   - Inspect `git diff --stat HEAD~1` or `git show --stat HEAD` for the fork commit.
   - Run focused Castor validation: `castor test --filter=...`, `castor deptrac`, `castor phpstan`, `castor cs-check`.
   - Verify no unintended changes (only the advertised files changed).

6. **Re-review**
   - Run the reviewer subagent again on the worktree at the new HEAD.
   - If APPROVED, proceed.
   - If REQUEST CHANGES again, repeat from step 3 with the new feedback.

7. **Update task**
   - Use `update_task` to record decisions, commit sha, reviewer result, and validation.
   - Append work log entries for each iteration.
