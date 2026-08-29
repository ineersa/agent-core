# task-to-pr: Review and Create PR (IN-PROGRESS → CODE-REVIEW)

Read [specification-fidelity.md](specification-fidelity.md). For changes touching TUI behavior, also read [tui-proof.md](tui-proof.md).

1. Inspect the worktree with `git status`, `git log`, and `git diff --stat origin/main...HEAD`.
2. Run the reviewer subagent in the worktree. Record its role, artifact/run ID, target revision, and scope through `update_task`. Require specification-fidelity review. If it requests changes, fix blockers under the chosen ownership and repeat review until approved.
3. Run focused Castor validation: relevant filtered tests, `castor deptrac`, `castor phpstan`, and `castor cs-check`; add controller replay or `castor test:tui` only when that proof layer is required. Do not require full `castor test`, because the transition runs full `castor check`.
   - Add focused `castor test:llm-real` only for provider/LLM-visible changes such as schemas, prompts, streaming, model routing, or provider compatibility.
4. Record reviewer decision, target revision, validation, and unresolved blockers with `update_task`.
5. `move_task(to="CODE-REVIEW")`; it runs deterministic `castor check`, verifies a clean worktree, pushes the branch, and creates the PR.

## Failure handling

`castor check` does not auto-kill leaked workers. Treat survivors as lifecycle bugs; diagnose with `castor clean:cleanup:workers:list` and clean only as an investigated last resort.

A failed CODE-REVIEW transition reports the failing lane, bounded error, QA report directory, and lane log when one ran. For setup, lock, preflight, or finalizer failures, use the real bounded setup error and QA directory—never invent a lane or log. Never allowlist, quarantine, blindly retry, or raise timeouts for flakes; fix the deterministic root cause, document unrelated fixes, re-review, and rerun. Escalate when the proper fix requires a broader product decision.
