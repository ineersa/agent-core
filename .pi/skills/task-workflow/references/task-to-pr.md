# task-to-pr: review and create the PR

Main must read this procedure before doing phase work or calling `move_task`. The router alone is not enough.

Read [implementation-ownership.md](implementation-ownership.md) and [specification-fidelity.md](specification-fidelity.md). For TUI changes, also read [tui-proof.md](tui-proof.md).

1. Inspect the worktree with `git status`, `git log`, and `git diff --stat origin/main...HEAD`.
2. Run the reviewer subagent in the worktree. Record its role, artifact or run ID, target revision, and scope with `update_task`. Require a specification-fidelity review.
3. If the reviewer requests changes, apply the ownership decision rules to the requested fixes. Record ownership, implement the fixes, run focused validation, and repeat review until approved.
4. Run relevant filtered tests, `castor deptrac`, `castor phpstan`, and `castor cs-check`. Add controller replay or `castor test:tui` only when the required proof layer calls for it. Do not run full `castor test`; the transition runs `castor check`.
5. Run focused `castor test:llm-real` only for provider or LLM-visible changes such as schemas, prompts, streaming, model routing, or provider compatibility.
6. Record the reviewer decision, target revision, validation, and unresolved blockers with `update_task`.
7. Call `move_task(to="CODE-REVIEW")`. It runs deterministic `castor check`, checks that the worktree is clean, pushes the branch, and creates or updates the PR.

## Failure handling

`castor check` does not kill leaked workers. Treat survivors as lifecycle bugs. Diagnose them with `castor clean:cleanup:workers:list`. Use cleanup only as an investigated last resort.

A failed CODE-REVIEW transition reports the failing lane, a bounded error, the QA report directory, and the lane log when one ran. Setup, lock, preflight, and finalizer failures must report the actual bounded error and available QA directory. Do not invent a lane or log. Do not allowlist or quarantine flakes, retry blindly, or raise timeouts. Fix the deterministic cause, document unrelated fixes, request another review, and rerun the gate. Ask the user when the correct fix needs a broader product decision.
