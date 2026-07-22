// Behavioral harness for the Pi task-workflow extension.
//
// Executes the real production modules under Node 22 type-stripping.
// Invoked from PHPUnit via Symfony Process so Castor `castor test` runs it.

import { existsSync } from "node:fs";
import { mkdir, mkdtemp, rm, writeFile, readFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const extensionRoot = join(here, "../../../../.pi/extensions/task-workflow");

const {
	extractField,
	listTasks,
	normalizeStatus,
	resolveListStatuses,
} = await import(pathToFileURL(join(extensionRoot, "task-store.ts")).href);

const { removeTaskWorktreeSafely } = await import(
	pathToFileURL(join(extensionRoot, "worktrees.ts")).href
);

type ExecResult = {
	stdout: string;
	stderr: string;
	code: number;
	killed?: boolean;
};

type TaskInfo = {
	status: string;
	file: string;
	path: string;
	title: string;
	branch?: string;
	worktree?: string;
	prUrl?: string;
};

type AssertionError = Error & { expected?: unknown; actual?: unknown };

function fail(message: string, expected?: unknown, actual?: unknown): never {
	const err = new Error(message) as AssertionError;
	err.expected = expected;
	err.actual = actual;
	throw err;
}

function assertEqual(actual: unknown, expected: unknown, message: string): void {
	const left = JSON.stringify(actual);
	const right = JSON.stringify(expected);
	if (left !== right) {
		fail(`${message}\nexpected: ${right}\nactual:   ${left}`, expected, actual);
	}
}

function assertTrue(value: unknown, message: string): void {
	if (!value) {
		fail(message, true, value);
	}
}

function assertUndefined(value: unknown, message: string): void {
	if (value !== undefined) {
		fail(message, undefined, value);
	}
}

async function withTempDir<T>(prefix: string, fn: (dir: string) => Promise<T>): Promise<T> {
	const dir = await mkdtemp(join(tmpdir(), `${prefix}-`));
	try {
		return await fn(dir);
	} finally {
		await rm(dir, { recursive: true, force: true });
	}
}

function fakePi(
	execImpl: (
		command: string,
		args: string[],
		options: { cwd: string },
	) => Promise<ExecResult>,
) {
	return {
		exec: execImpl,
	} as any;
}

async function writeTask(
	root: string,
	status: string,
	file: string,
	body: string,
): Promise<string> {
	const dir = join(root, status);
	await mkdir(dir, { recursive: true });
	const path = join(dir, file);
	await writeFile(path, body, "utf8");
	return path;
}

async function testNormalizeStatus(): Promise<void> {
	assertEqual(normalizeStatus("ARCHIVE"), "ARCHIVE", "ARCHIVE normalizes");
	assertEqual(normalizeStatus("archive"), "ARCHIVE", "archive lower-case normalizes");
	assertEqual(normalizeStatus("CANCELLED"), "CANCELLED", "CANCELLED normalizes");
	assertEqual(normalizeStatus("CANCELED"), "CANCELLED", "CANCELED US spelling normalizes");
	assertEqual(normalizeStatus("canceled"), "CANCELLED", "canceled lower-case normalizes");
	try {
		normalizeStatus("NOPE");
		fail("expected unknown status to throw");
	} catch (err: any) {
		assertTrue(String(err.message).includes("Unknown task status"), "unknown status message");
	}
}

async function testResolveListStatuses(): Promise<void> {
	assertEqual(
		resolveListStatuses(undefined, false),
		["TODO", "IN-PROGRESS", "CODE-REVIEW", "DONE", "CANCELLED"],
		"default listing omits ARCHIVE",
	);
	assertEqual(
		resolveListStatuses(undefined, true),
		["TODO", "IN-PROGRESS", "CODE-REVIEW", "DONE", "ARCHIVE", "CANCELLED"],
		"include_archive true lists all six",
	);
	assertEqual(
		resolveListStatuses("TODO", true),
		["TODO", "ARCHIVE"],
		"TODO + include_archive unions ARCHIVE",
	);
	assertEqual(
		resolveListStatuses("ARCHIVE", false),
		["ARCHIVE"],
		"explicit ARCHIVE works without include_archive",
	);
	assertEqual(
		resolveListStatuses("ARCHIVE", true),
		["ARCHIVE"],
		"explicit ARCHIVE + include_archive does not duplicate",
	);
	assertEqual(
		resolveListStatuses("CANCELLED", false),
		["CANCELLED"],
		"explicit CANCELLED only",
	);
}

async function testExtractFieldEmptyLines(): Promise<void> {
	const md = [
		"# Sample",
		"",
		"## Workflow metadata",
		"Status: IN-PROGRESS",
		"Branch: task/sample",
		"Worktree:",
		"Fork run:",
		"PR URL: https://example.com/pr/42",
		"PR Status:",
		"",
	].join("\n");

	assertUndefined(extractField(md, "Worktree"), "empty Worktree must not capture next line");
	assertUndefined(extractField(md, "Fork run"), "empty Fork run must not capture next line");
	assertEqual(extractField(md, "Branch"), "task/sample", "Branch remains non-empty");
	assertEqual(extractField(md, "PR URL"), "https://example.com/pr/42", "PR URL remains non-empty");
	assertUndefined(extractField(md, "PR Status"), "empty PR Status is undefined");

	const crlf = "Branch: task/crlf\r\nWorktree:\r\nFork run: abc\r\nPR URL:\r\n";
	assertUndefined(extractField(crlf, "Worktree"), "CRLF empty Worktree");
	assertEqual(extractField(crlf, "Fork run"), "abc", "CRLF non-empty Fork run");
	assertUndefined(extractField(crlf, "PR URL"), "CRLF empty PR URL");
}

async function testListTasksFilesystem(): Promise<void> {
	await withTempDir("pi-task-list", async (root) => {
		await writeTask(
			root,
			"TODO",
			"todo-one.md",
			"# Todo One\n\n## Workflow metadata\nStatus: TODO\nBranch:\nWorktree:\n",
		);
		await writeTask(
			root,
			"ARCHIVE",
			"archived.md",
			"# Archived\n\n## Workflow metadata\nStatus: ARCHIVE\nBranch: task/archived\nWorktree:\n",
		);
		await writeTask(
			root,
			"CANCELLED",
			"cancelled.md",
			"# Cancelled\n\n## Workflow metadata\nStatus: CANCELLED\nBranch: task/cancelled\nWorktree:\n",
		);

		const defaultList = await listTasks(root);
		assertEqual(
			defaultList.map((t: TaskInfo) => `${t.status}/${t.file}`).sort(),
			["CANCELLED/cancelled.md", "TODO/todo-one.md"],
			"default listTasks omits ARCHIVE and keeps CANCELLED",
		);

		const withArchive = await listTasks(root, undefined, true);
		assertEqual(
			withArchive.map((t: TaskInfo) => `${t.status}/${t.file}`).sort(),
			["ARCHIVE/archived.md", "CANCELLED/cancelled.md", "TODO/todo-one.md"],
			"include_archive lists ARCHIVE too",
		);

		const todoPlusArchive = await listTasks(root, "TODO", true);
		assertEqual(
			todoPlusArchive.map((t: TaskInfo) => `${t.status}/${t.file}`).sort(),
			["ARCHIVE/archived.md", "TODO/todo-one.md"],
			"status TODO + include_archive unions ARCHIVE",
		);

		const onlyArchive = await listTasks(root, "ARCHIVE", false);
		assertEqual(
			onlyArchive.map((t: TaskInfo) => `${t.status}/${t.file}`),
			["ARCHIVE/archived.md"],
			"explicit ARCHIVE lists archive without include_archive",
		);

		// Empty Worktree metadata must not leak the next field into TaskInfo.worktree.
		const emptyWorktreePath = await writeTask(
			root,
			"IN-PROGRESS",
			"empty-wt.md",
			[
				"# Empty Worktree",
				"",
				"## Workflow metadata",
				"Status: IN-PROGRESS",
				"Branch: task/empty-wt",
				"Worktree:",
				"Fork run: fork-abc",
				"PR URL: https://example.com/pr/9",
				"",
			].join("\n"),
		);
		const progressive = await listTasks(root, "IN-PROGRESS");
		const empty = progressive.find((t: TaskInfo) => t.file === "empty-wt.md");
		assertTrue(!!empty, "IN-PROGRESS empty-wt task is listed");
		assertUndefined(empty?.worktree, "listTasks must not treat next metadata line as Worktree");
		assertEqual(empty?.branch, "task/empty-wt", "branch preserved for empty-wt task");
		assertEqual(empty?.prUrl, "https://example.com/pr/9", "prUrl preserved for empty-wt task");
		assertEqual(empty?.path, emptyWorktreePath, "path points at written markdown");
	});
}

async function testRemoveTaskWorktreeSafely(): Promise<void> {
	await withTempDir("pi-task-wt", async (base) => {
		const codeRoot = join(base, "code");
		const worktreeBase = join(base, "worktrees");
		const slug = "sample-task";
		const worktree = join(worktreeBase, slug);
		const ideaDir = join(worktreeBase, ".idea");
		const imlPath = join(ideaDir, "worktrees.iml");

		await mkdir(codeRoot, { recursive: true });
		await mkdir(worktree, { recursive: true });
		await mkdir(ideaDir, { recursive: true });

		const markerStart = `<!-- pi-task-workflow:start ${slug} -->`;
		const markerEnd = `<!-- pi-task-workflow:end ${slug} -->`;
		const iml = [
			'<?xml version="1.0" encoding="UTF-8"?>',
			'<module type="WEB_MODULE" version="4">',
			'  <component name="NewModuleRootManager">',
			'    <content url="file://$MODULE_DIR$">',
			`    ${markerStart}`,
			`    <excludeFolder url="file://$MODULE_DIR$/${slug}/vendor" />`,
			`    ${markerEnd}`,
			"    </content>",
			"  </component>",
			"</module>",
			"",
		].join("\n");
		await writeFile(imlPath, iml, "utf8");

		const task: TaskInfo = {
			status: "IN-PROGRESS",
			file: `${slug}.md`,
			path: join(base, `${slug}.md`),
			title: "Sample",
			branch: `task/${slug}`,
			worktree,
		};

		// Success path: non-forced worktree remove, then IDEA marker cleanup.
		const calls: Array<{ command: string; args: string[]; cwd: string }> = [];
		const successPi = fakePi(async (command, args, options) => {
			calls.push({ command, args, cwd: options.cwd });
			if (command === "git" && args[0] === "worktree" && args[1] === "remove") {
				assertEqual(args, ["worktree", "remove", worktree], "non-forced worktree remove args");
				assertEqual(options.cwd, codeRoot, "git runs in code root");
				// Simulate successful git worktree remove by deleting the directory.
				await rm(worktree, { recursive: true, force: true });
				return { stdout: "", stderr: "", code: 0 };
			}
			return { stdout: "", stderr: "unexpected command", code: 1 };
		});

		const successNotes = await removeTaskWorktreeSafely(successPi, codeRoot, task);
		assertTrue(successNotes.some((n: string) => n.includes("Removed worktree")), "notes mention removed worktree");
		assertTrue(
			successNotes.some((n: string) => n.includes("Removed IDEA exclusions")),
			"notes mention IDEA exclusion cleanup",
		);
		assertTrue(!existsSync(worktree), "worktree directory gone after success");
		const imlAfterSuccess = await readFile(imlPath, "utf8");
		assertTrue(!imlAfterSuccess.includes(markerStart), "IDEA start marker removed after success");
		assertTrue(!imlAfterSuccess.includes(markerEnd), "IDEA end marker removed after success");
		assertEqual(calls.length, 1, "success path issues exactly one git call");
		assertEqual(calls[0]?.args.includes("--force"), false, "never force-removes worktree");

		// Failure path: dirty/safe remove fails closed — markers retained.
		const dirtyWorktree = join(worktreeBase, "dirty-task");
		await mkdir(dirtyWorktree, { recursive: true });
		const dirtySlug = "dirty-task";
		const dirtyIml = [
			'<?xml version="1.0" encoding="UTF-8"?>',
			'<module type="WEB_MODULE" version="4">',
			'  <component name="NewModuleRootManager">',
			'    <content url="file://$MODULE_DIR$">',
			`    <!-- pi-task-workflow:start ${dirtySlug} -->`,
			`    <excludeFolder url="file://$MODULE_DIR$/${dirtySlug}/vendor" />`,
			`    <!-- pi-task-workflow:end ${dirtySlug} -->`,
			"    </content>",
			"  </component>",
			"</module>",
			"",
		].join("\n");
		await writeFile(imlPath, dirtyIml, "utf8");

		const dirtyTask: TaskInfo = {
			status: "IN-PROGRESS",
			file: `${dirtySlug}.md`,
			path: join(base, `${dirtySlug}.md`),
			title: "Dirty",
			branch: `task/${dirtySlug}`,
			worktree: dirtyWorktree,
		};

		const failPi = fakePi(async (command, args) => {
			if (command === "git" && args[0] === "worktree" && args[1] === "remove") {
				assertEqual(args.includes("--force"), false, "failure path also non-forced");
				return {
					stdout: "",
					stderr:
						"fatal: working trees containing modified or untracked files cannot be removed without --force",
					code: 128,
				};
			}
			return { stdout: "", stderr: "unexpected", code: 1 };
		});

		let failed = false;
		try {
			await removeTaskWorktreeSafely(failPi, codeRoot, dirtyTask);
		} catch (err: any) {
			failed = true;
			assertTrue(
				String(err.message).includes("Safe worktree cleanup failed"),
				"fail-closed error message",
			);
		}
		assertTrue(failed, "dirty remove must throw");
		assertTrue(existsSync(dirtyWorktree), "dirty worktree remains after failed cleanup");
		const imlAfterFail = await readFile(imlPath, "utf8");
		assertTrue(
			imlAfterFail.includes(`<!-- pi-task-workflow:start ${dirtySlug} -->`),
			"IDEA markers retained after fail-closed cleanup",
		);

		// Missing path: skip git remove, still clean IDEA markers.
		const missing = join(worktreeBase, "already-gone");
		const missingSlug = "already-gone";
		const missingIml = [
			'<?xml version="1.0" encoding="UTF-8"?>',
			'<module type="WEB_MODULE" version="4">',
			'  <component name="NewModuleRootManager">',
			'    <content url="file://$MODULE_DIR$">',
			`    <!-- pi-task-workflow:start ${missingSlug} -->`,
			`    <excludeFolder url="file://$MODULE_DIR$/${missingSlug}/vendor" />`,
			`    <!-- pi-task-workflow:end ${missingSlug} -->`,
			"    </content>",
			"  </component>",
			"</module>",
			"",
		].join("\n");
		await writeFile(imlPath, missingIml, "utf8");

		const missingTask: TaskInfo = {
			status: "IN-PROGRESS",
			file: `${missingSlug}.md`,
			path: join(base, `${missingSlug}.md`),
			title: "Missing",
			branch: `task/${missingSlug}`,
			worktree: missing,
		};

		let gitCalled = false;
		const missingPi = fakePi(async () => {
			gitCalled = true;
			return { stdout: "", stderr: "should not run", code: 1 };
		});

		const missingNotes = await removeTaskWorktreeSafely(missingPi, codeRoot, missingTask);
		assertTrue(!gitCalled, "missing path must not invoke git worktree remove");
		assertTrue(
			missingNotes.some((n: string) => n.includes("Worktree path missing")),
			"notes mention missing path",
		);
		const imlAfterMissing = await readFile(imlPath, "utf8");
		assertTrue(
			!imlAfterMissing.includes(`<!-- pi-task-workflow:start ${missingSlug} -->`),
			"missing path still cleans IDEA markers",
		);

		// No worktree metadata: no side effects.
		const noWtNotes = await removeTaskWorktreeSafely(
			fakePi(async () => {
				throw new Error("exec must not be called without worktree metadata");
			}),
			codeRoot,
			{
				status: "TODO",
				file: "no-wt.md",
				path: join(base, "no-wt.md"),
				title: "No WT",
			},
		);
		assertTrue(
			noWtNotes.some((n: string) => n.includes("No Worktree metadata")),
			"no-metadata path is a no-op note",
		);
	});
}

async function main(): Promise<void> {
	const cases: Array<[string, () => Promise<void>]> = [
		["normalizeStatus ARCHIVE/CANCELLED/CANCELED", testNormalizeStatus],
		["resolveListStatuses default/include/explicit", testResolveListStatuses],
		["extractField empty-line metadata", testExtractFieldEmptyLines],
		["listTasks filesystem semantics", testListTasksFilesystem],
		["removeTaskWorktreeSafely success/failure/missing", testRemoveTaskWorktreeSafely],
	];

	let failed = 0;
	for (const [name, fn] of cases) {
		try {
			await fn();
			console.log(`ok - ${name}`);
		} catch (err: any) {
			failed += 1;
			console.error(`not ok - ${name}`);
			console.error(err?.stack || String(err));
		}
	}

	if (failed > 0) {
		console.error(`\n${failed} behavioral case(s) failed`);
		process.exit(1);
	}

	console.log(`\n${cases.length} behavioral case(s) passed`);
	console.log("PI_TASK_WORKFLOW_BEHAVIOR_OK");
}

await main();
