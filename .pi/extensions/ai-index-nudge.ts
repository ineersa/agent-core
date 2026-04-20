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

const STRICT_COMMAND = "castor dev:index-methods --no-ansi --strict --all";
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

function buildMissingSummaryReminder(missingEntries: string[]): string {
	return wrapSystemReminder([
		"AI Index summary reminder:",
		"- Missing class docblock summaries were detected.",
		"- Add one clear sentence as the first docblock description line for every listed class.",
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
			const strictResult = await runCastor(pi, ctx.cwd, STRICT_COMMAND);
			if (strictResult.code !== 0 && ctx.hasUI) {
				ctx.ui.notify(`⚠ AI summary validation reported issues`, "warning");
			}

			// Only check class-level missing summaries (method summaries are not enforced)
			const missingEntries = extractMissingEntries(`${strictResult.stdout}\n${strictResult.stderr}`)
				.filter((entry) => !entry.includes("::")); // skip method entries
			if (missingEntries.length === 0) {
				return;
			}

			if (ctx.hasUI) {
				ctx.ui.notify(`⚠ Missing AI index class summaries (${missingEntries.length})`, "warning");
			}

			const reminder = buildMissingSummaryReminder(missingEntries);
			return {
				content: [...event.content, { type: "text" as const, text: reminder }],
			};
		} finally {
			inFlight.delete(relativePath);
		}
	});
}
