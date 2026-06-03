---
description: Respond to PR review comments with analysis, implementation iteration, and re-review
argument-hint: "<task-or-pr>"
---

Address code review feedback for task or PR: `$ARGUMENTS`

If the argument is empty or still the literal placeholder `<task-or-pr>`, ask the user for the task slug or PR URL/number instead of guessing. Otherwise, address code review feedback for the task or PR identified by `$ARGUMENTS`:

## Orchestrator role

You are an **orchestrator**, not an implementor. Your job is to dispatch work to specialized agents and coordinate their results:

- **Reviewer subagent** — for re-review after fixes are applied.
- **Researcher subagents** — for web searches, external docs referenced in review comments.
- **Fork (tool)** — for ALL implementation fixes: addressing review feedback, fixing blockers, resolving gate failures. You MUST use a fork for any file modification. Never edit files directly.
- **Main agent (you)** — reads PR comments, classifies feedback, prepares fork instructions, verifies output, moves task state.

If you catch yourself about to open an editor, write a file, or run a code change — stop and launch a fork instead.

1. **Read all PR comments and task metadata**
   - Use `gh pr view <number> --comments` or the task's PR URL from task metadata.
   - Read the task file (usually under `tasks/CODE-REVIEW/`) to retrieve worktree
     path, PR URL, and other metadata needed for the iteration.
   - Read every inline review comment — do not guess or summarize from memory.
   - Identify the task slug from the PR branch name (pattern: `task/<slug>`).

2. **Move back to IN-PROGRESS before implementation**
   - If the task is in CODE-REVIEW, call `move_task` with `to="IN-PROGRESS"` before launching implementation work.
   - Use the existing task worktree from metadata. If it is missing, recreate or recover it before implementation.

3. **Classify feedback**
   - Separate blockers (bugs, design flaws, safety issues) from nice-to-have notes.
   - Note which comments intersect with already-identified blockers on the task.
   - If a comment references external docs (e.g. Doctrine release notes, Symfony changelog), read those too.
   - Use the researcher subagent for web searches or web-based research when up-to-date external information is needed.

4. **Prepare exact fork instructions**
   - Write exact implementation instructions covering each actionable comment.
   - Group nearby changes into single edits where possible.
   - Specify exact files, old/new text patterns, validation steps, and limits of authority.
   - Pass those instructions directly to the fork.

5. **Launch a fork**
   - Launch a single fork with `cwd` set to the task worktree (from task metadata).
   - Include the exact implementation instructions.
   - Do NOT implement directly — the fork implements.

6. **Verify fork output**
   - Confirm the fork committed (check git log on worktree).
   - Inspect `git diff --stat HEAD~1` or `git show --stat HEAD` for the fork commit.
   - Run focused Castor validation: `castor test --filter=...`, `castor deptrac`, `castor phpstan`, `castor cs-check`.
   - Verify no unintended changes (only the advertised files changed).

7. **Re-review**
   - Run the reviewer subagent again on the worktree at the new HEAD.
   - If REQUEST CHANGES again, repeat from step 4 with the new feedback.
   - If APPROVED, move the task back to CODE-REVIEW with `move_task`. This reruns the full Castor gate before pushing.

8. **Update task**
   - Use `update_task` to record decisions, commit sha, reviewer result, and validation.
   - Append work log entries for each iteration.
