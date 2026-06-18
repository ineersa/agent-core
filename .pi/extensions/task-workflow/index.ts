// Task Workflow Extension — Multi-file refactored version
//
// Provides task_list, create_task, move_task, update_task tools and slash
// commands for repo-local issue tracking using an external task board directory.
//
// Design invariants:
// - Task board root is configurable and lives OUTSIDE the code repo.
// - Task mutations NEVER git-commit to the code repo.
// - Code git operations (branches, worktrees, PRs) still happen in the code repo.

// @ts-ignore
import type { ExtensionAPI, ExtensionCommandContext, ExtensionContext } from "@earendil-works/pi-coding-agent";
// @ts-ignore
import { withFileMutationQueue } from "@earendil-works/pi-coding-agent";
import { StringEnum } from "@earendil-works/pi-ai";
import { Type } from "typebox";
import { existsSync, readFileSync } from "node:fs";
import { readFile, writeFile } from "node:fs/promises";
import { join, relative } from "node:path";

import { STATUSES, type TaskInfo, type TaskStatus } from "./types";
import { repoRoot, gitOk, run } from "./exec";
import {
	resolveTaskRoot,
	ensureTaskDirs,
	listTasks,
	findTask,
	normalizeStatus,
	slugify,
	rel,
	updateField,
	appendLog,
	renderTask,
	moveFileWithMetadata,
	lockPath,
	today,
} from "./task-store";
import { createWorktreeForTask, mergeTaskBranch } from "./worktrees";
import { pushTaskBranch, ghAvailable, findExistingPr, createPr } from "./pr";
import { workflowPrompt } from "./prompt";

// ── Parameter schemas ─────────────────────────────────────────────────────────

const statusParam = StringEnum(STATUSES);

const CreateTaskParams = Type.Object({
	title: Type.String({ description: "Short task title" }),
	body: Type.Optional(Type.String({ description: "Free-form notes/context for the task" })),
	acceptance: Type.Optional(Type.Array(Type.String(), { description: "Acceptance criteria bullets" })),
	id: Type.Optional(Type.String({ description: "Optional filename slug/id. Defaults to date + title slug." })),
});

const ListTasksParams = Type.Object({
	status: Type.Optional(statusParam),
});

const MoveTaskParams = Type.Object({
	task: Type.String({ description: "Task filename, slug, or unique substring" }),
	to: statusParam,
	from: Type.Optional(statusParam),
	forkRun: Type.Optional(Type.String({ description: "Fork/subagent run id to record in the task file" })),
	summary: Type.Optional(Type.String({ description: "Completion or handoff summary appended to the task" })),
	validation: Type.Optional(Type.Array(Type.String(), { description: "Validation commands/results appended to the task" })),
	worktreeBase: Type.Optional(Type.String({ description: "Directory for task worktrees. Defaults to ../<repo>-worktrees" })),
	cleanupWorktree: Type.Optional(Type.Boolean({ description: "After successful merge, remove the worktree. Default true." })),
	deleteBranch: Type.Optional(Type.Boolean({ description: "After successful merge, delete the task branch. Default false." })),
	requireCleanMain: Type.Optional(Type.Boolean({ description: "Require the integration checkout to be clean before merge. Default true." })),
	cleanupStaleIndexEntries: Type.Optional(Type.Boolean({ description: "Before DONE merge, reset stale staged-add/deleted worktree entries (AD) in the integration checkout. Default false." })),
	prTitle: Type.Optional(Type.String({ description: "Title for GitHub PR when moving to CODE-REVIEW. Defaults to task title." })),
	prBody: Type.Optional(Type.String({ description: "Body for GitHub PR when moving to CODE-REVIEW." })),
	prBaseBranch: Type.Optional(Type.String({ description: "Base branch for PR. Defaults to repository default branch." })),
	pushOnly: Type.Optional(Type.Boolean({ description: "Push branch but skip PR creation. Default false." })),
	castorCheckTimeoutSeconds: Type.Optional(Type.Number({ description: "Timeout in seconds for the deterministic castor check gate during CODE-REVIEW transition. Default 480.", minimum: 60, maximum: 1200 })),
});

