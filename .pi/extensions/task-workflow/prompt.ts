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

Tasks use the external board under TODO, IN-PROGRESS, CODE-REVIEW, DONE, ARCHIVE, and CANCELLED. ${boardDesc}

Use task tools for status transitions; task-board metadata never commits to the code repository. Claim with move_task(IN-PROGRESS), submit with move_task(CODE-REVIEW), and merge only after approval with move_task(DONE). Load the task-workflow skill for phase procedures, validation, implementation ownership, review, worktree, and cleanup details.
`;
}
