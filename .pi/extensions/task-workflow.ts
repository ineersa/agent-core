// @ts-ignore
import type { ExtensionAPI, ExtensionCommandContext, ExtensionContext } from "@earendil-works/pi-coding-agent";
// @ts-ignore
import { withFileMutationQueue } from "@earendil-works/pi-coding-agent";
import { StringEnum } from "@earendil-works/pi-ai";
import { Type } from "typebox";
import { existsSync } from "node:fs";
import { cp, mkdir, readdir, readFile, rename, writeFile } from "node:fs/promises";
import { basename, dirname, join, relative, resolve } from "node:path";
const TASK_ROOT = "tasks";
const STATUSES = ["TODO", "IN-PROGRESS", "CODE-REVIEW", "DONE"] as const;
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
	prUrl?: string;
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
	cleanupWorktree: Type.Optional(Type.Boolean({ description: "After successful merge, remove the worktree. Default true." })),
	deleteBranch: Type.Optional(Type.Boolean({ description: "After successful merge, delete the task branch. Default false." })),
	requireCleanMain: Type.Optional(Type.Boolean({ description: "Require the integration checkout to be clean before merge. Default true." })),
	cleanupStaleIndexEntries: Type.Optional(Type.Boolean({ description: "Before DONE merge, reset stale staged-add/deleted worktree entries (AD) in the integration checkout. Default false." })),
	prTitle: Type.Optional(Type.String({ description: "Title for GitHub PR when moving to CODE-REVIEW. Defaults to task title." })),
	prBody: Type.Optional(Type.String({ description: "Body for GitHub PR when moving to CODE-REVIEW." })),
	prBaseBranch: Type.Optional(Type.String({ description: "Base branch for PR. Defaults to repository default branch." })),
	pushOnly: Type.Optional(Type.Boolean({ description: "Push branch but skip PR creation. Default false." })),
	castorCheckTimeoutSeconds: Type.Optional(Type.Number({ description: "Timeout in seconds for the deterministic castor check gate during CODE-REVIEW transition. Default 480.", minimum: 60, maximum: 1200 })),
	skipCastorCheck: Type.Optional(Type.Boolean({ description: "Skip the automatic deterministic castor check gate during CODE-REVIEW transition. Default false." })),
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

