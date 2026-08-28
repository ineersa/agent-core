# task-done: Merge Approved PR (CODE-REVIEW → DONE)

1. Confirm the PR is approved or merged on GitHub.
2. `move_task(to="DONE")`; it merges the task branch into the integration checkout and runs `git pull`. On conflict, leave the task in CODE-REVIEW and never force.
3. Run `LLM_MODE=true castor check` in the integration checkout.
   - If prerequisites are unavailable, run focused relevant tests, `castor deptrac`, `castor phpstan`, and `castor cs-check`; add controller replay or `castor test:tui` only when required.
4. Record validation through `update_task`.
5. Confirm clean git status and worktree removal.

`castor check` does not auto-kill leaked workers. Treat survivors as lifecycle bugs; diagnose with `castor clean:cleanup:workers:list` and clean only as an investigated last resort.
