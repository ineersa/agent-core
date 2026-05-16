// @ts-ignore
import type { ExtensionAPI, ExtensionContext } from "@mariozechner/pi-coding-agent";
// @ts-ignore
import { withFileMutationQueue } from "@mariozechner/pi-coding-agent";
import { StringEnum } from "@mariozechner/pi-ai";
import { Type } from "typebox";
import { existsSync } from "node:fs";
import { mkdir, readdir, readFile, rename, symlink, writeFile } from "node:fs/promises";
import { basename, dirname, join, relative, resolve } from "node:path";

const TASK_ROOT = "tasks";
const STATUSES = ["TODO", "IN-PROGRESS", "DONE"] as const;
type TaskStatus = (typeof STATUSES)[number];

type ExecResult = {
	stdout: string;
	stderr: string;
	code: number;
	killed?: boolean;
};

type TaskInfo = {
	status: TaskStatus;
	file: string;
	path: string;
	title: string;
	branch?: string;
	worktree?: string;
};

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
	cleanupWorktree: Type.Optional(Type.Boolean({ description: "After successful IN-PROGRESS -> DONE merge, remove the worktree. Default true." })),
	deleteBranch: Type.Optional(Type.Boolean({ description: "After successful merge, delete the task branch. Default false." })),
	requireCleanMain: Type.Optional(Type.Boolean({ description: "Require the integration checkout to be clean before merge. Default true." })),
});

function normalizeStatus(value: string): TaskStatus {
	const upper = value.toUpperCase();
	if (upper === "IN_PROGRESS" || upper === "INPROGRESS" || upper === "IN-PROGRESS") return "IN-PROGRESS";
	if (upper === "TODO") return "TODO";
	if (upper === "DONE") return "DONE";
	throw new Error(`Unknown task status: ${value}`);
}

function slugify(input: string): string {
	return input
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, "-")
		.replace(/^-+|-+$/g, "")
		.slice(0, 80) || "task";
}

function today(): string {
	return new Date().toISOString().slice(0, 10);
}

function rel(repoRoot: string, path: string): string {
	return relative(repoRoot, path) || ".";
}

async function run(pi: ExtensionAPI, command: string, args: string[], cwd: string, signal?: AbortSignal): Promise<ExecResult> {
	const result = await pi.exec(command, args, { cwd, signal, timeout: 120_000 });
	return result as ExecResult;
}

async function git(pi: ExtensionAPI, repoRoot: string, args: string[], signal?: AbortSignal): Promise<ExecResult> {
	return run(pi, "git", args, repoRoot, signal);
}

async function gitOk(pi: ExtensionAPI, repoRoot: string, args: string[], signal?: AbortSignal): Promise<ExecResult> {
	const result = await git(pi, repoRoot, args, signal);
	if (result.code !== 0) {
		throw new Error(`git ${args.join(" ")} failed\n${result.stderr || result.stdout}`.trim());
	}
	return result;
}

async function repoRoot(pi: ExtensionAPI, cwd: string, signal?: AbortSignal): Promise<string> {
	const result = await run(pi, "git", ["rev-parse", "--show-toplevel"], cwd, signal);
	if (result.code !== 0) return cwd;
	return result.stdout.trim() || cwd;
}

async function ensureTaskDirs(root: string): Promise<void> {
	for (const status of STATUSES) {
		const dir = join(root, TASK_ROOT, status);
		await mkdir(dir, { recursive: true });
		const keep = join(dir, ".gitkeep");
		if (!existsSync(keep)) await writeFile(keep, "", "utf8");
	}
}

async function listTasks(root: string, status?: TaskStatus): Promise<TaskInfo[]> {
	await ensureTaskDirs(root);
	const statuses = status ? [status] : STATUSES;
	const tasks: TaskInfo[] = [];
	for (const s of statuses) {
		const dir = join(root, TASK_ROOT, s);
		const files = (await readdir(dir)).filter((file) => file.endsWith(".md")).sort();
		for (const file of files) {
			const path = join(dir, file);
			const text = await readFile(path, "utf8");
			tasks.push({
				status: s,
				file,
				path,
				title: extractTitle(text, file),
				branch: extractField(text, "Branch"),
				worktree: extractField(text, "Worktree"),
			});
		}
	}
	return tasks;
}

