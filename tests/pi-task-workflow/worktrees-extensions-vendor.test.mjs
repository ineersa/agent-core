import { execFile, execFileSync } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";
import { afterEach, describe, it } from "node:test";
import assert from "node:assert/strict";

import { createWorktreeForTask } from "../../.pi/extensions/task-workflow/worktrees.ts";

const execFileAsync = promisify(execFile);
const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), "../..");

/** @typedef {{ command: string; args: string[]; cwd: string; signal?: AbortSignal; timeout?: number }} RecordedExec */

/** @typedef {{ stdout: string; stderr: string; code: number }} ExecResult */

/** @typedef {{ status: string; file: string; path: string; title: string }} TaskInfo */

/** @typedef {"with-composer" | "missing-dir" | "dir-no-composer"} ExtensionsLayout */

/**
 * @param {string} cwd
 * @param {string[]} args
 */
function runGit(cwd, args) {
	execFileSync("git", args, { cwd, encoding: "utf8" });
}

/**
 * @param {string} root
 * @param {ExtensionsLayout} layout
 */
function initRepo(root, layout) {
	mkdirSync(join(root, ".hatfield"), { recursive: true });
	if (layout === "with-composer" || layout === "dir-no-composer") {
		const ext = join(root, ".hatfield/extensions");
		mkdirSync(ext, { recursive: true });
		if (layout === "with-composer") {
			writeFileSync(join(ext, "composer.json"), '{"name":"test/extensions"}');
		}
	}
	writeFileSync(join(root, "README.md"), "test\n");
	runGit(root, ["init", "-b", "main"]);
	runGit(root, ["config", "user.email", "test@example.com"]);
	runGit(root, ["config", "user.name", "Test"]);
	runGit(root, ["config", "commit.gpgsign", "false"]);
	runGit(root, ["add", "."]);
	runGit(root, ["commit", "-m", "init"]);
}

/**
 * @param {string} slug
 * @param {string} boardPath
 * @returns {TaskInfo}
 */
function makeTask(slug, boardPath) {
	return {
		status: "TODO",
		file: `${slug}.md`,
		path: boardPath,
		title: "Test",
	};
}

/**
 * @param {"success" | "fail" | "throw"} composerBehavior
 * @param {RecordedExec[]} calls
 */
function createPiMock(composerBehavior, calls) {
	return {
		/**
		 * @param {string} command
		 * @param {string[]} args
		 * @param {{ cwd: string; signal?: AbortSignal; timeout?: number }} options
		 * @returns {Promise<ExecResult>}
		 */
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
				} catch (err) {
					const e = /** @type {NodeJS.ErrnoException & { stdout?: Buffer; stderr?: Buffer }} */ (err);
					const stdout = e.stdout?.toString?.() ?? "";
					const stderr = e.stderr?.toString?.() ?? "";
					const code = typeof e.code === "number" ? e.code : 1;
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

/**
 * @returns {string}
 */
function makeTempRoot() {
	mkdirSync(join(projectRoot, "var/tmp"), { recursive: true });
	return mkdtempSync(join(projectRoot, "var/tmp", "pi-tw-"));
}

describe("createWorktreeForTask extensions vendor", () => {
	/** @type {string} */
	let tempRoot = "";

	afterEach(() => {
		if (tempRoot) {
			rmSync(tempRoot, { recursive: true, force: true });
			tempRoot = "";
		}
	});

	it("runs composer install and creates autoload.php on success", async () => {
		tempRoot = makeTempRoot();
		const codeRoot = join(tempRoot, "repo");
		mkdirSync(codeRoot, { recursive: true });
		initRepo(codeRoot, "with-composer");
		const worktreeBase = join(tempRoot, "worktrees");
		const slug = "2026-01-01-ext-vendor";
		/** @type {RecordedExec[]} */
		const calls = [];
		const pi = createPiMock("success", calls);
		const abort = new AbortController();

		const result = await createWorktreeForTask(
			pi,
			codeRoot,
			makeTask(slug, "/tmp/board"),
			worktreeBase,
			abort.signal,
		);

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
		assert.equal(call.signal, abort.signal);
	});

	it("skips composer when extensions tree is not installable", async () => {
		/** @type {Array<{ layout: ExtensionsLayout; slug: string }>} */
		const cases = [
			{ layout: "missing-dir", slug: "2026-01-01-no-ext-dir" },
			{ layout: "dir-no-composer", slug: "2026-01-01-no-composer" },
		];

		for (const { layout, slug } of cases) {
			const caseRoot = makeTempRoot();
			try {
				const codeRoot = join(caseRoot, "repo");
				mkdirSync(codeRoot, { recursive: true });
				initRepo(codeRoot, layout);
				const worktreeBase = join(caseRoot, "worktrees");
				/** @type {RecordedExec[]} */
				const calls = [];
				const pi = createPiMock("success", calls);

				const result = await createWorktreeForTask(pi, codeRoot, makeTask(slug, "/tmp/board"), worktreeBase);

				assert.equal(result.extensionsVendorInstalled, false, layout);
				assert.equal(calls.filter((c) => c.command === "composer").length, 0, layout);
				assert.ok(existsSync(result.worktree), layout);
			} finally {
				rmSync(caseRoot, { recursive: true, force: true });
			}
		}
	});

	it("treats composer failures as non-fatal with diagnostics", async () => {
		/** @type {Array<{ behavior: "fail" | "throw"; slug: string }>} */
		const cases = [
			{ behavior: "fail", slug: "2026-01-01-ext-fail" },
			{ behavior: "throw", slug: "2026-01-01-ext-throw" },
		];

		for (const { behavior, slug } of cases) {
			const caseRoot = makeTempRoot();
			try {
				const codeRoot = join(caseRoot, "repo");
				mkdirSync(codeRoot, { recursive: true });
				initRepo(codeRoot, "with-composer");
				const worktreeBase = join(caseRoot, "worktrees");
				/** @type {RecordedExec[]} */
				const calls = [];
				const pi = createPiMock(behavior, calls);

				const result = await createWorktreeForTask(pi, codeRoot, makeTask(slug, "/tmp/board"), worktreeBase);

				assert.equal(result.extensionsVendorInstalled, false, behavior);
				assert.ok(result.extensionsVendorNote?.includes("composer install"), behavior);
				assert.ok(existsSync(result.worktree), behavior);
			} finally {
				rmSync(caseRoot, { recursive: true, force: true });
			}
		}
	});
});
