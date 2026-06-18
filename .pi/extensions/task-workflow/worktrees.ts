// Git worktree creation, .idea copy/path-rewrite, and merge operations
//
// Worktrees are created in the CODE repo (agent-core), not the task board repo.
// This module handles:
// - Creating task branches + worktrees
// - Copying vendor/, .vera/, .idea/ into worktrees
// - Rewriting absolute path references in copied .idea/ files
// - Merging task branches back into the integration checkout

import { existsSync } from "node:fs";
import { cp, mkdir, readFile, writeFile, readdir, stat } from "node:fs/promises";
import { join, resolve, dirname, basename } from "node:path";
// @ts-ignore
import type { ExtensionAPI } from "@earendil-works/pi-coding-agent";
import type { TaskInfo, WorktreeCreateResult } from "./types";
import { gitOk, git, branchExists, run } from "./exec";

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

// ── .idea copy and path rewriting ────────────────────────────────────────────

/**
 * Copy the integration checkout's `.idea/` directory into the worktree and
 * rewrite absolute path references to point at the worktree.
 *
 * This ensures IDE indexing points at the worktree, not the main checkout.
 *
 * Returns true if .idea was copied, false otherwise.
 * Notes appended to the worktree creation result on success.
 */
export async function copyIdeaWithPathRewrite(
	integrationRoot: string,
	worktreeRoot: string,
): Promise<{ copied: boolean; note?: string }> {
	const src = join(integrationRoot, ".idea");
	const dst = join(worktreeRoot, ".idea");
	if (!existsSync(src) || existsSync(dst)) {
		return { copied: false };
	}

	try {
		// Copy recursively
		await cp(src, dst, { recursive: true });

		// Rewrite absolute path references in text files
		let rewriteCount = 0;
		const entries = await readdir(dst, { withFileTypes: true });
		for (const entry of entries) {
			if (!entry.isFile()) continue;
			const filePath = join(dst, entry.name);
			// Skip binary-like files by extension
			const ext = entry.name.split(".").pop()?.toLowerCase() || "";
			const binaryExts = new Set(["iml", "jar", "zip", "png", "jpg", "gif", "ico", "svg"]);
			if (binaryExts.has(ext)) continue;

			try {
				const content = await readFile(filePath, "utf8");
				// Only rewrite if the integration path appears in the content
				if (content.includes(integrationRoot)) {
					const rewritten = content.replaceAll(integrationRoot, worktreeRoot);
					if (rewritten !== content) {
						await writeFile(filePath, rewritten, "utf8");
						rewriteCount++;
					}
				}
			} catch {
				// Skip files that can't be read as text
			}
		}

		const note = rewriteCount > 0
			? `Copied and rewrote ${rewriteCount} .idea file(s) to point at worktree.`
			: `Copied .idea directory (no path rewrites needed).`;
		return { copied: true, note };
	} catch (err: any) {
		// Non-fatal: IDE indexing is a convenience
		return { copied: false, note: `Warning: .idea copy failed: ${err.message}` };
	}
}

// ── Create worktree ─────────────────────────────────────────────────────────
//
// Creates a task/ branch + git worktree, copies vendor/.vera/.idea,
// and rewrites .idea paths to point at the worktree.

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

	// ── Copy .idea/ with path rewriting ──────────────────────────────────────
	const { copied: ideaCopied, note: ideaNote } = await copyIdeaWithPathRewrite(codeRoot, worktree);

	return {
		branch,
		worktree,
		output: result.stdout || result.stderr,
		veraCopied,
		vendorCopied,
		ideaCopied,
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
		const remove = await git(pi, codeRoot, ["worktree", "remove", worktree], signal);
		notes.push(remove.code === 0 ? `Removed worktree ${worktree}.` : `Worktree cleanup failed: ${remove.stderr || remove.stdout}`);
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
