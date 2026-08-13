// Minimal per-worktree .idea metadata derived from the integration primary module.

import { existsSync } from "node:fs";
import { mkdir, readdir, readFile, writeFile } from "node:fs/promises";
import { basename, join } from "node:path";

async function findPrimaryIml(codeRoot: string): Promise<string | null> {
	const ideaDir = join(codeRoot, ".idea");
	if (!existsSync(ideaDir)) return null;
	const primary = join(ideaDir, `${basename(codeRoot)}.iml`);
	if (existsSync(primary)) return primary;
	const entries = await readdir(ideaDir).catch((err: unknown) => {
		// Intentional local degradation: missing/unreadable .idea is non-fatal.
		void err;
		return [] as string[];
	});
	const imlFiles = entries.filter((e) => e.endsWith(".iml"));
	if (imlFiles.length === 1) return join(ideaDir, imlFiles[0]);
	return null;
}

/**
 * Strip cross-project module order entries and any absolute paths outside $MODULE_DIR$.
 * Keep source roots / exclude folders / inherited JDK.
 */
function sanitizeIml(content: string): string {
	// Drop orderEntry module references (other modules in the aggregate project).
	let out = content.replace(/\n?\s*<orderEntry\s+type="module"[^/]*\/>/g, "");
	// Drop absolute file:// paths that are not $MODULE_DIR$ macros (defensive).
	out = out.replace(
		/\n?\s*<(?:sourceFolder|excludeFolder)\s+[^>]*url="file:\/\/(?!\$MODULE_DIR\$)[^"]+"[^/]*\/>/g,
		"",
	);
	return out;
}

/**
 * Create a worktree-local .idea with a single primary module and modules.xml.
 * Idempotent when already present. Returns a note for move_task.
 */
export async function ensureWorktreeIdea(
	codeRoot: string,
	worktree: string,
): Promise<{ created: boolean; note?: string }> {
	const worktreeIdea = join(worktree, ".idea");
	const moduleName = basename(worktree);
	const targetIml = join(worktreeIdea, `${moduleName}.iml`);
	const modulesXml = join(worktreeIdea, "modules.xml");

	if (existsSync(targetIml) && existsSync(modulesXml)) {
		return { created: false, note: `Worktree .idea already present at ${worktreeIdea}.` };
	}

	const sourceIml = await findPrimaryIml(codeRoot);
	if (!sourceIml) {
		return {
			created: false,
			note: "Integration .idea primary module not found; skipped worktree .idea setup.",
		};
	}

	let content: string;
	try {
		content = await readFile(sourceIml, "utf8");
	} catch (err: unknown) {
		const msg = err instanceof Error ? err.message : "read error";
		return {
			created: false,
			note: `Failed to read integration IDEA module: ${msg}`,
		};
	}

	const sanitized = sanitizeIml(content);
	const modules = `<?xml version="1.0" encoding="UTF-8"?>
<project version="4">
  <component name="ProjectModuleManager">
    <modules>
      <module fileurl="file://$PROJECT_DIR$/.idea/${moduleName}.iml" filepath="$PROJECT_DIR$/.idea/${moduleName}.iml" />
    </modules>
  </component>
</project>
`;
	const gitignore = `# Default ignored files
/shelf/
/workspace.xml
/httpRequests/
/dataSources/
/dataSources.local.xml
`;

	try {
		await mkdir(worktreeIdea, { recursive: true });
		await writeFile(targetIml, sanitized, "utf8");
		await writeFile(modulesXml, modules, "utf8");
		await writeFile(join(worktreeIdea, ".gitignore"), gitignore, "utf8");
		return { created: true, note: `Created worktree .idea module at ${worktreeIdea}.` };
	} catch (err: unknown) {
		const msg = err instanceof Error ? err.message : "write error";
		return {
			created: false,
			note: `Failed to write worktree .idea: ${msg}`,
		};
	}
}
