// @ts-ignore
import type { ExtensionAPI } from "@mariozechner/pi-coding-agent";
// @ts-ignore
import { isToolCallEventType } from "@mariozechner/pi-coding-agent";

const CASTOR_COMMAND_PATTERN = /(^|\s)(?:vendor\/bin\/)?castor(?=\s|$)/;
const LLM_MODE_PATTERN = /\bLLM_MODE\s*=/;
const VERSION_CHECK_PATTERN = /\bCASTOR_DISABLE_VERSION_CHECK\s*=/;
const NO_COLOR_PATTERN = /\bNO_COLOR\s*=/;
const CL_COLOR_PATTERN = /\bCLICOLOR\s*=/;

// Stop at shell operators so pipes/redirections are not swallowed into args.
const CASTOR_LIST_PATTERN = /((?:^|\s|&&\s*|\|\|\s*|;\s*)(?:vendor\/bin\/)?castor\s+list\b)((?:\s+[^\s|;&>]+)*)/g;

function applyLlmFriendlyListDefaults(command: string): string {
	return command.replace(CASTOR_LIST_PATTERN, (_match, prefix: string, args: string) => {
		const hasRaw = /\s--raw\b/.test(args);
		const hasFormat = /\s--format(?:=|\s)/.test(args);
		const hasShort = /\s--short\b/.test(args);
		const hasNoAnsi = /\s--no-ansi\b/.test(args);

		let suffix = args;
		if (!hasRaw && !hasFormat) {
			suffix += " --format=md";
		}
		if (!hasRaw && !hasShort) {
			suffix += " --short";
		}
		if (!hasNoAnsi) {
			suffix += " --no-ansi";
		}

		return `${prefix}${suffix}`;
	});
}

export default function (pi: ExtensionAPI) {
	pi.on("tool_call", (event) => {
		if (!isToolCallEventType("bash", event)) {
			return;
		}

		const { command } = event.input;
		if (!CASTOR_COMMAND_PATTERN.test(command)) {
			return;
		}

		const exports: string[] = [];
		if (!LLM_MODE_PATTERN.test(command)) {
			exports.push("export LLM_MODE=true");
		}
		if (!VERSION_CHECK_PATTERN.test(command)) {
			exports.push("export CASTOR_DISABLE_VERSION_CHECK=1");
		}
		if (!NO_COLOR_PATTERN.test(command)) {
			exports.push("export NO_COLOR=1");
		}
		if (!CL_COLOR_PATTERN.test(command)) {
			exports.push("export CLICOLOR=0");
		}

		const transformedCommand = applyLlmFriendlyListDefaults(command);
		event.input.command = exports.length > 0
			? `${exports.join("\n")}\n${transformedCommand}`
			: transformedCommand;
	});
}