const UpdateTaskParams = Type.Object({
	task: Type.String({ description: "Task filename, slug, or unique substring" }),
	from: Type.Optional(statusParam),
	forkRun: Type.Optional(Type.String({ description: "Fork/subagent run id to record in the task file" })),
	summary: Type.Optional(Type.String({ description: "Completion or handoff summary appended to the task" })),
	validation: Type.Optional(Type.Array(Type.String(), { description: "Validation commands/results appended to the task" })),
	prUrl: Type.Optional(Type.String({ description: "Set/update the PR URL in task metadata" })),
	prStatus: Type.Optional(Type.String({ description: "Set/update the PR status in task metadata (open, merged, closed)" })),
	workLog: Type.Optional(Type.Array(Type.String(), { description: "Custom work log entries to append" })),
});

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Read the project .pi/settings.json for task root config.
 * Lightweight — just sync JSON parse.
 */
function readSettings(codeRoot: string): Record<string, unknown> {
	try {
		const settingsPath = join(codeRoot, ".pi", "settings.json");
		if (!existsSync(settingsPath)) return {};
		const raw = readFileSync(settingsPath, "utf8");
		return JSON.parse(raw);
	} catch {
		return {};
	}
}

function taskListText(tasks: TaskInfo[], taskRoot: string): string {
	if (tasks.length === 0) return "No tasks.";
	return tasks.map((task) => {
		const extra: string[] = [];
		if (task.branch) extra.push(task.branch);
		if (task.prUrl) extra.push(`PR: ${task.prUrl}`);
		const extras = extra.length > 0 ? ` [${extra.join(" ")}]` : "";
		return `- ${task.status}/${task.file}: ${task.title}${extras} (${rel(taskRoot, task.path)})`;
	}).join("\n");
}

// ── Extension entry ──────────────────────────────────────────────────────────