function normalizeStatus(value: string): TaskStatus {
	const upper = value.toUpperCase();
	if (upper === "TODO") return "TODO";
	if (upper === "IN_PROGRESS" || upper === "INPROGRESS" || upper === "IN-PROGRESS") return "IN-PROGRESS";
	if (upper === "CODE_REVIEW" || upper === "CODEREVIEW" || upper === "CODE-REVIEW") return "CODE-REVIEW";
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

async function run(pi: ExtensionAPI, command: string, args: string[], cwd: string, signal?: AbortSignal, timeoutMs?: number): Promise<ExecResult> {
	const result = await pi.exec(command, args, { cwd, signal, timeout: timeoutMs ?? 120_000 });
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

// ── Task file commit helpers ─────────────────────────────────────────────────

/*
 * Validate that a path is under tasks/ and return the repo-relative path.
 * Throws if the path is outside the allowed tasks/ tree.
 */
function taskRelPath(root: string, absPath: string): string {
	const relPath = rel(root, absPath);
	if (!relPath.startsWith("tasks/")) {
		throw new Error(`Task commit path must be under tasks/, got: ${relPath}`);
	}
	return relPath;
}

/*
 * Check whether the current branch has an upstream configured.
 */
async function hasUpstream(pi: ExtensionAPI, root: string, signal?: AbortSignal): Promise<boolean> {
	const result = await git(pi, root, ["rev-parse", "--abbrev-ref", "--symbolic-full-name", "@{u}"], signal);
	return result.code === 0;
}

/*
 * Commit exact task file changes and optional push.
 *
 * Stages ONLY the given paths (validated under tasks/), verifies no other
 * staged changes exist, commits if there is a diff, and pushes if the
 * current branch has an upstream configured and requirePush is true.
 * If requirePush is false, push is best-effort (recorded as note on failure).
 *
 * Returns notes suitable for appending to a task-workflow log.
 */
async function commitTaskFileChanges(
	pi: ExtensionAPI,
	root: string,
	paths: string[],
	message: string,
	signal?: AbortSignal,
	requirePush: boolean = true,
): Promise<string[]> {
	const notes: string[] = [];

	// No paths means nothing to commit
	if (paths.length === 0) {
		return notes;
	}

	// Validate and deduplicate paths
	const taskPaths = [...new Set(paths.map((p) => taskRelPath(root, p)))];

	// Refuse if other changes are already staged
	const stagedResult = await git(pi, root, ["diff", "--cached", "--name-only"], signal);
	const existingStaged = stagedResult.stdout.trim().split("\n").filter(Boolean);
	if (existingStaged.length > 0) {
		throw new Error(
			`Unrelated changes are already staged. Commit or reset them before moving tasks.\n` +
			`Staged files: ${existingStaged.join(", ")}`,
		);
	}

	// Stage exact task paths
	await gitOk(pi, root, ["add", "--", ...taskPaths], signal);

	// Verify staged diff only contains allowed paths
	const stagedDiff = await gitOk(pi, root, ["diff", "--cached", "--name-only"], signal);
	const stagedOnly = stagedDiff.stdout.trim().split("\n").filter(Boolean);
	const extraFiles = stagedOnly.filter((f) => !taskPaths.includes(f));
	if (extraFiles.length > 0) {
		// Something unexpected got staged — unstage our paths and abort
		await gitOk(pi, root, ["reset", "HEAD", "--", ...taskPaths], signal);
		throw new Error(
			`Stage included unexpected files alongside task paths.\n` +
			`Task paths: ${taskPaths.join(", ")}\n` +
			`Unexpected: ${extraFiles.join(", ")}`,
		);
	}

	// No-op check
	const noDiff = await git(pi, root, ["diff", "--cached", "--quiet"], signal);
	if (noDiff.code === 0) {
		await gitOk(pi, root, ["reset", "HEAD", "--", ...taskPaths], signal);
		return notes;
	}

	// Commit
	const commitResult = await gitOk(pi, root, ["commit", "-m", message], signal);
	const shaMatch = commitResult.stdout.match(/\[[^\]]+ ([a-f0-9]+)\]/);
	const sha = shaMatch ? shaMatch[1] : commitResult.stdout.trim();
	notes.push(`Committed task metadata: ${sha} \u2014 ${message}`);

	// Push if upstream configured
	if (await hasUpstream(pi, root, signal)) {
		const pushResult = await git(pi, root, ["push"], signal);
		if (pushResult.code !== 0) {
			if (requirePush) {
				throw new Error(
					`Task metadata has already been committed locally but push failed.\n` +
					`The integration checkout is now ahead of remote. A manual git push is required before continuing.\n` +
					`Run: git push\n` +
					`${pushResult.stderr || pushResult.stdout}`,
				);
			}
			notes.push(`Warning: push failed — ${(pushResult.stderr || pushResult.stdout).trim()}`);
		} else {
			notes.push(`Pushed task metadata commit to remote.`);
		}
	} else {
		notes.push(`No upstream configured; task metadata committed locally only.`);
	}

	return notes;
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
				prUrl: extractField(text, "PR URL"),
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
		"PR URL:",
		"PR Status:",
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
): Promise<{ branch: string; worktree: string; output: string; veraCopied: boolean; vendorCopied: boolean }> {
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

	let vendorCopied = false;
	const mainVendor = join(root, "vendor");
	const worktreeVendor = join(worktree, "vendor");
	if (existsSync(mainVendor) && !existsSync(worktreeVendor)) {
		try {
			await cp(mainVendor, worktreeVendor, { recursive: true });
			vendorCopied = true;
		} catch {
			// Non-fatal. The worker can run composer install in the worktree if needed.
		}
	}

	let veraCopied = false;
	const mainVera = join(root, ".vera");
	const worktreeVera = join(worktree, ".vera");
	if (existsSync(mainVera) && !existsSync(worktreeVera)) {
		try {
			await cp(mainVera, worktreeVera, { recursive: true });
			veraCopied = true;
		} catch {
			// Non-fatal. Forks can still work with absolute-path reads/edits if the index copy fails.
		}
	}

	return { branch, worktree, output: result.stdout || result.stderr, veraCopied, vendorCopied };
}

function staleAddedDeletedPaths(status: string): string[] {
	return status
		.split("\n")
		.map((line) => line.trimEnd())
		.filter((line) => line.startsWith("AD "))
		.map((line) => line.slice(3).trim())
		.filter(Boolean);
}

function formatDirtyIntegrationCheckoutMessage(branch: string, status: string): string {
	const lines = status.trimEnd().split("\n").filter(Boolean);
	const stalePaths = staleAddedDeletedPaths(status);
	const untracked = lines.filter((line) => line.startsWith("??"));
	const staged = lines.filter((line) => !line.startsWith("??") && line[0] !== " ");
	const unstaged = lines.filter((line) => !line.startsWith("??") && line.length > 1 && line[1] !== " ");

	const sections = [
		`Integration checkout is not clean; refusing to merge ${branch}.`,
		"",
		"Status:",
		status.trimEnd(),
		"",
		"Categorized:",
		`- staged changes: ${staged.length}`,
		`- unstaged changes: ${unstaged.length}`,
		`- untracked files: ${untracked.length}`,
		`- stale staged-add/deleted-worktree entries (AD): ${stalePaths.length}`,
		"",
		"Suggested fixes:",
		"- Commit or stash unrelated integration-checkout changes before moving the task to DONE.",
		"- If the dirty status is only stale AD entries, retry move_task with cleanupStaleIndexEntries=true.",
		"- Use requireCleanMain=false only when you intentionally want to merge into a dirty checkout.",
	];

	return sections.join("\n");
}

async function mergeTaskBranch(
	pi: ExtensionAPI,
	root: string,
	task: TaskInfo,
	options: { cleanupWorktree: boolean; deleteBranch: boolean; requireCleanMain: boolean; cleanupStaleIndexEntries: boolean },
	signal?: AbortSignal,
): Promise<string[]> {
	const branch = task.branch;
	const worktree = task.worktree;
	if (!branch || !worktree) {
		return ["No Branch/Worktree metadata found; moved task without git merge."];
	}

	const notes: string[] = [];
	if (options.requireCleanMain) {
		let mainStatus = await gitOk(pi, root, ["status", "--porcelain"], signal);
		if (mainStatus.stdout.trim() !== "" && options.cleanupStaleIndexEntries) {
			const stalePaths = staleAddedDeletedPaths(mainStatus.stdout);
			if (stalePaths.length > 0) {
				await gitOk(pi, root, ["reset", "HEAD", "--", ...stalePaths], signal);
				notes.push(`Reset stale staged entries: ${stalePaths.join(", ")}.`);
				mainStatus = await gitOk(pi, root, ["status", "--porcelain"], signal);
			}
		}
		if (mainStatus.stdout.trim() !== "") {
			throw new Error(formatDirtyIntegrationCheckoutMessage(branch, mainStatus.stdout));
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

	notes.push(...[`Merged ${branch} into integration checkout.`, (merge.stdout || merge.stderr).trim()].filter(Boolean));

	if (options.cleanupWorktree) {
		const remove = await git(pi, root, ["worktree", "remove", worktree], signal);
		notes.push(remove.code === 0 ? `Removed worktree ${worktree}.` : `Worktree cleanup failed: ${remove.stderr || remove.stdout}`);
	}
	if (options.deleteBranch) {
		const del = await git(pi, root, ["branch", "-d", branch], signal);
		notes.push(del.code === 0 ? `Deleted branch ${branch}.` : `Branch deletion failed: ${del.stderr || del.stdout}`);
	}

	// Sync local main with remote (user merges PRs via GitHub UI)
	const pull = await git(pi, root, ["pull"], signal);
	if (pull.code === 0) {
		notes.push(`Pulled integration checkout: ${(pull.stdout || pull.stderr).trim()}.`);
	} else {
		notes.push(`Pull warning: ${(pull.stderr || pull.stdout).trim()}`);
	}

	return notes;
}

// ── GitHub / PR helpers ──────────────────────────────────────────────────────

/*
 * Push the task branch to the remote and set upstream tracking.
 * Throws a clear error if no remote is configured.
 */
async function pushTaskBranch(
	pi: ExtensionAPI,
	root: string,
	branch: string,
	signal?: AbortSignal,
): Promise<string> {
	// Verify remote exists
	const remoteResult = await run(pi, "git", ["remote", "get-url", "origin"], root, signal);
	if (remoteResult.code !== 0) {
		throw new Error("No git remote 'origin' configured. Push requires a remote repository.\n\nSet one with:\n  git remote add origin <url>");
	}

	// Push with upstream tracking
	const pushResult = await gitOk(pi, root, ["push", "-u", "origin", branch], signal);
	return pushResult.stdout || pushResult.stderr || `Pushed ${branch} to origin.`;
}

/*
 * Check whether the `gh` CLI is installed and authenticated.
 */
async function ghAvailable(pi: ExtensionAPI, root: string, signal?: AbortSignal): Promise<{ available: boolean; reason?: string }> {
	const authResult = await run(pi, "gh", ["auth", "status"], root, signal);
	if (authResult.code !== 0) {
		const err = authResult.stderr || authResult.stdout;
		if (err.includes("not found") || authResult.code === 127) {
			return { available: false, reason: "GitHub CLI (gh) is not installed. Install it from https://cli.github.com/" };
		}
		return { available: false, reason: `gh is not authenticated: ${err.trim()}` };
	}
	return { available: true };
}

/*
 * Look for an existing PR whose head is the task branch.
 * Returns the PR URL if found, or null.
 */
async function findExistingPr(
	pi: ExtensionAPI,
	root: string,
	branch: string,
	signal?: AbortSignal,
): Promise<string | null> {
	const result = await run(pi, "gh", ["pr", "list", "--head", branch, "--json", "url", "--jq", ".[0].url", "--state", "open"], root, signal);
	if (result.code !== 0) return null;
	const url = result.stdout.trim();
	return url || null;
}

/*
 * Create a GitHub PR for the task branch, returning the PR URL.
 */
async function createPr(
	pi: ExtensionAPI,
	root: string,
	branch: string,
	title: string,
	body: string,
	baseBranch?: string,
	signal?: AbortSignal,
): Promise<string> {
	const args = ["pr", "create", "--head", branch, "--title", title];
	if (body.trim()) args.push("--body", body);
	if (baseBranch) args.push("--base", baseBranch);

	const result = await run(pi, "gh", args, root, signal);
	if (result.code !== 0) {
		const err = result.stderr || result.stdout;
		// Check for common failure patterns
		if (err.includes("already exists") || err.includes("pull request already exists")) {
			const existing = await findExistingPr(pi, root, branch, signal);
			if (existing) return existing;
		}
		throw new Error(`gh pr create failed:\n${err.trim()}`);
	}
	const url = result.stdout.trim();
	if (!url) throw new Error("gh pr create succeeded but produced no output URL.");
	return url;
}

function taskListText(tasks: TaskInfo[], root: string): string {
	if (tasks.length === 0) return "No tasks.";
	return tasks.map((task) => {
		const extra: string[] = [];
		if (task.branch) extra.push(task.branch);
		if (task.prUrl) extra.push(`PR: ${task.prUrl}`);
		const extras = extra.length > 0 ? ` [${extra.join(" ")}]` : "";
		return `- ${task.status}/${task.file}: ${task.title}${extras} (${rel(root, task.path)})`;
	}).join("\n");
}

function workflowPrompt(): string {
	return `

## Project task workflow

This project uses a repo-local lightweight issue tracker under tasks/TODO, tasks/IN-PROGRESS, tasks/CODE-REVIEW, and tasks/DONE.

- Use task_list to inspect existing tasks before starting planned work.
- Use create_task when the user asks to track new follow-up work.
- Use update_task to update task metadata or append work log entries without moving the task file.
- Use move_task to change task status instead of moving task files manually.
- When claiming a task, call move_task with to="IN-PROGRESS". This requires a clean integration checkout (commit/stash first). It creates a task/<slug> git branch and sibling worktree at ../<repo>-worktrees/<slug>, copies vendor/ and .vera/ into the worktree when they exist, then records metadata in the task file.
- When implementation is complete and committed, the parent/orchestrator/user calls move_task with to="CODE-REVIEW". This automatically runs deterministic castor check (replay-backed, no live LLM) in the worktree, then pushes the branch and creates a GitHub PR via the gh CLI. The PR URL is stored in the task metadata. Run focused Castor validation (castor test, castor deptrac, castor phpstan, castor cs-check) before moving to catch issues early. Pass skipCastorCheck:true to bypass the automatic gate.
- After code review and PR approval, the parent/orchestrator/user calls move_task with to="DONE". It attempts a git merge back into the integration checkout and reports conflicts without moving the task to DONE if the merge fails. After a successful merge, it runs git pull to sync with remote changes from GitHub PR merges.
- move_task with to="DONE" requires a clean integration checkout by default. If it reports stale AD entries from staged additions deleted in the worktree, retry with cleanupStaleIndexEntries=true; do not commit unrelated staged changes just to satisfy the task workflow.
- IDE tools are scoped to the current checkout and may not index sibling worktrees. move_task copies .vera when available so semantic-search can work in the worktree, but prefer absolute-path read/edit/bash operations or open a separate pi session rooted at the worktree when IDE indexes are unavailable.
`;
}


export default function (pi: ExtensionAPI) {
	pi.on("before_agent_start", async (event, ctx) => {
		const prompt = event.systemPrompt + workflowPrompt();
		return { systemPrompt: prompt };
	});

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
			const lockPath = join(root, TASK_ROOT, ".task-workflow.lock");

			return withFileMutationQueue(lockPath, async () => {
				const slug = slugify(params.id || `${today()}-${params.title}`);
				const path = join(root, TASK_ROOT, "TODO", `${slug}.md`);
				if (existsSync(path)) throw new Error(`Task already exists: ${rel(root, path)}`);
				await writeFile(path, renderTask(params.title, params.body, params.acceptance), "utf8");

				const commitNotes = await commitTaskFileChanges(pi, root, [path], `Add task ${slug}`, signal, true);
				return { content: [{ type: "text", text: [`Created ${rel(root, path)}`, ...commitNotes].join("\n") }], details: { path, commitNotes } };
			});
		},
	});

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

				// TODO → IN-PROGRESS: create worktree (requires clean integration checkout)
				if (task.status === "TODO" && to === "IN-PROGRESS") {
					const mainStatus = await gitOk(pi, root, ["status", "--porcelain"], signal);
					if (mainStatus.stdout.trim() !== "") {
						throw new Error(`Integration checkout is not clean; commit or stash changes before claiming a task.\n${mainStatus.stdout}`);
					}
					const worktree = await createWorktreeForTask(pi, root, task, params.worktreeBase, signal);
					text = updateField(text, "Status", "IN-PROGRESS");
					text = updateField(text, "Branch", worktree.branch);
					text = updateField(text, "Worktree", worktree.worktree);
					text = updateField(text, "Started", new Date().toISOString());
					if (params.forkRun) text = updateField(text, "Fork run", params.forkRun);
					notes.push(`Created branch ${worktree.branch}.`, `Created worktree ${worktree.worktree}.`);
					if (worktree.vendorCopied) notes.push(`Copied vendor directory into ${worktree.worktree}.`);
					if (worktree.veraCopied) notes.push(`Copied .vera index into ${worktree.worktree}.`);
				}
				// IN-PROGRESS → CODE-REVIEW: push branch + create PR
				else if (task.status === "IN-PROGRESS" && to === "CODE-REVIEW") {
					const branch = task.branch;
					if (!branch) {
						throw new Error("Task has no Branch metadata. Was it moved to IN-PROGRESS via move_task?");
					}

					// Pre-check: worktree must exist
					const worktree = task.worktree;
					if (!worktree || !existsSync(worktree)) {
						throw new Error(
							`Task worktree is missing or does not exist. Cannot push without a worktree.\n` +
							`Worktree: ${worktree || "(not set)"}\n` +
							`Claim the task with move_task(to="IN-PROGRESS") to create a worktree first.`,
						);
					}

					// Step 1: verify worktree is clean
					const wtStatus = await gitOk(pi, worktree, ["status", "--porcelain"], signal);
					if (wtStatus.stdout.trim() !== "") {
						throw new Error(`Worktree has uncommitted changes; commit them before moving to CODE-REVIEW.\n${worktree}\n${wtStatus.stdout}`);
					}

				// Step 2: run deterministic castor check in worktree
					const skipGate = params.skipCastorCheck === true;
					if (!skipGate) {
						const checkTimeout = params.castorCheckTimeoutSeconds ?? 480;
						notes.push(`Running deterministic castor check in worktree (timeout ${checkTimeout}s)...`);

						const checkStart = Date.now();
						const checkResult = await run(
							pi,
							"timeout",
							["--kill-after=30s", `${checkTimeout}s`, "env", "LLM_MODE=true", "castor", "check"],
							worktree,
							signal,
							(checkTimeout + 30) * 1000, // Wait longer than timeout+kill-after
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

						notes.push(
							`castor check passed (${checkDuration.toFixed(1)}s).`,
						);
					} else {
						notes.push("Skipped castor check gate (skipCastorCheck: true).");
					}

					// Step 3: push branch
					const pushResult = await pushTaskBranch(pi, root, branch, signal);
					notes.push(`Pushed ${branch} to origin.`);
					notes.push(pushResult.trim());

					// Step 4: create/update PR (unless pushOnly)
					if (!params.pushOnly) {
						const ghStatus = await ghAvailable(pi, root, signal);
						if (!ghStatus.available) {
							throw new Error(
								`Branch pushed, but cannot create PR: ${ghStatus.reason}\n\n` +
								`To skip PR creation and move without a PR, pass pushOnly: true.\n` +
								`To create a PR manually: gh pr create --head ${branch}`,
							);
						}

						// Check for existing PR
						const existingPr = await findExistingPr(pi, root, branch, signal);
						if (existingPr) {
							notes.push(`PR already exists: ${existingPr}`);
							text = updateField(text, "PR URL", existingPr);
							text = updateField(text, "PR Status", "open");
						} else {
							const prTitle = params.prTitle || task.title;
							const prBody = params.prBody || `Task: ${task.title}\nBranch: ${branch}\n\nAuto-created by move_task (CODE-REVIEW).`;
							const prUrl = await createPr(pi, root, branch, prTitle, prBody, params.prBaseBranch, signal);
							notes.push(`Created PR: ${prUrl}`);
							text = updateField(text, "PR URL", prUrl);
							text = updateField(text, "PR Status", "open");
						}
					} else {
						notes.push("Skipped PR creation (pushOnly: true).");
					}

					text = updateField(text, "Status", "CODE-REVIEW");
				}
				// CODE-REVIEW (or IN-PROGRESS) → DONE: merge
				else if (to === "DONE") {
					const mergeNotes = await mergeTaskBranch(pi, root, task, {
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
				// Any other transition: just update status
				else {
					text = updateField(text, "Status", to);
				}

				if (params.forkRun) text = updateField(text, "Fork run", params.forkRun);
				if (params.validation?.length) notes.push(`Validation: ${params.validation.join("; ")}`);
				if (params.summary) notes.push(`Summary: ${params.summary}`);
				text = appendLog(text, notes);

				const target = await moveFileWithMetadata(task, to, text, root);

				// Auto-commit task file changes so integration checkout stays clean
				const slug = task.file.replace(/\.md$/, "");
				const commitNotes = await commitTaskFileChanges(pi, root, [task.path, target], `Move task ${slug} to ${to.toLowerCase()}`, signal, true);
				notes.push(...commitNotes);

				return {
					content: [{ type: "text", text: [`Moved task to ${rel(root, target)}.`, ...notes].join("\n") }],
					details: { from: task.status, to, path: target, notes },
				};
			});
		},
	});

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
			const root = await repoRoot(pi, ctx.cwd, signal);
			await ensureTaskDirs(root);
			const lockPath = join(root, TASK_ROOT, ".task-workflow.lock");

			return withFileMutationQueue(lockPath, async () => {
				const task = await findTask(root, params.task, params.from);
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

				// Auto-commit task file changes so integration checkout stays clean
				const slug = task.file.replace(/\.md$/, "");
				const commitNotes = await commitTaskFileChanges(pi, root, [task.path], `Update task ${slug} metadata`, signal, false);
				notes.push(...commitNotes);

				return {
					content: [{ type: "text", text: [`Updated ${rel(root, task.path)}.`, ...notes].join("\n") }],
					details: { path: task.path, notes },
				};
			});
		},
	});

	// --- Slash commands for listing tasks by status ---

	const taskCommand = (status: TaskStatus | undefined, label: string) => ({
		description: `List ${label} tasks`,
		handler: async (_args: string, ctx: ExtensionCommandContext) => {
			try {
				const root = await repoRoot(pi, ctx.cwd);
				const tasks = await listTasks(root, status);
				if (tasks.length === 0) {
					ctx.ui.notify(`No ${label} tasks.`, "info");
					return;
				}
				const text = taskListText(tasks, root);
				ctx.ui.notify(text, "info");
			} catch (err: any) {
				ctx.ui.notify(`Error: ${err.message}`, "error");
			}
		},
	});

	pi.registerCommand("tasks", taskCommand(undefined, "all"));
	pi.registerCommand("tasks-todo", taskCommand("TODO", "TODO"));
	pi.registerCommand("tasks-in-progress", taskCommand("IN-PROGRESS", "IN-PROGRESS"));
	pi.registerCommand("tasks-code-review", taskCommand("CODE-REVIEW", "CODE-REVIEW"));
	pi.registerCommand("tasks-done", taskCommand("DONE", "DONE"));
}
