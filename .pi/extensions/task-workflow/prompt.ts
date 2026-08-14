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

Tasks live at an **external task board directory** (separate from this code repo) under TODO, IN-PROGRESS, CODE-REVIEW, DONE, ARCHIVE, and CANCELLED subdirectories. ${boardDesc}

Code operations (branches, worktrees, PRs) still run against this code repository.

- Use task_list to inspect existing tasks before starting planned work. CANCELLED and ARCHIVE are omitted by default; pass status=CANCELLED to list cancelled tasks, and include_archive=true or status=ARCHIVE to list archived tasks.
- Use create_task when the user asks to track new follow-up work.
- Use update_task to update task metadata or append work log entries without moving the task file.
- Use move_task to change task status instead of moving task files manually.
- When claiming a task, call move_task with to="IN-PROGRESS". This requires a clean integration checkout (commit/stash first). It creates a task/<slug> git branch and sibling worktree at ../<repo>-worktrees/<slug>, copies vendor/ and .vera/ when they exist, updates parent worktree IDEA exclusions when present, creates minimal worktree-local .idea metadata from the integration primary module, opens that exact worktree in JetBrains via MCP (ide_open_project), then records metadata in the task file. IDE/MCP open failures are recorded as degradation notes and do not fail the transition.
- When implementation is complete and committed, the parent/orchestrator/user calls move_task with to="CODE-REVIEW". This automatically runs deterministic castor check (replay-backed, no live LLM) in the worktree, then pushes the branch and creates a GitHub PR via the gh CLI. The PR URL is stored in the task metadata. Run focused Castor validation (castor test, castor deptrac, castor phpstan, castor cs-check) before moving to catch issues early.
- After code review and PR approval, the parent/orchestrator/user calls move_task with to="DONE". It attempts a git merge back into the integration checkout and reports conflicts without moving the task to DONE if the merge fails. After a successful merge, it runs git pull to sync with remote changes from GitHub PR merges. When cleanupWorktree is true, it closes the exact worktree JetBrains project before removal, then cleans worktree + IDEA exclusions; dirty preflight still fails closed before close.
- Use move_task with to="ARCHIVE" only from DONE; metadata/status update and Markdown move only — no git side effects.
- Use move_task with to="CANCELLED" from any status to abandon a task. Clean worktrees are closed in JetBrains then removed with IDEA exclusions; dirty worktrees fail closed without closing/removing; the git branch is left in place.
- move_task with to="DONE" requires a clean integration checkout by default. If it reports stale AD entries from staged additions deleted in the worktree, retry with cleanupStaleIndexEntries=true; do not commit unrelated staged changes just to satisfy the task workflow.
- **Task status/metadata moves are NOT committed to the code repository.** Task changes affect the external task board files only. The task board repo is independently versioned — no auto-commits to agent-core git history.
- Prefer JetBrains semantic IDE tools (ide_*) for navigation, references/hierarchy, diagnostics, and semantic rename/move. Always target the exact task worktree with project_path; never assume the integration checkout or the aggregate sibling-worktree project. move_task opens the exact worktree after claim; if IDE tools are unavailable, fall back to absolute-path filesystem operations. Parent IDEA exclusions still keep the aggregate worktrees project from indexing generated worktree content.
`;
}
