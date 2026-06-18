// Task board filesystem operations
//
// All task CRUD works against a configurable "task board root" directory.
// This root is separate from the code repo root so task state changes
// do not pollute code git history.

import { existsSync } from "node:fs";
import { mkdir, readdir, readFile, rename, writeFile } from "node:fs/promises";
import { join, relative } from "node:path";
import { STATUSES, type TaskStatus, type TaskInfo } from "./types";

// ── Task root resolution ─────────────────────────────────────────────────────

/**
 * Resolve the task board root directory.
 *
 * Resolution order:
 * 1. `PI_TASK_WORKFLOW_ROOT` env var
 * 2. `.pi/settings.json` key `taskWorkflow.taskRoot`
 * 3. Auto-detect sibling `${codeRepoBasename}-tasks` if it exists and has status dirs
 * 4. Legacy fallback: `codeRoot/tasks`
 */
export async function resolveTaskRoot(
	codeRoot: string,
	settings?: Record<string, unknown>,
): Promise<string> {
	// 1. Env var
	const envRoot = process.env.PI_TASK_WORKFLOW_ROOT;
	if (envRoot && isValidTaskRoot(envRoot)) {
		return envRoot;
	}
	if (envRoot) {
		// Env set but invalid; warn by returning anyway (mkdir will create dirs)
		return envRoot;
	}

	// 2. Settings
	if (settings?.taskWorkflow) {
		const tw = settings.taskWorkflow as Record<string, unknown>;
		if (typeof tw.taskRoot === "string" && tw.taskRoot) {
			return tw.taskRoot;
		}
	}

	// 3. Auto-detect sibling
	const parentDir = join(codeRoot, "..");
	const basename = codeRoot.split("/").pop() || "agent-core";
	const sibling = join(parentDir, `${basename}-tasks`);
	if (existsSync(sibling) && isValidTaskRoot(sibling)) {
		return sibling;
	}

	// 4. Legacy fallback
	return join(codeRoot, "tasks");
}

function isValidTaskRoot(dir: string): boolean {
	if (!existsSync(dir)) return false;
	// Check for at least one status subdirectory with .md files
	for (const status of STATUSES) {
		const statusDir = join(dir, status);
		if (existsSync(statusDir)) return true;
	}
	// Root itself might have tasks directly (for new boards)
	return true;
}

// ── Utility ──────────────────────────────────────────────────────────────────

export function rel(root: string, path: string): string {
	return relative(root, path) || ".";
}

export function slugify(input: string): string {
	return input
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, "-")
		.replace(/^-+|-+$/g, "")
		.slice(0, 80) || "task";
}

export function today(): string {
	return new Date().toISOString().slice(0, 10);
}

export function normalizeStatus(value: string): TaskStatus {
	const upper = value.toUpperCase();
	if (upper === "TODO") return "TODO";
	if (upper === "IN_PROGRESS" || upper === "INPROGRESS" || upper === "IN-PROGRESS") return "IN-PROGRESS";
	if (upper === "CODE_REVIEW" || upper === "CODEREVIEW" || upper === "CODE-REVIEW") return "CODE-REVIEW";
	if (upper === "DONE") return "DONE";
	throw new Error(`Unknown task status: ${value}`);
}

// ── Directory management ─────────────────────────────────────────────────────

export async function ensureTaskDirs(root: string): Promise<void> {
	for (const status of STATUSES) {
		const dir = join(root, status);
		await mkdir(dir, { recursive: true });
		const keep = join(dir, ".gitkeep");
		if (!existsSync(keep)) await writeFile(keep, "", "utf8");
	}
	// Also ensure legacy ARCHIVE / CANCELLED if they exist
	for (const extra of ["ARCHIVE", "CANCELLED"]) {
		const dir = join(root, extra);
		if (!existsSync(dir)) continue;
		// Already exists, that's fine
	}
}

// ── List tasks ────────────────────────────────────────────────────────────────

export async function listTasks(root: string, status?: TaskStatus): Promise<TaskInfo[]> {
	await ensureTaskDirs(root);
	const statuses = status ? [status] : STATUSES;
	const tasks: TaskInfo[] = [];
	for (const s of statuses) {
		const dir = join(root, s);
		let files: string[];
		try {
			files = (await readdir(dir)).filter((file) => file.endsWith(".md")).sort();
		} catch {
			// Directory may not exist yet; skip
			continue;
		}
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

// ── Text helpers ────────────────────────────────────────────────────────────

export function extractTitle(text: string, file: string): string {
	const match = text.match(/^#\s+(.+)$/m);
	return match?.[1]?.trim() || file.replace(/\.md$/, "");
}

export function extractField(text: string, name: string): string | undefined {
	const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
	const match = text.match(new RegExp(`^${escaped}:\\s*(.+)$`, "mi"));
	return match?.[1]?.trim();
}

export function updateField(text: string, name: string, value: string): string {
	const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
	const regex = new RegExp(`^${escaped}:.*$`, "mi");
	if (regex.test(text)) return text.replace(regex, `${name}: ${value}`);
	return `${text.trimEnd()}\n${name}: ${value}\n`;
}

export function appendLog(text: string, lines: string[]): string {
	return `${text.trimEnd()}\n\n## Task workflow update - ${new Date().toISOString()}\n${lines.map((line) => `- ${line}`).join("\n")}\n`;
}

/**
 * Render a new task markdown file.
 */
export function renderTask(title: string, body?: string, acceptance?: string[]): string {
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

// ── Find task ────────────────────────────────────────────────────────────────

export async function findTask(root: string, query: string, status?: TaskStatus): Promise<TaskInfo> {
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

// ── Move file with metadata ─────────────────────────────────────────────────

export async function moveFileWithMetadata(
	task: TaskInfo,
	to: TaskStatus,
	text: string,
	taskRoot: string,
): Promise<string> {
	const target = join(taskRoot, to, task.file);
	if (existsSync(target)) {
		throw new Error(`Target task already exists: ${rel(taskRoot, target)}`);
	}
	await mkdir(join(taskRoot, to), { recursive: true });
	await writeFile(task.path, text, "utf8");
	await rename(task.path, target);
	return target;
}

// ── Lock path ────────────────────────────────────────────────────────────────

export function lockPath(taskRoot: string): string {
	return join(taskRoot, ".task-workflow.lock");
}
