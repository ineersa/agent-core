# task-done: merge the approved PR

Main must read this procedure before doing phase work or calling `move_task`. The router alone is not enough.

1. Confirm that GitHub shows the PR as approved or merged.
2. Call `move_task(to="DONE")`. It merges the task branch into the integration checkout and runs `git pull`. If a conflict occurs, leave the task in CODE-REVIEW and do not force the merge.
3. Run `LLM_MODE=true castor check` in the integration checkout.
4. If a required prerequisite is unavailable, run relevant focused tests, `castor deptrac`, `castor phpstan`, and `castor cs-check`. Add controller replay or `castor test:tui` only when required.
5. Record validation with `update_task`.
6. Confirm that Git status is clean and the task worktree was removed.

`castor check` does not kill leaked workers. Treat survivors as lifecycle bugs. Diagnose them with `castor clean:cleanup:workers:list`. Use cleanup only as an investigated last resort.
