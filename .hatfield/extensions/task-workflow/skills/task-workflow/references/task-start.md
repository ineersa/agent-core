# task-start: implement the task

Main must read this procedure before doing phase work or calling `move_task`. The router alone is not enough.

Before implementation, read:

- [implementation-ownership.md](implementation-ownership.md)
- [specification-fidelity.md](specification-fidelity.md)
- [tui-proof.md](tui-proof.md) when the task changes TUI behavior

1. Call `move_task(to="IN-PROGRESS")`. This creates the task worktree and branch, copies `vendor/`, and updates parent IDEA exclusions when present.
2. Perform or refresh the routing pass in the task worktree. Reuse current `task-explain` findings instead of repeating the same research.
3. Apply the specification-fidelity check and ask the user about unresolved behavior or public API decisions.
4. Choose ownership with the decision rules in `implementation-ownership.md`. Main owns by default. Record each assigned slice with the required ownership log.
5. Implement sequentially under the recorded ownership.
6. Verify the output, run focused validation, and record each slice as completed or blocked. Include the commit when available.
7. Stop. Tell the user what was implemented and that `task-to-pr` is the next phase.

Run targeted checks appropriate to the change in this phase. Do not run `castor check`: `move_task(to="CODE-REVIEW")` runs it automatically in the next phase, satisfying the full-validation requirement for the submitted revision. Implementation completion does not mean the full gate has passed.

Do not move to CODE-REVIEW, push, create a PR, or launch a reviewer in this phase.

## Operational notes

- Run targeted task-branch checks in the task worktree.
- `castor check` does not kill leaked QA workers. Treat survivors as lifecycle bugs. Diagnose them with `castor clean:cleanup:workers:list`. Use cleanup only as an investigated last resort.
- Worktree setup updates parent IDEA exclusions, creates minimal local IDEA metadata, and opens the exact worktree through `jetbrains-index_ide_open_project`. DONE or CANCELLED cleanup closes that project before removing the worktree. An IDE integration failure does not fail the status transition. Scope semantic IDE tools to the exact worktree.
- Task status and metadata live on the external board. They do not commit to `agent-core`.
