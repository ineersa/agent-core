---
description: Merge a reviewed and approved task to DONE
argument-hint: "<task>"
---

Complete reviewed task: `$ARGUMENTS`

If the task argument is empty or still the literal placeholder `<task>`, ask the user for the task slug instead of guessing. Otherwise, complete the reviewed task named by `$ARGUMENTS` by merging the PR and running post-merge validation:

## Orchestrator role

You are an **orchestrator**, not an implementor. Your job is to dispatch work to specialized agents and coordinate their results:

- **Fork (tool)** — for any fix needed during merge resolution or post-merge validation failures. You MUST use a fork for any file modification. Never edit files directly.
- **Main agent (you)** — checks PR state, merges task branches, runs validation, records results, cleans up.

If you catch yourself about to open an editor, write a file, or run a code change — stop and launch a fork instead.

1. **Confirm PR is approved/merged**
   - Check task metadata for PR URL and PR Status.
   - If the PR is not yet merged on GitHub, verify the user has approved merging.
   - If the user merged via the GitHub UI, the integration checkout may be behind; pull main first (`git pull`).

2. **Move to DONE**
   - Call `move_task` with the task slug from `$ARGUMENTS` and `to="DONE"`.
   - This attempts to merge the task branch into the integration checkout.
   - It runs `git pull` after merging to sync with remote.
   - If merge conflicts occur, `move_task` reports them and keeps the task at CODE-REVIEW — do not force.

3. **Post-merge validation**
   - Run `LLM_MODE=true castor check` on the integration checkout (main) after the merge.
   - If prerequisites (tmux, llama.cpp) are unavailable, run the available subset:
     `castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`.
   - If `castor install` is needed because of new dependencies (e.g. Doctrine bundles), run it first.

4. **Record results**
   - Use `update_task` to append validation results and any post-merge notes.
   - If validation reveals new failures, document them and decide whether to open a follow-up task.

5. **Clean up**
   - Ensure task-board commits are pushed to main (so main is not ahead of origin).
   - `move_task` with `to="DONE"` cleans up the worktree by default
     (cleanupWorktree defaults to true). The task branch is kept by default unless
     `deleteBranch: true` was explicitly passed.
   - Check whether the worktree directory still exists; if cleanup missed it,
     delete the surviving worktree directory after verifying it has no needed changes.
   - Confirm `git status` on integration checkout is clean.
