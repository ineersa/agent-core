// @ts-ignore
import type { ExtensionAPI } from "@mariozechner/pi-coding-agent";
// @ts-ignore
import { relative, resolve, sep } from "node:path";

const CASTOR_ENV_EXPORTS = [
	"export LLM_MODE=true",
	"export CASTOR_DISABLE_VERSION_CHECK=1",
	"export NO_COLOR=1",
	"export CLICOLOR=0",
].join("\n");

const INDEX_COMMAND_BASE = "castor dev:index-methods --no-ansi";
const MISSING_PREFIX = "MISSING:";

function wrapSystemReminder(content: string): string {
	return `<system-reminder>\n${content}\n</system-reminder>`;
}

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

function extractMissingEntries(output: string): string[] {
	return output
		.split(/\r?\n/)
		.map((line) => line.trim())
		.filter((line) => line.startsWith(MISSING_PREFIX))
		.map((line) => line.slice(MISSING_PREFIX.length).trim())
		.filter((line) => line.length > 0);
}

function shellEscape(value: string): string {
	return `'${value.replace(/'/g, `'"'"'`)}'`;
}

function buildIndexCommand(relativePath: string, strict: boolean): string {
	const strictFlag = strict ? " --strict" : "";
	return `${INDEX_COMMAND_BASE}${strictFlag} -- ${shellEscape(relativePath)}`;
}

function buildMissingSummaryReminder(relativePath: string, missingEntries: string[]): string {
	return wrapSystemReminder([
		"AI Index summary reminder:",
		`- Missing class/method docblock summaries were detected in ${relativePath}.`,
		"- Add one clear sentence as the first docblock description line for every listed class/method.",
		"",
		"<missing-summaries>",
		...missingEntries.map((entry) => `- ${entry}`),
		"</missing-summaries>",
	].join("\n"));
}

async function runCastor(pi: ExtensionAPI, cwd: string, command: string): Promise<{ stdout: string; stderr: string; code: number }> {
	const script = `${CASTOR_ENV_EXPORTS}\n${command}`;
	const result = await pi.exec("bash", ["-lc", script], {
		cwd,
		timeout: 120_000,
	});

	return {
		stdout: result.stdout ?? "",
		stderr: result.stderr ?? "",
		code: result.code,
	};
}

export default function aiIndexNudgeExtension(pi: ExtensionAPI): void {
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
			const indexResult = await runCastor(pi, ctx.cwd, buildIndexCommand(relativePath, false));
			if (indexResult.code !== 0 && ctx.hasUI) {
				ctx.ui.notify(`⚠ Failed to regenerate AI index for ${relativePath}`, "warning");
			}

			const strictResult = await runCastor(pi, ctx.cwd, buildIndexCommand(relativePath, true));
			if (strictResult.code !== 0 && ctx.hasUI) {
				ctx.ui.notify(`⚠ AI summary validation reported issues in ${relativePath}`, "warning");
			}

			const missingEntries = extractMissingEntries(`${strictResult.stdout}\n${strictResult.stderr}`)
				.filter((entry) => entry.startsWith(`${relativePath}:`));
			if (missingEntries.length === 0) {
				return;
			}

			if (ctx.hasUI) {
				ctx.ui.notify(`⚠ Missing AI index summaries in ${relativePath}`, "warning");
			}

			const reminder = buildMissingSummaryReminder(relativePath, missingEntries);
			return {
				content: [...event.content, { type: "text" as const, text: reminder }],
			};
		} finally {
			inFlight.delete(relativePath);
		}
	});
}