function extractTitle(text: string, file: string): string {
	const match = text.match(/^#\s+(.+)$/m);
	return match?.[1]?.trim() || file.replace(/\.md$/, "");
}

function extractField(text: string, name: string): string | undefined {
	const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
	const match = text.match(new RegExp(`^${escaped}:\\s*(.+)$`, "mi"));
	return match?.[1]?.trim();
}

async function findTask(root: string, query: string, status?: TaskStatus): Promise<TaskInfo> {
	const normalized = query.replace(/^@/, "").replace(/\.md$/, "");
	const candidates = await listTasks(root, status);
	const matches = candidates.filter((task) => {
		const stem = task.file.replace(/\.md$/, "");
		return task.file === query || stem === normalized || stem.includes(normalized) || task.title.toLowerCase().includes(normalized.toLowerCase());
	});
	if (matches.length === 0) {
		throw new Error(`No task matched "${query}"${status ? ` in ${status}` : ""}.`);
	}
	if (matches.length > 1) {
		throw new Error(`Task query "${query}" is ambiguous:\n${matches.map((t) => `- ${t.status}/${t.file}`).join("\n")}`);
	}
	return matches[0];
}

function defaultWorktreeBase(root: string): string {
	return resolve(dirname(root), `${basename(root)}-worktrees`);
}

function renderTask(title: string, body?: string, acceptance?: string[]): string {
	const lines = [
		`# ${title}`,
		"",
		"## Goal",
		body?.trim() || "TODO: describe the task.",
		"",
		"## Acceptance criteria",
	];
	if (acceptance?.length) {
		for (const item of acceptance) lines.push(`- ${item}`);
	} else {
		lines.push("- TODO: add acceptance criteria.");
	}
	lines.push(
		"",
		"## Workflow metadata",
		"Status: TODO",
		"Branch:",
		"Worktree:",
		"Fork run:",
		"Started:",
		"Completed:",
		"",
		"## Work log",
		"- Created: " + new Date().toISOString(),
		"",
	);
	return lines.join("\n");
}

function updateField(text: string, name: string, value: string): string {
	const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
	const regex = new RegExp(`^${escaped}:.*$`, "mi");
	if (regex.test(text)) return text.replace(regex, `${name}: ${value}`);
	return `${text.trimEnd()}\n${name}: ${value}\n`;
}

function appendLog(text: string, lines: string[]): string {
	return `${text.trimEnd()}\n\n## Task workflow update - ${new Date().toISOString()}\n${lines.map((line) => `- ${line}`).join("\n")}\n`;
}

async function moveFileWithMetadata(task: TaskInfo, to: TaskStatus, text: string, root: string): Promise<string> {
	const target = join(root, TASK_ROOT, to, task.file);
	if (existsSync(target)) {
		throw new Error(`Target task already exists: ${rel(root, target)}`);
	}
	await mkdir(dirname(target), { recursive: true });
	await writeFile(task.path, text, "utf8");
	await rename(task.path, target);
	return target;
}

async function branchExists(pi: ExtensionAPI, root: string, branch: string, signal?: AbortSignal): Promise<boolean> {
	const result = await git(pi, root, ["show-ref", "--verify", "--quiet", `refs/heads/${branch}`], signal);
	return result.code === 0;
}

async function createWorktreeForTask(
	pi: ExtensionAPI,
	root: string,
	task: TaskInfo,
	worktreeBase: string | undefined,
	signal?: AbortSignal,
): Promise<{ branch: string; worktree: string; output: string }> {
	const slug = task.file.replace(/\.md$/, "");
	const branch = `task/${slug}`;
	const base = worktreeBase ? resolve(root, worktreeBase) : defaultWorktreeBase(root);
	const worktree = join(base, slug);
	await mkdir(base, { recursive: true });

	if (existsSync(worktree)) {
		throw new Error(`Worktree path already exists: ${worktree}`);
	}

	const exists = await branchExists(pi, root, branch, signal);
	const args = exists ? ["worktree", "add", worktree, branch] : ["worktree", "add", "-b", branch, worktree, "HEAD"];
	const result = await gitOk(pi, root, args, signal);

	const mainVendor = join(root, "vendor");
	const worktreeVendor = join(worktree, "vendor");
	if (existsSync(mainVendor) && !existsSync(worktreeVendor)) {
		try {
			await symlink(mainVendor, worktreeVendor, "dir");
		} catch {
			// Non-fatal. The worker can run composer install in the worktree if needed.
		}
	}

	return { branch, worktree, output: result.stdout || result.stderr };
}

async function mergeTaskBranch(
	pi: ExtensionAPI,
	root: string,
	task: TaskInfo,
	options: { cleanupWorktree: boolean; deleteBranch: boolean; requireCleanMain: boolean },
	signal?: AbortSignal,
): Promise<string[]> {
	const branch = task.branch;
	const worktree = task.worktree;
	if (!branch || !worktree) {
		return ["No Branch/Worktree metadata found; moved task without git merge."];
	}

	if (options.requireCleanMain) {
		const mainStatus = await gitOk(pi, root, ["status", "--porcelain"], signal);
		if (mainStatus.stdout.trim() !== "") {
			throw new Error(`Integration checkout is not clean; refusing to merge ${branch}. Commit/stash current changes or pass requireCleanMain=false.\n${mainStatus.stdout}`);
		}
	}

	const wtStatus = await gitOk(pi, worktree, ["status", "--porcelain"], signal);
	if (wtStatus.stdout.trim() !== "") {
		throw new Error(`Worktree has uncommitted changes; commit them before moving to DONE.\n${worktree}\n${wtStatus.stdout}`);
	}

	const merge = await git(pi, root, ["merge", "--no-ff", "--no-edit", branch], signal);
	if (merge.code !== 0) {
		const conflicts = await git(pi, root, ["diff", "--name-only", "--diff-filter=U"], signal);
		throw new Error(`Merge of ${branch} failed. Resolve conflicts in integration checkout, then retry move_task.\nConflicts:\n${conflicts.stdout || "(none reported)"}\n\n${merge.stderr || merge.stdout}`);
	}

	const notes = [`Merged ${branch} into integration checkout.`, (merge.stdout || merge.stderr).trim()].filter(Boolean);

	if (options.cleanupWorktree) {
		const remove = await git(pi, root, ["worktree", "remove", worktree], signal);
		notes.push(remove.code === 0 ? `Removed worktree ${worktree}.` : `Worktree cleanup failed: ${remove.stderr || remove.stdout}`);
	}
	if (options.deleteBranch) {
		const del = await git(pi, root, ["branch", "-d", branch], signal);
		notes.push(del.code === 0 ? `Deleted branch ${branch}.` : `Branch deletion failed: ${del.stderr || del.stdout}`);
	}

	return notes;
}

function taskListText(tasks: TaskInfo[], root: string): string {
	if (tasks.length === 0) return "No tasks.";
	return tasks.map((task) => {
		const extra = task.branch ? ` [${task.branch}]` : "";
		return `- ${task.status}/${task.file}: ${task.title}${extra} (${rel(root, task.path)})`;
	}).join("\n");
}

function workflowPrompt(): string {
	return `

## Project task workflow

This project uses a repo-local lightweight issue tracker under tasks/TODO, tasks/IN-PROGRESS, and tasks/DONE.

- Use task_list to inspect existing tasks before starting planned work.
- Use create_task when the user asks to track new follow-up work.
- Use move_task to change task status instead of moving task files manually.
- When claiming a task, call move_task with to="IN-PROGRESS". That creates a task/<slug> git branch and sibling worktree at ../<repo>-worktrees/<slug>, then records metadata in the task file.
- When finishing a worktree task, ensure worktree changes are committed and validation is recorded, then call move_task with to="DONE". It attempts a git merge back into the integration checkout and reports conflicts without moving the task to DONE if the merge fails.
- IDE and semantic-search tools are scoped to the current checkout and may not index sibling worktrees. For worktree implementation, prefer absolute-path read/edit/bash operations, or open a separate pi session rooted at the worktree. Use the main checkout's IDE/semantic tools for discovery only when source matches the worktree branch.
`;
}

export default function (pi: ExtensionAPI) {
	pi.on("before_agent_start", (event) => {
		return { systemPrompt: event.systemPrompt + workflowPrompt() };
	});

	pi.registerTool({
		name: "task_list",
		label: "Task List",
		description: "List repo-local workflow tasks from tasks/TODO, tasks/IN-PROGRESS, and tasks/DONE.",
		promptSnippet: "List project workflow tasks from tasks/TODO, tasks/IN-PROGRESS, and tasks/DONE",
		promptGuidelines: [
			"Use task_list before starting tracked project work to understand TODO and IN-PROGRESS tasks.",
		],
		parameters: ListTasksParams,
		async execute(_toolCallId, params, signal, _onUpdate, ctx: ExtensionContext) {
			const root = await repoRoot(pi, ctx.cwd, signal);
			const status = params.status ? normalizeStatus(params.status) : undefined;
			const tasks = await listTasks(root, status);
			return { content: [{ type: "text", text: taskListText(tasks, root) }], details: { tasks } };
		},
	});

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
			const root = await repoRoot(pi, ctx.cwd, signal);
			await ensureTaskDirs(root);
			const slug = slugify(params.id || `${today()}-${params.title}`);
			const path = join(root, TASK_ROOT, "TODO", `${slug}.md`);
			if (existsSync(path)) throw new Error(`Task already exists: ${rel(root, path)}`);
			await writeFile(path, renderTask(params.title, params.body, params.acceptance), "utf8");
			return { content: [{ type: "text", text: `Created ${rel(root, path)}` }], details: { path } };
		},
	});

	pi.registerTool({
		name: "move_task",
		label: "Move Task",
		description: "Move a task between TODO, IN-PROGRESS, and DONE. TODO→IN-PROGRESS creates a worktree; IN-PROGRESS→DONE merges the task branch.",
		promptSnippet: "Move tracked project tasks between statuses; creates worktrees and merges completed task branches",
		promptGuidelines: [
			"Use move_task instead of manual mv/git worktree commands for tracked task workflow transitions.",
			"Use move_task with to=\"IN-PROGRESS\" before launching a worker/fork for a tracked task.",
			"Use move_task with to=\"DONE\" only after the task branch is committed and validation has passed; move_task reports merge conflicts and leaves the task in IN-PROGRESS on failure.",
		],
		parameters: MoveTaskParams,
		async execute(_toolCallId, params, signal, _onUpdate, ctx: ExtensionContext) {
			const root = await repoRoot(pi, ctx.cwd, signal);
			await ensureTaskDirs(root);
			const lockPath = join(root, TASK_ROOT, ".task-workflow.lock");

			return withFileMutationQueue(lockPath, async () => {
				const to = normalizeStatus(params.to);
				const from = params.from ? normalizeStatus(params.from) : undefined;
				const task = await findTask(root, params.task, from);
				if (task.status === to) {
					return { content: [{ type: "text", text: `Task already in ${to}: ${task.status}/${task.file}` }], details: { task } };
				}

				let text = await readFile(task.path, "utf8");
				let notes: string[] = [`Moved ${task.status} → ${to}.`];

				if (task.status === "TODO" && to === "IN-PROGRESS") {
					const worktree = await createWorktreeForTask(pi, root, task, params.worktreeBase, signal);
					text = updateField(text, "Status", "IN-PROGRESS");
					text = updateField(text, "Branch", worktree.branch);
					text = updateField(text, "Worktree", worktree.worktree);
					text = updateField(text, "Started", new Date().toISOString());
					if (params.forkRun) text = updateField(text, "Fork run", params.forkRun);
					notes.push(`Created branch ${worktree.branch}.`, `Created worktree ${worktree.worktree}.`);
				} else if (task.status === "IN-PROGRESS" && to === "DONE") {
					const mergeNotes = await mergeTaskBranch(pi, root, task, {
						cleanupWorktree: params.cleanupWorktree ?? true,
						deleteBranch: params.deleteBranch ?? false,
						requireCleanMain: params.requireCleanMain ?? true,
					}, signal);
					text = updateField(text, "Status", "DONE");
					text = updateField(text, "Completed", new Date().toISOString());
					notes = notes.concat(mergeNotes);
				} else {
					text = updateField(text, "Status", to);
				}

				if (params.forkRun) text = updateField(text, "Fork run", params.forkRun);
				if (params.validation?.length) notes.push(`Validation: ${params.validation.join("; ")}`);
				if (params.summary) notes.push(`Summary: ${params.summary}`);
				text = appendLog(text, notes);

				const target = await moveFileWithMetadata(task, to, text, root);
				return {
					content: [{ type: "text", text: [`Moved task to ${rel(root, target)}.`, ...notes].join("\n") }],
					details: { from: task.status, to, path: target, notes },
				};
			});
		},
	});
}
