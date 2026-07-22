// Git worktree creation, parent IDEA module exclusion management, and merge operations
//
// Worktrees are created in the CODE repo (agent-core), not the task board repo.
// This module handles:
// - Creating task branches + worktrees
// - Copying vendor/ and .vera/ into worktrees
// - Adding/removing worktree exclusion blocks in the parent worktree IDEA module (when present)
// - Merging task branches back into the integration checkout

import { existsSync } from "node:fs";
import { cp, mkdir, readFile, readdir, writeFile } from "node:fs/promises";
import { join, resolve, dirname, basename } from "node:path";
// @ts-ignore
import type { ExtensionAPI } from "@earendil-works/pi-coding-agent";
import type { TaskInfo, WorktreeCreateResult } from "./types";
import { gitOk, git, branchExists } from "./exec";

// ── Worktree default base ────────────────────────────────────────────────────

export function defaultWorktreeBase(root: string): string {
	return resolve(dirname(root), `${basename(root)}-worktrees`);
}

// ── Stale index helpers ──────────────────────────────────────────────────────

export function staleAddedDeletedPaths(status: string): string[] {
	return status
		.split("\n")
		.map((line) => line.trimEnd())
		.filter((line) => line.startsWith("AD "))
		.map((line) => line.slice(3).trim())
		.filter(Boolean);
}

