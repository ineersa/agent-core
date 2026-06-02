---
description: Merge a reviewed and approved task to DONE
argument-hint: "<task>"
---

/task-done <task>

Complete a reviewed task by merging the PR and running post-merge validation:

1. **Confirm PR is approved/merged**
   - Check task metadata for PR URL and PR Status.
   - If the PR is not yet merged on GitHub, verify the user has approved merging.
   - If the user merged via the GitHub UI, the integration checkout may be behind; pull main first (`git pull`).

2. **Move to DONE**
   - Call `move_task` with task slug and `to="DONE"`.
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
   - The `move_task` with to="DONE" should have cleaned up the worktree and deleted the branch (depending on cleanupWorktree/deleteBranch settings).
   - Confirm `git status` on integration checkout is clean.
