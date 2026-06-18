// Workflow prompt text injected into agent system prompt
//
// This describes the task workflow for the agent, including the external
// task board path and that task changes no longer commit to the code repo.

export function workflowPrompt(taskRoot?: string): string {
	const boardDesc = taskRoot
		? `Task board at \`${taskRoot}\` (external to code repo).`
		: `Task board is external to the code repo.`;

	return `

## Project task workflow

Tasks live at an **external task board directory** (separate from this code repo) under TODO, IN-PROGRESS, CODE-REVIEW, and DONE subdirectories. ${boardDesc}

Code operations (branches, worktrees, PRs) still run against this code repository.

- Use task_list to inspect existing tasks before starting planned work.
- Use create_task when the user asks to track new follow-up work.
- Use update_task to update task metadata or append work log entries without moving the task file.
- Use move_task to change task status instead of moving task files manually.
- When claiming a task, call move_task with to="IN-PROGRESS". This requires a clean integration checkout (commit/stash first). It creates a task/<slug> git branch and sibling worktree at ../<repo>-worktrees/<slug>, copies vendor/ and .vera/ into the worktree when they exist, and updates the parent worktree IDEA module exclusions when present, then records metadata in the task file.
- When implementation is complete and committed, the parent/orchestrator/user calls move_task with to="CODE-REVIEW". This automatically runs deterministic castor check (replay-backed, no live LLM) in the worktree, then pushes the branch and creates a GitHub PR via the gh CLI. The PR URL is stored in the task metadata. Run focused Castor validation (castor test, castor deptrac, castor phpstan, castor cs-check) before moving to catch issues early.
- After code review and PR approval, the parent/orchestrator/user calls move_task with to="DONE". It attempts a git merge back into the integration checkout and reports conflicts without moving the task to DONE if the merge fails. After a successful merge, it runs git pull to sync with remote changes from GitHub PR merges. Worktree IDEA exclusions are cleaned up during DONE merge when cleanupWorktree is true.
- move_task with to="DONE" requires a clean integration checkout by default. If it reports stale AD entries from staged additions deleted in the worktree, retry with cleanupStaleIndexEntries=true; do not commit unrelated staged changes just to satisfy the task workflow.
- **Task status/metadata moves are NOT committed to the code repository.** Task changes affect the external task board files only. The task board repo is independently versioned — no auto-commits to agent-core git history.
- IDE tools are scoped to the current checkout and may not index sibling worktrees. move_task copies .vera when available so semantic-search can work in the worktree. When the parent worktree has an IDEA module, worktree creation adds exclude-folder entries for the new worktree and DONE cleanup removes them. Prefer absolute-path read/edit/bash operations or open a separate pi session rooted at the worktree when IDE indexes are unavailable.
`;
}
