# task-review-iterate: Address PR Feedback

Read [implementation-ownership.md](implementation-ownership.md) and [specification-fidelity.md](specification-fidelity.md). For TUI changes, also read [tui-proof.md](tui-proof.md).

1. Read the PR summary with `gh pr view` and inline comments with `gh api repos/<owner>/<repo>/pulls/<n>/comments`; classify blockers versus suggestions.
2. `move_task(to="IN-PROGRESS")` before implementation.
3. Reapply specification fidelity, choose ownership before deep exploration, and append the assigned ownership record.
4. Implement fixes sequentially, verify output, run focused Castor validation, and append the completed or blocked ownership record.
5. Re-review with a reviewer subagent, recording role, artifact/run ID, target revision, and scope. Include specification fidelity. Repeat from step 3 if changes are requested.
6. When approved, `move_task(to="CODE-REVIEW")` to push and create/update the PR.
7. Record reviewer identity, revision, scope, decision, commit, validation, and unresolved blockers through `update_task`.
