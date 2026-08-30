# task-review-iterate: address PR feedback

Main must read this procedure before doing phase work or calling `move_task`. The router alone is not enough.

Read [implementation-ownership.md](implementation-ownership.md) and [specification-fidelity.md](specification-fidelity.md). For TUI changes, also read [tui-proof.md](tui-proof.md).

1. Read the PR summary with `gh pr view` and inline comments with `gh api repos/<owner>/<repo>/pulls/<n>/comments`. Separate blockers from suggestions.
2. Call `move_task(to="IN-PROGRESS")` before changing code.
3. Perform a routing pass for the accepted fixes. Apply the ownership decision rules and record each assigned slice before detailed investigation.
4. Implement fixes sequentially, verify the result, run focused Castor validation, and record each slice as completed or blocked.
5. Run a reviewer subagent against the new revision. Require a specification-fidelity review. Record the role, artifact or run ID, target revision, and scope. If the reviewer requests changes, repeat from step 3.
6. When the reviewer approves, call `move_task(to="CODE-REVIEW")` to push the branch and create or update the PR.
7. Record the reviewer identity, revision, scope, decision, commit, validation, and unresolved blockers with `update_task`.
