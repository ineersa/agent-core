# task-done: merge the approved PR

Main must read this procedure before doing phase work or calling `move_task`. The router alone is not enough.

1. Confirm that GitHub shows the PR as approved or merged.
2. Call `move_task(to="DONE")`. It merges the task branch into the integration checkout and runs `git pull`. If a conflict occurs, leave the task in CODE-REVIEW and do not force the merge.
3. Run `LLM_MODE=true castor check` in the integration checkout. This checks the integrated result separately from the pre-merge CODE-REVIEW gate.
4. If the gate fails or a required prerequisite is unavailable, record the failure or blocker with `update_task` and report post-merge validation as incomplete, even though the task has moved to DONE. Relevant focused checks may provide diagnostic evidence, but do not replace the required full gate.
5. Record validation with `update_task`.
6. Confirm that Git status is clean and the task worktree was removed.

`castor check` does not kill leaked workers. Treat survivors as lifecycle bugs. Diagnose them with `castor clean:cleanup:workers:list`. Use cleanup only as an investigated last resort.
