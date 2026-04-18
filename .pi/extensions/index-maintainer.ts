import type { ExtensionAPI } from "@mariozechner/pi-coding-agent";
import { execSync } from "node:child_process";
import { readdirSync } from "node:fs";
import { resolve, join } from "node:path";

export default function (pi: ExtensionAPI) {
	pi.registerCommand("index-maintainer", {
		description:
			"Detect changed files in src/ via git and launch the index-maintainer subagent to update ai-index.toon + docs",
		handler: async (_args, ctx) => {
			const cwd = ctx.cwd;
			const srcDir = resolve(cwd, "src");
			const changedPaths = getChangedScope(cwd, srcDir);

			if (changedPaths.length === 0) {
				ctx.ui.notify("No changes detected in src/. Nothing to update.", "info");
				return;
			}

			const task = buildTask(changedPaths);
			ctx.ui.notify(
				`Launching index-maintainer for ${changedPaths.length} path(s)...`,
				"info",
			);

			await ctx.waitForIdle();
			pi.sendUserMessage(task, { deliverAs: "steer" });
		},
	});
}

/**
 * Detect git changes in src/ and return a deduplicated set of
 * namespace / sub-namespace / standalone-file paths.
 *
 * If the git tree is clean (no changes), returns ALL namespaces
 * plus any standalone .php files directly in src/.
 */
function getChangedScope(cwd: string, srcDir: string): string[] {
	const paths = new Set<string>();

	// 1) Try git diff — works for tracked changes
	try {
		const diff = execSync(
			"git diff --name-only HEAD -- src/",
			{ cwd, encoding: "utf-8" },
		).trim();

		const untracked = execSync(
			"git ls-files --others --exclude-standard -- src/",
			{ cwd, encoding: "utf-8" },
		).trim();

		const changed = [diff, untracked]
			.join("\n")
			.split("\n")
			.map((l) => l.trim())
			.filter(Boolean);

		if (changed.length === 0) {
			// Clean tree → full scope
			return collectAllNamespaces(srcDir);
		}

		for (const file of changed) {
			const scope = toScopePath(file);
			if (scope) paths.add(scope);
		}
	} catch {
		// Not a git repo or other error — fall back to full scope
		return collectAllNamespaces(srcDir);
	}

	return [...paths].sort();
}

/**
 * Map a changed file path like "src/Application/Handler/Foo.php"
 * to its scope path: "src/Application/Handler/"
 *
 * Standalone files in src/ root become "src/AgentLoopBundle.php" etc.
 * Skips docs/ directories — they're artifacts, not source.
 */
function toScopePath(file: string): string | null {
	const parts = file.split("/");
	if (parts.length <= 2) {
		// e.g. "src/AgentLoopBundle.php"
		return file;
	}
	// Skip docs/ artifacts — they're maintained by the subagent itself
	if (parts.includes("docs")) return null;
	// Return the namespace directory: "src/NS/Sub/"
	return parts.slice(0, -1).join("/") + "/";
}

/**
 * Collect all namespace directories under src/ (recursively to depth 2)
 * plus any standalone .php files directly in src/.
 */
function collectAllNamespaces(srcDir: string): string[] {
	const paths: string[] = [];

	function walk(dir: string, prefix: string, depth: number) {
		let entries;
		try {
			entries = readdirSync(dir, { withFileTypes: true });
		} catch {
			return;
		}

		for (const entry of entries) {
			if (entry.name.startsWith(".") || entry.name === "docs") continue;

			const fullPath = join(dir, entry.name);
			const scopePath = prefix + entry.name;

			if (entry.isDirectory()) {
				paths.push(scopePath + "/");
				if (depth < 2) {
					walk(fullPath, scopePath + "/", depth + 1);
				}
			} else if (entry.isFile() && entry.name.endsWith(".php") && depth === 0) {
				// Standalone files in src/ root
				paths.push(scopePath);
			}
		}
	}

	walk(srcDir, "src/", 0);
	return paths.sort();
}

/**
 * Build the user message that tells the main agent to launch the
 * index-maintainer subagent with the scoped paths.
 */
function buildTask(paths: string[]): string {
	const pathList = paths.map((p) => `- ${p}`).join("\n");
	return [
		"Launch the **index-maintainer** subagent with the following scoped paths. Pass these exactly as-is so the subagent knows what to update.",
		"",
		"```",
		`Update ai-index.toon and docs for these paths:`,
		"",
		pathList,
		"```",
		"",
		"The subagent should read its skill (index-maintainer) for the schema and workflows, then use scout subagents to explore the code before writing any artifacts.",
		"You **MUST** never launch more than 2 scouts at the same time, always launch 1 or 2 scouts and wait for reports.",
		"*IMPORTANT* Each scout MUST be responsible for it's own namespace, don't ever launch scouts on same namespace twice.",
		"**VERY IMPORTANT** Each scout MUST have it's own DEDICATED TASK and NAMESPACE, never launch scouts with the same task!",
		"Prefer not to read full files and trust scouts, unless necessary.",
	].join("\n");
}
