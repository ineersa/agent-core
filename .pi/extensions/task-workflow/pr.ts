// GitHub PR helpers for the task workflow

// @ts-ignore
import type { ExtensionAPI } from "@earendil-works/pi-coding-agent";
import { run } from "./exec";

/**
 * Push the task branch to the remote and set upstream tracking.
 * Throws a clear error if no remote is configured.
 */
export async function pushTaskBranch(
	pi: ExtensionAPI,
	root: string,
	branch: string,
	signal?: AbortSignal,
): Promise<string> {
	// Verify remote exists
	const remoteResult = await run(pi, "git", ["remote", "get-url", "origin"], root, signal);
	if (remoteResult.code !== 0) {
		throw new Error(
			"No git remote 'origin' configured. Push requires a remote repository.\n\nSet one with:\n  git remote add origin <url>",
		);
	}

	// Push with upstream tracking — use exec directly since gitOk
	// already handles errors via the git helper
	const pushResult = await run(pi, "git", ["push", "-u", "origin", branch], root, signal);
	if (pushResult.code !== 0) {
		throw new Error(`git push failed:\n${pushResult.stderr || pushResult.stdout}`);
	}
	return pushResult.stdout || pushResult.stderr || `Pushed ${branch} to origin.`;
}

/**
 * Check whether the `gh` CLI is installed and authenticated.
 */
export async function ghAvailable(
	pi: ExtensionAPI,
	root: string,
	signal?: AbortSignal,
): Promise<{ available: boolean; reason?: string }> {
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

/**
 * Look for an existing PR whose head is the task branch.
 * Returns the PR URL if found, or null.
 */
export async function findExistingPr(
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

/**
 * Create a GitHub PR for the task branch, returning the PR URL.
 */
export async function createPr(
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