export function formatDirtyIntegrationCheckoutMessage(branch: string, status: string): string {
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

// ── Parent IDEA module exclusion management ──────────────────────────────────

/**
 * Exclusion entries for worktrees — practical roots derived from agent-core's
 * meaningful excludes. These are the directories that contain generated content,
 * caches, search indexes, or dependencies that IDEA should not index.
 */
const WORKTREE_EXCLUDE_PATHS = [
	".hatfield",
	".vera",
	"var",
	"vendor",
	"apps/coding-agent/var",
	"apps/coding-agent/vendor",
	"packages/agent-core/var",
	"packages/agent-core/vendor",
	"packages/ai-index/vendor",
];

/**
 * Sentinels that bracket the worktree exclusion block in the parent IDEA module.
 * The slug is embedded in the comment so each worktree's block can be
 * identified and removed independently.
 */
const START_MARKER = (slug: string) => `<!-- pi-task-workflow:start ${slug} -->`;
const END_MARKER = (slug: string) => `<!-- pi-task-workflow:end ${slug} -->`;

/**
 * Build the full sentinel block of <excludeFolder> entries for a worktree.
 */
function buildExclusionBlock(slug: string): string {
	const lines = ["", START_MARKER(slug)];
	for (const relPath of WORKTREE_EXCLUDE_PATHS) {
		lines.push(`    <excludeFolder url="file://$MODULE_DIR$/${slug}/${relPath}" />`);
	}
	lines.push(END_MARKER(slug));
	return lines.join("\n");
}

/**
 * Locate the parent worktree IDEA module .iml file.
 *
 * Strategy:
 * 1. Build expected path: <base>/.idea/<baseBasename>.iml
 * 2. Fallback: if exactly one .iml file exists under <base>/.idea, use it.
 *
 * Returns the path or null if none/ambiguous.
 */
async function findParentIdeaModule(worktreeBase: string): Promise<string | null> {
	const ideaDir = join(worktreeBase, ".idea");
	if (!existsSync(ideaDir)) return null;

	const primary = join(ideaDir, `${basename(worktreeBase)}.iml`);
	if (existsSync(primary)) return primary;

	// Fallback: if exactly one .iml, use it
	try {
		const entries = await readdir(ideaDir);
		const imlFiles = entries.filter((e) => e.endsWith(".iml"));
		if (imlFiles.length === 1) return join(ideaDir, imlFiles[0]);
		if (imlFiles.length > 1) {
			return null; // Ambiguous — can't pick
		}
	} catch {
		// Permission error or directory vanished — non-fatal
	}
	return null;
}

/**
 * Add (or replace) an exclusion block for the given worktree slug in the parent IDEA module.
 * Idempotent: if a block already exists for the slug, it is replaced.
 *
 * Returns true if the module was updated, false otherwise.
 */
export async function addWorktreeExclusions(
	slug: string,
	worktreeBase: string,
): Promise<{ updated: boolean; note?: string }> {
	const imlPath = await findParentIdeaModule(worktreeBase);
	if (!imlPath) {
		return { updated: false, note: "Parent IDEA module not found or ambiguous; skipping exclusion update." };
	}

	let content: string;
	try {
		content = await readFile(imlPath, "utf8");
	} catch (err: any) {
		return { updated: false, note: `Failed to read parent IDEA module: ${err.message}` };
	}

	// Check for existing block for this slug
	const startTag = START_MARKER(slug);
	const endTag = END_MARKER(slug);
	const startIdx = content.indexOf(startTag);
	const endIdx = content.indexOf(endTag);

	// Validate sentinel marker integrity
	const hasStart = startIdx !== -1;
	const hasEnd = endIdx !== -1;
	if (hasStart !== hasEnd) {
		return { updated: false, note: `Parent IDEA module has mismatched exclusion markers for ${slug} (${hasStart ? 'start-only' : 'end-only'}); skipping update to avoid corruption.` };
	}
	if (hasStart && endIdx < startIdx) {
		return { updated: false, note: `Parent IDEA module has reversed exclusion markers for ${slug} (end before start); skipping update to avoid corruption.` };
	}

	const newBlock = buildExclusionBlock(slug);

	if (hasStart) {
		// Replace existing block — both markers valid and ordered
		content = content.slice(0, startIdx) + newBlock + content.slice(endIdx + endTag.length);
	} else {
		// Insert new block inside <content ...> element, before </content>
		const contentCloseIdx = content.indexOf("</content>");
		if (contentCloseIdx === -1) {
			return { updated: false, note: "Parent IDEA module has no <content> element; cannot insert exclusions." };
		}
		content = content.slice(0, contentCloseIdx) + newBlock + "\n" + content.slice(contentCloseIdx);
	}

	try {
		await writeFile(imlPath, content, "utf8");
		return { updated: true };
	} catch (err: any) {
		return { updated: false, note: `Failed to write parent IDEA module: ${err.message}` };
	}
}

/**
 * Remove the exclusion block for the given worktree slug from the parent IDEA module.
 * Idempotent: if no block exists, this is a no-op.
 *
 * Returns true if the module was updated, false otherwise.
 */
export async function removeWorktreeExclusions(
	slug: string,
	worktreeBase: string,
): Promise<{ updated: boolean; note?: string }> {
	const imlPath = await findParentIdeaModule(worktreeBase);
	if (!imlPath) {
		return { updated: false, note: "Parent IDEA module not found; skipping exclusion cleanup." };
	}

	let content: string;
	try {
		content = await readFile(imlPath, "utf8");
	} catch (err: any) {
		return { updated: false, note: `Failed to read parent IDEA module for cleanup: ${err.message}` };
	}

	const startTag = START_MARKER(slug);
	const endTag = END_MARKER(slug);
	const startIdx = content.indexOf(startTag);
	const endIdx = content.indexOf(endTag);

	// Validate sentinel marker integrity
	const hasStart = startIdx !== -1;
	const hasEnd = endIdx !== -1;
	if (hasStart !== hasEnd) {
		return { updated: false, note: `Parent IDEA module has mismatched exclusion markers for ${slug} (${hasStart ? 'start-only' : 'end-only'}); skipping cleanup to avoid corruption.` };
	}
	if (hasStart && endIdx < startIdx) {
		return { updated: false, note: `Parent IDEA module has reversed exclusion markers for ${slug} (end before start); skipping cleanup to avoid corruption.` };
	}

	if (!hasStart) {
		return { updated: false }; // No block — idempotent
	}

	// Remove the block, including leading whitespace/newline before the start marker
	// to keep formatting clean
	const beforeStart = content.lastIndexOf("\n", startIdx - 1);
	const removeStart = beforeStart !== -1 && beforeStart > 0 ? beforeStart : startIdx;
	content = content.slice(0, removeStart) + content.slice(endIdx + endTag.length);

	try {
		await writeFile(imlPath, content, "utf8");
		return { updated: true };
	} catch (err: any) {
		return { updated: false, note: `Failed to write parent IDEA module for cleanup: ${err.message}` };
	}
}

// ── Create worktree ─────────────────────────────────────────────────────────
//
// Creates a task/ branch + git worktree, copies vendor/.vera,
// and updates parent worktree IDEA module exclusions.

export async function createWorktreeForTask(
	pi: ExtensionAPI,
	codeRoot: string,
	task: TaskInfo,
	worktreeBase: string | undefined,
	signal?: AbortSignal,
): Promise<WorktreeCreateResult> {
	const slug = task.file.replace(/\.md$/, "");
	const branch = `task/${slug}`;
	const base = worktreeBase ? resolve(codeRoot, worktreeBase) : defaultWorktreeBase(codeRoot);
	const worktree = join(base, slug);
	await mkdir(base, { recursive: true });

	if (existsSync(worktree)) {
		throw new Error(`Worktree path already exists: ${worktree}`);
	}

	const exists = await branchExists(pi, codeRoot, branch, signal);
	const args = exists ? ["worktree", "add", worktree, branch] : ["worktree", "add", "-b", branch, worktree, "HEAD"];
	const result = await gitOk(pi, codeRoot, args, signal);

	// ── Copy vendor/ ──────────────────────────────────────────────────────────
	let vendorCopied = false;
	const mainVendor = join(codeRoot, "vendor");
	const worktreeVendor = join(worktree, "vendor");
	if (existsSync(mainVendor) && !existsSync(worktreeVendor)) {
		try {
			await cp(mainVendor, worktreeVendor, { recursive: true });
			vendorCopied = true;
		} catch {
			// Non-fatal. Worker can run composer install.
		}
	}

	// ── Copy .vera/ ──────────────────────────────────────────────────────────
	let veraCopied = false;
	const mainVera = join(codeRoot, ".vera");
	const worktreeVera = join(worktree, ".vera");
	if (existsSync(mainVera) && !existsSync(worktreeVera)) {
		try {
			await cp(mainVera, worktreeVera, { recursive: true });
			veraCopied = true;
		} catch {
			// Non-fatal. Forks can use absolute-path reads/edits.
		}
	}

	// ── Update parent IDEA worktree exclusions ─────────────────────────────
	const { updated: ideaExclusionsUpdated, note: ideaNote } = await addWorktreeExclusions(slug, base);

	return {
		branch,
		worktree,
		output: result.stdout || result.stderr,
		veraCopied,
		vendorCopied,
		ideaExclusionsUpdated,
		ideaNote,
	};
}

// ── Merge task branch ────────────────────────────────────────────────────────

export async function mergeTaskBranch(
	pi: ExtensionAPI,
	codeRoot: string,
	task: TaskInfo,
	options: {
		cleanupWorktree: boolean;
		deleteBranch: boolean;
		requireCleanMain: boolean;
		cleanupStaleIndexEntries: boolean;
	},
	signal?: AbortSignal,
): Promise<string[]> {
	const branch = task.branch;
	const worktree = task.worktree;
	if (!branch || !worktree) {
		return ["No Branch/Worktree metadata found; moved task without git merge."];
	}

	const notes: string[] = [];
	if (options.requireCleanMain) {
		let mainStatus = await gitOk(pi, codeRoot, ["status", "--porcelain"], signal);
		if (mainStatus.stdout.trim() !== "" && options.cleanupStaleIndexEntries) {
			const stalePaths = staleAddedDeletedPaths(mainStatus.stdout);
			if (stalePaths.length > 0) {
				await gitOk(pi, codeRoot, ["reset", "HEAD", "--", ...stalePaths], signal);
				notes.push(`Reset stale staged entries: ${stalePaths.join(", ")}.`);
				mainStatus = await gitOk(pi, codeRoot, ["status", "--porcelain"], signal);
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

	const merge = await git(pi, codeRoot, ["merge", "--no-ff", "--no-edit", branch], signal);
	if (merge.code !== 0) {
		const conflicts = await git(pi, codeRoot, ["diff", "--name-only", "--diff-filter=U"], signal);
		throw new Error(`Merge of ${branch} failed. Resolve conflicts in integration checkout, then retry move_task.\nConflicts:\n${conflicts.stdout || "(none reported)"}\n\n${merge.stderr || merge.stdout}`);
	}

	notes.push(`Merged ${branch} into integration checkout.`, (merge.stdout || merge.stderr).trim());

	if (options.cleanupWorktree) {
		// DONE cleanup is best-effort: failure notes are recorded but the merge still succeeds.
		const cleanupNotes = await cleanupWorktreeAndIdeaExclusions(pi, codeRoot, task, false, signal);
		notes.push(...cleanupNotes);
	}
	if (options.deleteBranch) {
		const del = await git(pi, codeRoot, ["branch", "-d", branch], signal);
		notes.push(del.code === 0 ? `Deleted branch ${branch}.` : `Branch deletion failed: ${del.stderr || del.stdout}`);
	}

	// Sync local main with remote (user merges PRs via GitHub UI)
	const pull = await git(pi, codeRoot, ["pull"], signal);
	if (pull.code === 0) {
		notes.push(`Pulled integration checkout: ${(pull.stdout || pull.stderr).trim()}.`);
	} else {
		notes.push(`Pull warning: ${(pull.stderr || pull.stdout).trim()}`);
	}

	return notes;
}

// ── Shared worktree + IDEA exclusion cleanup ─────────────────────────────────
//
// Ordering invariant: never strip IDEA exclusions until `git worktree remove`
// succeeds. Never force-delete or recursively delete a dirty worktree.
// Used by DONE merge (best-effort) and CANCELLED (fail-closed).

async function cleanupWorktreeAndIdeaExclusions(
	pi: ExtensionAPI,
	codeRoot: string,
	task: TaskInfo,
	failClosed: boolean,
	signal?: AbortSignal,
): Promise<string[]> {
	const worktree = task.worktree;
	if (!worktree) {
		return [];
	}

	const slug = task.file.replace(/\.md$/, "");
	const base = dirname(worktree);
	const notes: string[] = [];

	// Non-forced remove only. Dirty trees must fail rather than be deleted.
	const remove = await git(pi, codeRoot, ["worktree", "remove", worktree], signal);
	if (remove.code !== 0) {
		const detail = (remove.stderr || remove.stdout || "").trim() || "(no git output)";
		if (failClosed) {
			throw new Error(
				`Safe worktree cleanup failed; leaving task unmoved and IDEA exclusions intact.\n` +
				`Worktree: ${worktree}\n${detail}`,
			);
		}
		notes.push(`Worktree cleanup failed: ${detail}`);
		notes.push(`IDEA exclusions preserved for ${worktree} because worktree removal failed.`);
		return notes;
	}

	notes.push(`Removed worktree ${worktree}.`);
	const { updated: exclusionsRemoved, note: exclusionNote } = await removeWorktreeExclusions(slug, base);
	if (exclusionNote) notes.push(exclusionNote);
	if (exclusionsRemoved) notes.push(`Removed IDEA exclusions for worktree ${worktree}.`);
	return notes;
}

/**
 * Cancel/cleanup path: remove a task worktree without merge/pull/branch deletion.
 *
 * Fail closed: if Worktree metadata points at a directory and safe
 * `git worktree remove` fails, throw without removing IDEA exclusions.
 * Leave the git branch intact. Historical Branch/Worktree metadata is left
 * for the caller to preserve in the task Markdown.
 */
export async function removeTaskWorktreeSafely(
	pi: ExtensionAPI,
	codeRoot: string,
	task: TaskInfo,
	signal?: AbortSignal,
): Promise<string[]> {
	const worktree = task.worktree;
	if (!worktree) {
		return ["No Worktree metadata; cancelled without git worktree cleanup."];
	}

	if (!existsSync(worktree)) {
		// Directory already gone — still try IDEA exclusion cleanup so markers
		// do not linger after an external/manual worktree removal.
		const slug = task.file.replace(/\.md$/, "");
		const base = dirname(worktree);
		const notes = [`Worktree path missing (${worktree}); skipping git worktree remove.`];
		const { updated: exclusionsRemoved, note: exclusionNote } = await removeWorktreeExclusions(slug, base);
		if (exclusionNote) notes.push(exclusionNote);
		if (exclusionsRemoved) notes.push(`Removed IDEA exclusions for worktree ${worktree}.`);
		return notes;
	}

	return cleanupWorktreeAndIdeaExclusions(pi, codeRoot, task, true, signal);
}
