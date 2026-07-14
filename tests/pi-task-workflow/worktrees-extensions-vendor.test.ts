import { execFile, execFileSync } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";
import { afterEach, describe, it } from "node:test";
import assert from "node:assert/strict";

import worktrees from "../../.pi/extensions/task-workflow/worktrees.ts";
const { createWorktreeForTask } = worktrees;
import type { ExecResult, TaskInfo } from "../../.pi/extensions/task-workflow/types.ts";

const execFileAsync = promisify(execFile);
const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), "../..");

type RecordedExec = {
	command: string;
	args: string[];
	cwd: string;
	signal?: AbortSignal;
	timeout?: number;
};

type ExtensionAPI = {
	exec: (
		command: string,
		args: string[],
		options: { cwd: string; signal?: AbortSignal; timeout?: number },
	) => Promise<ExecResult>;
};

function runGit(cwd: string, args: string[]): void {
	execFileSync("git", args, { cwd, encoding: "utf8" });
}

function initRepoWithExtensions(root: string, withExtensions: boolean): void {
	mkdirSync(join(root, ".hatfield"), { recursive: true });
	if (withExtensions) {
		const ext = join(root, ".hatfield/extensions");
		mkdirSync(ext, { recursive: true });
		writeFileSync(join(ext, "composer.json"), '{"name":"test/extensions"}');
	}
	writeFileSync(join(root, "README.md"), "test\n");
	runGit(root, ["init", "-b", "main"]);
	runGit(root, ["config", "user.email", "test@example.com"]);
	runGit(root, ["config", "user.name", "Test"]);
	runGit(root, ["add", "."]);
	runGit(root, ["commit", "-m", "init"]);
}

function makeTask(slug: string, boardPath: string): TaskInfo {
	return {
		status: "TODO",
		file: `${slug}.md`,
		path: boardPath,
		title: "Test",
	};
}

function createPiMock(
	composerBehavior: "success" | "fail" | "throw",
	calls: RecordedExec[],
): ExtensionAPI {
	return {
		exec: async (command, args, options) => {
			calls.push({
				command,
				args: [...args],
				cwd: options.cwd,
				signal: options.signal,
				timeout: options.timeout,
			});

			if (command === "git") {
				try {
					const { stdout, stderr } = await execFileAsync("git", args, { cwd: options.cwd });
					return { stdout: stdout ?? "", stderr: stderr ?? "", code: 0 };
				} catch (err: any) {
					const stdout = err?.stdout?.toString?.() ?? "";
					const stderr = err?.stderr?.toString?.() ?? "";
					const code = typeof err?.code === "number" ? err.code : 1;
					return { stdout, stderr, code };
				}
			}

			if (command === "composer") {
				if (composerBehavior === "throw") {
					throw new Error("composer spawn failed");
				}
				if (composerBehavior === "fail") {
					return { stdout: "", stderr: "composer install failed", code: 1 };
				}
				const vendorDir = join(options.cwd, ".hatfield/extensions/vendor");
				mkdirSync(vendorDir, { recursive: true });
				writeFileSync(join(vendorDir, "autoload.php"), "<?php\n");
				return { stdout: "installed", stderr: "", code: 0 };
			}

			return { stdout: "", stderr: `unexpected command ${command}`, code: 1 };
		},
	};
}

