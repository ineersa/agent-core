// Git and shell execution helpers

// @ts-ignore
import type { ExtensionAPI } from "@earendil-works/pi-coding-agent";
import type { ExecResult } from "./types";

export async function run(
	pi: ExtensionAPI,
	command: string,
	args: string[],
	cwd: string,
	signal?: AbortSignal,
	timeoutMs?: number,
): Promise<ExecResult> {
	const result = await pi.exec(command, args, { cwd, signal, timeout: timeoutMs ?? 120_000 });
	return result as ExecResult;
}

export async function git(
	pi: ExtensionAPI,
	repoRoot: string,
	args: string[],
	signal?: AbortSignal,
): Promise<ExecResult> {
	return run(pi, "git", args, repoRoot, signal);
}

export async function gitOk(
	pi: ExtensionAPI,
	repoRoot: string,
	args: string[],
	signal?: AbortSignal,
): Promise<ExecResult> {
	const result = await git(pi, repoRoot, args, signal);
	if (result.code !== 0) {
		throw new Error(`git ${args.join(" ")} failed\n${result.stderr || result.stdout}`.trim());
	}
	return result;
}

/**
 * Resolve the git repo root for a given working directory.
 */
export async function repoRoot(
	pi: ExtensionAPI,
	cwd: string,
	signal?: AbortSignal,
): Promise<string> {
	const result = await run(pi, "git", ["rev-parse", "--show-toplevel"], cwd, signal);
	if (result.code !== 0) return cwd;
	return result.stdout.trim() || cwd;
}

/**
 * Check whether a branch exists locally.
 */
export async function branchExists(
	pi: ExtensionAPI,
	root: string,
	branch: string,
	signal?: AbortSignal,
): Promise<boolean> {
	const result = await git(pi, root, ["show-ref", "--verify", "--quiet", `refs/heads/${branch}`], signal);
	return result.code === 0;
}

/**
 * Check whether the current branch has an upstream configured.
 */
export async function hasUpstream(
	pi: ExtensionAPI,
	root: string,
	signal?: AbortSignal,
): Promise<boolean> {
	const result = await git(pi, root, ["rev-parse", "--abbrev-ref", "--symbolic-full-name", "@{u}"], signal);
	return result.code === 0;
}