export default function (pi: ExtensionAPI) {
	// ── Inject workflow prompt ──────────────────────────────────────────────
	pi.on("before_agent_start", async (event, ctx) => {
		const root = await repoRoot(pi, ctx.cwd);
		const settings = readSettings(root);
		const taskRoot = await resolveTaskRoot(root, settings);
		const prompt = event.systemPrompt + workflowPrompt(taskRoot);
		return { systemPrompt: prompt };
	});

	// ── task_list ──────────────────────────────────────────────────────────
	pi.registerTool({
		name: "task_list",
		label: "Task List",
		description: "List repo-local workflow tasks from tasks/TODO, tasks/IN-PROGRESS, tasks/CODE-REVIEW, and tasks/DONE.",
		promptSnippet: "List project workflow tasks from tasks/TODO, tasks/IN-PROGRESS, tasks/CODE-REVIEW, and tasks/DONE",
		promptGuidelines: [
			"Use task_list before starting tracked project work to understand TODO and IN-PROGRESS tasks.",
		],
		parameters: ListTasksParams,
		async execute(_toolCallId, params, signal, _onUpdate, ctx: ExtensionContext) {
			const root = await repoRoot(pi, ctx.cwd, signal);
			const settings = readSettings(root);
			const taskRoot = await resolveTaskRoot(root, settings);
			const status = params.status ? normalizeStatus(params.status) : undefined;
			const tasks = await listTasks(taskRoot, status);
			return { content: [{ type: "text", text: taskListText(tasks, taskRoot) }], details: { tasks } };
		},
	});

	// ── create_task ────────────────────────────────────────────────────────
	pi.registerTool({
		name: "create_task",
		label: "Create Task",
		description: "Create a Markdown task file in tasks/TODO.",
		promptSnippet: "Create a tracked project task in tasks/TODO",
		promptGuidelines: [
			"Use create_task for user-approved follow-up work that should be tracked in the repo task board.",
		],
		parameters: CreateTaskParams,
		async execute(_toolCallId, params, signal, _onUpdate, ctx: ExtensionContext) {
			const codeRoot = await repoRoot(pi, ctx.cwd, signal);
			const settings = readSettings(codeRoot);
			const taskRoot = await resolveTaskRoot(codeRoot, settings);
			await ensureTaskDirs(taskRoot);

			return withFileMutationQueue(lockPath(taskRoot), async () => {
				const slug = slugify(params.id || `${today()}-${params.title}`);
				const path = join(taskRoot, "TODO", `${slug}.md`);
				if (existsSync(path)) throw new Error(`Task already exists: ${rel(taskRoot, path)}`);
				await writeFile(path, renderTask(params.title, params.body, params.acceptance), "utf8");

				// NOTE: No git commit to code repo. Task board is external.
				return {
					content: [{ type: "text", text: `Created ${rel(taskRoot, path)}` }],
					details: { path },
				};
			});
		},
	});

	// ── move_task ───────────────────────────────────────────────────────────
	pi.registerTool({
		name: "move_task",
		label: "Move Task",
		description: "Move a task between TODO, IN-PROGRESS, CODE-REVIEW, and DONE. TODO→IN-PROGRESS creates a worktree; IN-PROGRESS→CODE-REVIEW pushes the branch and creates a GitHub PR; CODE-REVIEW→DONE merges the task branch.",
		promptSnippet: "Move tracked project tasks between statuses; creates worktrees, opens PRs, and merges completed task branches",
		promptGuidelines: [
			"Use move_task instead of manual mv/git worktree commands for tracked task workflow transitions.",
			"Use move_task with to=\"IN-PROGRESS\" before launching a worker/fork for a tracked task.",
			"Use move_task with to=\"CODE-REVIEW\" after the worktree branch is committed and ready for review; this automatically runs deterministic castor check in the worktree, then pushes the branch and creates a PR. Run focused Castor validation (castor test, castor deptrac, castor phpstan, castor cs-check) yourself before moving to catch issues early.",
			"Use move_task with to=\"DONE\" only after PR review is approved and the user/parent decides to merge; move_task reports merge conflicts and leaves the task in CODE-REVIEW on failure.",
		],
		parameters: MoveTaskParams,
		async execute(_toolCallId, params, signal, _onUpdate, ctx: ExtensionContext) {
			const codeRoot = await repoRoot(pi, ctx.cwd, signal);
			const settings = readSettings(codeRoot);
			const taskRoot = await resolveTaskRoot(codeRoot, settings);
			await ensureTaskDirs(taskRoot);

			return withFileMutationQueue(lockPath(taskRoot), async () => {
				const to = normalizeStatus(params.to);
				const from = params.from ? normalizeStatus(params.from) : undefined;
				const task = await findTask(taskRoot, params.task, from);
				if (task.status === to) {
					return { content: [{ type: "text", text: `Task already in ${to}: ${task.status}/${task.file}` }], details: { task } };
				}

				let text = await readFile(task.path, "utf8");
				let notes: string[] = [`Moved ${task.status} → ${to}.`];

				// ── TODO → IN-PROGRESS: create worktree ──────────────────────
				if (task.status === "TODO" && to === "IN-PROGRESS") {
					const mainStatus = await gitOk(pi, codeRoot, ["status", "--porcelain"], signal);
					if (mainStatus.stdout.trim() !== "") {
						throw new Error(`Integration checkout is not clean; commit or stash changes before claiming a task.\n${mainStatus.stdout}`);
					}
					const wtResult = await createWorktreeForTask(pi, codeRoot, task, params.worktreeBase, signal);
					text = updateField(text, "Status", "IN-PROGRESS");
					text = updateField(text, "Branch", wtResult.branch);
					text = updateField(text, "Worktree", wtResult.worktree);
					text = updateField(text, "Started", new Date().toISOString());
					if (params.forkRun) text = updateField(text, "Fork run", params.forkRun);
					notes.push(`Created branch ${wtResult.branch}.`, `Created worktree ${wtResult.worktree}.`);
					if (wtResult.vendorCopied) notes.push(`Copied vendor directory into ${wtResult.worktree}.`);
					if (wtResult.veraCopied) notes.push(`Copied .vera index into ${wtResult.worktree}.`);
					if (wtResult.ideaCopied) notes.push(`Copied .idea with path rewriting into ${wtResult.worktree}.`);
					if (wtResult.ideaNote) notes.push(wtResult.ideaNote);
				}
				// ── IN-PROGRESS → CODE-REVIEW: push + PR ─────────────────────
				else if (task.status === "IN-PROGRESS" && to === "CODE-REVIEW") {
					const branch = task.branch;
					if (!branch) {
						throw new Error("Task has no Branch metadata. Was it moved to IN-PROGRESS via move_task?");
					}

					const worktree = task.worktree;
					if (!worktree || !existsSync(worktree)) {
						throw new Error(
							`Task worktree is missing or does not exist. Cannot push without a worktree.\n` +
							`Worktree: ${worktree || "(not set)"}\n` +
							`Claim the task with move_task(to="IN-PROGRESS") to create a worktree first.`,
						);
					}

					// Verify worktree clean
					const wtStatus = await gitOk(pi, worktree, ["status", "--porcelain"], signal);
					if (wtStatus.stdout.trim() !== "") {
						throw new Error(`Worktree has uncommitted changes; commit them before moving to CODE-REVIEW.\n${worktree}\n${wtStatus.stdout}`);
					}

					// Run deterministic castor check in worktree
					const checkTimeout = params.castorCheckTimeoutSeconds ?? 480;
					notes.push(`Running deterministic castor check in worktree (timeout ${checkTimeout}s)...`);

					const checkStart = Date.now();
					const checkResult = await run(
						pi,
						"timeout",
						["--kill-after=30s", `${checkTimeout}s`, "env", "LLM_MODE=true", "castor", "check"],
						worktree,
						signal,
						(checkTimeout + 45) * 1000,
					);

					const checkDuration = (Date.now() - checkStart) / 1000;
					const checkKilled = checkResult.code === 124 || checkResult.code === 137;

					if (checkResult.code !== 0) {
						const reason = checkKilled
							? `timeout after ${checkTimeout}s`
							: `exit code ${checkResult.code}`;
						const detail = checkResult.stderr || checkResult.stdout || "(no output)";
						throw new Error(
							`Castor check FAILED (${reason}) in the worktree. ` +
							`Fix the failures, re-validate with focused Castor commands, then move to CODE-REVIEW again.\n` +
							`Worktree: ${worktree}\n` +
							`Output:\n${detail.slice(0, 2000)}`,
						);
					}

					notes.push(`castor check passed (${checkDuration.toFixed(1)}s).`);

					// Push branch
					const pushResult = await pushTaskBranch(pi, codeRoot, branch, signal);
					notes.push(`Pushed ${branch} to origin.`);
					notes.push(pushResult.trim());

					// Create/update PR (unless pushOnly)
					if (!params.pushOnly) {
						const ghStatus = await ghAvailable(pi, codeRoot, signal);
						if (!ghStatus.available) {
							throw new Error(
								`Branch pushed, but cannot create PR: ${ghStatus.reason}\n\n` +
								`To skip PR creation and move without a PR, pass pushOnly: true.\n` +
								`To create a PR manually: gh pr create --head ${branch}`,
							);
						}

						const existingPr = await findExistingPr(pi, codeRoot, branch, signal);
						if (existingPr) {
							notes.push(`PR already exists: ${existingPr}`);
							text = updateField(text, "PR URL", existingPr);
							text = updateField(text, "PR Status", "open");
						} else {
							const prTitle = params.prTitle || task.title;
							const prBody = params.prBody || `Task: ${task.title}\nBranch: ${branch}\n\nAuto-created by move_task (CODE-REVIEW).`;
							const prUrl = await createPr(pi, codeRoot, branch, prTitle, prBody, params.prBaseBranch, signal);
							notes.push(`Created PR: ${prUrl}`);
							text = updateField(text, "PR URL", prUrl);
							text = updateField(text, "PR Status", "open");
						}
					} else {
						notes.push("Skipped PR creation (pushOnly: true).");
					}

					text = updateField(text, "Status", "CODE-REVIEW");
				}
				// ── → DONE: merge ───────────────────────────────────────────
				else if (to === "DONE") {
					const mergeNotes = await mergeTaskBranch(pi, codeRoot, task, {
						cleanupWorktree: params.cleanupWorktree ?? true,
						deleteBranch: params.deleteBranch ?? false,
						requireCleanMain: params.requireCleanMain ?? true,
						cleanupStaleIndexEntries: params.cleanupStaleIndexEntries ?? false,
					}, signal);
					text = updateField(text, "Status", "DONE");
					text = updateField(text, "Completed", new Date().toISOString());
					if (task.prUrl) text = updateField(text, "PR Status", "merged");
					notes = notes.concat(mergeNotes);
				}
				// ── Any other transition: just update status ────────────────
				else {
					text = updateField(text, "Status", to);
				}

				if (params.forkRun) text = updateField(text, "Fork run", params.forkRun);
				if (params.validation?.length) notes.push(`Validation: ${params.validation.join("; ")}`);
				if (params.summary) notes.push(`Summary: ${params.summary}`);
				text = appendLog(text, notes);

				const target = await moveFileWithMetadata(task, to, text, taskRoot);

				// NOTE: No git commit to code repo. Task board is external.
				return {
					content: [{ type: "text", text: [`Moved task to ${rel(taskRoot, target)}.`, ...notes].join("\n") }],
					details: { from: task.status, to, path: target, notes },
				};
			});
		},
	});

	// ── update_task ─────────────────────────────────────────────────────────
	pi.registerTool({
		name: "update_task",
		label: "Update Task",
		description: "Update metadata or append work log entries for an existing task without changing its status.",
		promptSnippet: "Update task metadata fields or append work log entries without moving the task between statuses",
		promptGuidelines: [
			"Use update_task instead of editing task files directly when recording fork run IDs, summaries, validation results, PR information, or work log entries.",
			"update_task does not change the task status or move the file. Use move_task for status changes.",
		],
		parameters: UpdateTaskParams,
		async execute(_toolCallId, params, signal, _onUpdate, ctx: ExtensionContext) {
			const codeRoot = await repoRoot(pi, ctx.cwd, signal);
			const settings = readSettings(codeRoot);
			const taskRoot = await resolveTaskRoot(codeRoot, settings);
			await ensureTaskDirs(taskRoot);

			return withFileMutationQueue(lockPath(taskRoot), async () => {
				const task = await findTask(taskRoot, params.task, params.from);
				let text = await readFile(task.path, "utf8");
				const notes: string[] = [];

				if (params.forkRun) {
					text = updateField(text, "Fork run", params.forkRun);
					notes.push(`Recorded fork run: ${params.forkRun}`);
				}
				if (params.prUrl) {
					text = updateField(text, "PR URL", params.prUrl);
					notes.push(`Updated PR URL: ${params.prUrl}`);
				}
				if (params.prStatus) {
					text = updateField(text, "PR Status", params.prStatus);
					notes.push(`Updated PR Status: ${params.prStatus}`);
				}
				if (params.validation?.length) {
					notes.push(`Validation: ${params.validation.join("; ")}`);
				}
				if (params.summary) {
					notes.push(`Summary: ${params.summary}`);
				}
				if (params.workLog?.length) {
					notes.push(...params.workLog);
				}

				if (notes.length === 0) {
					return { content: [{ type: "text", text: "No updates to apply (no fields provided)." }], details: { task } };
				}

				text = appendLog(text, notes);
				await writeFile(task.path, text, "utf8");

				// NOTE: No git commit to code repo. Task board is external.
				return {
					content: [{ type: "text", text: [`Updated ${rel(taskRoot, task.path)}.`, ...notes].join("\n") }],
					details: { path: task.path, notes },
				};
			});
		},
	});

	// ── Slash commands for listing tasks by status ─────────────────────────

	const taskCommand = (status: TaskStatus | undefined, label: string) => ({
		description: `List ${label} tasks`,
		handler: async (_args: string, cmdCtx: ExtensionCommandContext) => {
			try {
				const codeRoot = await repoRoot(pi, cmdCtx.cwd);
				const settings = readSettings(codeRoot);
				const taskRoot = await resolveTaskRoot(codeRoot, settings);
				const tasks = await listTasks(taskRoot, status);
				if (tasks.length === 0) {
					cmdCtx.ui.notify(`No ${label} tasks.`, "info");
					return;
				}
				const text = taskListText(tasks, taskRoot);
				cmdCtx.ui.notify(text, "info");
			} catch (err: any) {
				cmdCtx.ui.notify(`Error: ${err.message}`, "error");
			}
		},
	});

	pi.registerCommand("tasks", taskCommand(undefined, "all"));
	pi.registerCommand("tasks-todo", taskCommand("TODO", "TODO"));
	pi.registerCommand("tasks-in-progress", taskCommand("IN-PROGRESS", "IN-PROGRESS"));
	pi.registerCommand("tasks-code-review", taskCommand("CODE-REVIEW", "CODE-REVIEW"));
	pi.registerCommand("tasks-done", taskCommand("DONE", "DONE"));
}