describe("createWorktreeForTask extensions vendor", () => {
	let tempRoot = "";

	afterEach(() => {
		if (tempRoot) {
			rmSync(tempRoot, { recursive: true, force: true });
			tempRoot = "";
		}
	});

	it("runs composer install and creates autoload.php on success", async () => {
		tempRoot = mkdirSync(join(projectRoot, "var/tmp"), { recursive: true });
	mkdirSync(join(projectRoot, "var/tmp"), { recursive: true });
			tempRoot = mkdtempSync(join(projectRoot, "var/tmp", "pi-tw-"));
		const codeRoot = join(tempRoot, "repo");
		mkdirSync(codeRoot, { recursive: true });
		initRepoWithExtensions(codeRoot, true);
		const worktreeBase = join(tempRoot, "worktrees");
		const slug = "2026-01-01-ext-vendor";
		const calls: RecordedExec[] = [];
		const pi = createPiMock("success", calls);

		const result = await createWorktreeForTask(pi as any, codeRoot, makeTask(slug, "/tmp/board"), worktreeBase);

		assert.equal(result.extensionsVendorInstalled, true);
		assert.equal(result.extensionsVendorNote, undefined);
		const autoload = join(result.worktree, ".hatfield/extensions/vendor/autoload.php");
		assert.equal(existsSync(autoload), true);

		const composerCalls = calls.filter((c) => c.command === "composer");
		assert.equal(composerCalls.length, 1);
		const call = composerCalls[0];
		assert.deepEqual(call.args, ["install", "-d", ".hatfield/extensions", "--no-interaction", "--no-progress"]);
		assert.equal(call.cwd, result.worktree);
		assert.equal(call.timeout, 120_000);
	});

	it("skips composer when .hatfield/extensions is missing", async () => {
		tempRoot = mkdirSync(join(projectRoot, "var/tmp"), { recursive: true });
	mkdirSync(join(projectRoot, "var/tmp"), { recursive: true });
			tempRoot = mkdtempSync(join(projectRoot, "var/tmp", "pi-tw-"));
		const codeRoot = join(tempRoot, "repo");
		mkdirSync(codeRoot, { recursive: true });
		initRepoWithExtensions(codeRoot, false);
		const worktreeBase = join(tempRoot, "worktrees");
		const slug = "2026-01-01-no-ext";
		const calls: RecordedExec[] = [];
		const pi = createPiMock("success", calls);

		const result = await createWorktreeForTask(pi as any, codeRoot, makeTask(slug, "/tmp/board"), worktreeBase);

		assert.equal(result.extensionsVendorInstalled, false);
		assert.equal(calls.filter((c) => c.command === "composer").length, 0);
		assert.ok(existsSync(result.worktree));
	});

	it("treats composer non-zero exit as non-fatal with diagnostic", async () => {
		tempRoot = mkdirSync(join(projectRoot, "var/tmp"), { recursive: true });
	mkdirSync(join(projectRoot, "var/tmp"), { recursive: true });
			tempRoot = mkdtempSync(join(projectRoot, "var/tmp", "pi-tw-"));
		const codeRoot = join(tempRoot, "repo");
		mkdirSync(codeRoot, { recursive: true });
		initRepoWithExtensions(codeRoot, true);
		const worktreeBase = join(tempRoot, "worktrees");
		const slug = "2026-01-01-ext-fail";
		const calls: RecordedExec[] = [];
		const pi = createPiMock("fail", calls);

		const result = await createWorktreeForTask(pi as any, codeRoot, makeTask(slug, "/tmp/board"), worktreeBase);

		assert.equal(result.extensionsVendorInstalled, false);
		assert.ok(result.extensionsVendorNote?.includes("composer install"));
		assert.ok(result.extensionsVendorNote?.includes("failed"));
		assert.ok(existsSync(result.worktree));
	});

	it("treats composer exec throw as non-fatal with diagnostic", async () => {
		tempRoot = mkdirSync(join(projectRoot, "var/tmp"), { recursive: true });
	mkdirSync(join(projectRoot, "var/tmp"), { recursive: true });
			tempRoot = mkdtempSync(join(projectRoot, "var/tmp", "pi-tw-"));
		const codeRoot = join(tempRoot, "repo");
		mkdirSync(codeRoot, { recursive: true });
		initRepoWithExtensions(codeRoot, true);
		const worktreeBase = join(tempRoot, "worktrees");
		const slug = "2026-01-01-ext-throw";
		const calls: RecordedExec[] = [];
		const pi = createPiMock("throw", calls);

		const result = await createWorktreeForTask(pi as any, codeRoot, makeTask(slug, "/tmp/board"), worktreeBase);

		assert.equal(result.extensionsVendorInstalled, false);
		assert.ok(result.extensionsVendorNote?.includes("composer install"));
		assert.ok(existsSync(result.worktree));
	});
});
