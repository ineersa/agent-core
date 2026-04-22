// @ts-ignore
import type { ExtensionAPI } from "@mariozechner/pi-coding-agent";
// @ts-ignore
import { relative, resolve, sep } from "node:path";

const GENERATE_COMMAND_BASE = "vendor/bin/ai-index generate --no-ansi";

function normalizeToolPath(pathValue: unknown): string | null {
	if (typeof pathValue !== "string") {
		return null;
	}

	const normalized = pathValue.replace(/^@/, "").trim();
	return normalized.length > 0 ? normalized : null;
}

function toProjectRelativePath(cwd: string, pathValue: string): string | null {
	const absolute = resolve(cwd, pathValue);
	const rel = relative(cwd, absolute);
	if (rel === "" || rel === ".") {
		return null;
	}
	if (rel === ".." || rel.startsWith(`..${sep}`)) {
		return null;
	}

	return rel.split(sep).join("/");
}

function shouldProcessPath(relativePath: string): boolean {
	return relativePath.startsWith("src/") && relativePath.endsWith(".php");
}

function shellEscape(value: string): string {
	return `'${value.replace(/'/g, `"'"'"'`)}'`;
}

function buildGenerateCommand(relativePath: string): string {
	return `${GENERATE_COMMAND_BASE} -- ${shellEscape(relativePath)}`;
}

async function runGenerate(
	pi: ExtensionAPI,
	cwd: string,
	relativePath: string,
): Promise<{ code: number }> {
	const result = await pi.exec("bash", ["-lc", buildGenerateCommand(relativePath)], {
		cwd,
		timeout: 120_000,
	});

	return {
		code: result.code,
	};
}

export default function aiIndexWatchExtension(pi: ExtensionAPI): void {
	const inFlight = new Set<string>();

	pi.on("tool_result", async (event, ctx) => {
		if (event.isError) {
			return;
		}
		if (event.toolName !== "edit" && event.toolName !== "write") {
			return;
		}

		const rawPath = normalizeToolPath((event.input as Record<string, unknown>).path);
		if (!rawPath) {
			return;
		}

		const relativePath = toProjectRelativePath(ctx.cwd, rawPath);
		if (!relativePath || !shouldProcessPath(relativePath)) {
			return;
		}

		if (inFlight.has(relativePath)) {
			return;
		}
		inFlight.add(relativePath);

		try {
			const result = await runGenerate(pi, ctx.cwd, relativePath);
			if (result.code !== 0 && ctx.hasUI) {
				ctx.ui.notify(`⚠ Failed to regenerate AI index for ${relativePath}`, "warning");
			}
		} finally {
			inFlight.delete(relativePath);
		}
	});
}
