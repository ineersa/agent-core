# task-start: Implement (TODO → IN-PROGRESS)

Before implementation, read:

- [implementation-ownership.md](implementation-ownership.md)
- [specification-fidelity.md](specification-fidelity.md)
- For TUI behavior only: [tui-proof.md](tui-proof.md)

1. `move_task(to="IN-PROGRESS")`; this creates the task worktree and branch. Creation copies `vendor/` and `.vera/`, updates parent IDEA exclusions when present, creates minimal worktree-local `.idea` metadata, and opens that exact worktree in JetBrains when available.
2. Scout/research only where proportional; do not repeat context main already owns.
3. Apply the specification-fidelity gate and resolve public-surface ambiguity with the user.
4. Choose main or fork ownership before deep exploration and append the required ownership record with `outcome=assigned`.
5. Implement sequentially under that ownership in the task worktree.
6. Verify output, run focused validation, and append the ownership record with `outcome=completed` or `blocked` and the commit when available.
7. **STOP.** Tell the user implementation is done and that they run `task-to-pr` when ready.

Do not run `castor check`, move to CODE-REVIEW, push, create a PR, or launch a reviewer in this phase.

## Operational notes

- Full `castor check` in an active Hatfield integration checkout is safe after the stale-worker guard, but task branches should run gates in their worktree. This phase still forbids `castor check`.
- `castor check` does not auto-kill leaked QA workers. Treat survivors as lifecycle bugs. Diagnose with `castor clean:cleanup:workers:list`; use cleanup only as an explicit last resort after investigation.
- Worktree lifecycle updates parent IDEA exclusions, creates minimal local IDEA metadata, and opens the exact worktree via MCP. DONE/CANCELLED closes that project before removal. IDE degradation does not fail the transition. Prefer semantic IDE tools scoped to the exact worktree.
- Task status and metadata remain on the external board and are not committed to `agent-core`.
