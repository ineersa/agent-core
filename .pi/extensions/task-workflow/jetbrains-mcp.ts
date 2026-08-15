// Package-private one-shot JetBrains MCP client for task-workflow lifecycle.
// Connect → call one tool → discard. No shared connection pool.
// Uses raw streamable-HTTP JSON-RPC (no project-local npm dependency).

import { existsSync } from "node:fs";
import { readFile } from "node:fs/promises";
import { join } from "node:path";

const SERVER_NAME = "jetbrains-index";
const OPEN_TOOL = "ide_open_project";
const CLOSE_TOOL = "ide_close_project";
const OPEN_TIMEOUT_SECONDS = 600;
const PROTOCOL_VERSION = "2025-03-26";

type McpServerDef = {
	url?: string;
	headers?: Record<string, string>;
};

type JsonRpcSuccess = {
	result?: {
		isError?: boolean;
		content?: Array<{ type?: string; text?: string }>;
	};
	error?: { message?: string; code?: number };
};

function sanitizeError(err: unknown): string {
	const raw = err instanceof Error ? err.message : String(err ?? "unknown error");
	// Strip URLs/tokens/headers that may appear in transport errors.
	const scrubbed = raw
		.replace(/https?:\/\/[^\s)]+/gi, "<url>")
		.replace(/(authorization|token|api[_-]?key|bearer)\s*[:=]\s*\S+/gi, "$1=<redacted>")
		.replace(/\s+/g, " ")
		.trim();
	const max = 240;
	return scrubbed.length > max ? scrubbed.slice(0, max) + "…" : scrubbed;
}

async function resolveServerUrl(codeRoot: string): Promise<string> {
	const configPath = join(codeRoot, ".pi", "mcp.json");
	if (!existsSync(configPath)) {
		throw new Error(`Missing ${configPath}`);
	}
	const raw = await readFile(configPath, "utf8");
	const parsed = JSON.parse(raw) as {
		mcpServers?: Record<string, McpServerDef>;
	};
	const def = parsed.mcpServers?.[SERVER_NAME];
	const url = def?.url?.trim();
	if (!url) {
		throw new Error(`MCP server "${SERVER_NAME}" has no url in .pi/mcp.json`);
	}
	return url;
}

async function postJsonRpc(
	url: string,
	body: Record<string, unknown>,
	timeoutMs: number,
	allowEmpty = false,
): Promise<JsonRpcSuccess> {
	const controller = new AbortController();
	const timer = setTimeout(() => controller.abort(), timeoutMs);
	try {
		const res = await fetch(url, {
			method: "POST",
			headers: {
				"content-type": "application/json",
				accept: "application/json, text/event-stream",
			},
			body: JSON.stringify(body),
			signal: controller.signal,
		});
		const text = await res.text();
		if (!res.ok) {
			throw new Error(`MCP HTTP ${res.status}`);
		}
		if (allowEmpty && text.trim() === "") {
			return {};
		}
		// Streamable HTTP may return SSE; this server replies with plain JSON.
		if (text.startsWith("event:") || text.includes("\ndata:")) {
			const dataLine = text
				.split("\n")
				.map((l) => l.trim())
				.find((l) => l.startsWith("data:"));
			if (!dataLine) {
				throw new Error("MCP SSE response missing data");
			}
			return JSON.parse(dataLine.slice("data:".length).trim()) as JsonRpcSuccess;
		}
		return JSON.parse(text) as JsonRpcSuccess;
	} finally {
		clearTimeout(timer);
	}
}

async function callOnce(
	codeRoot: string,
	toolName: string,
	args: Record<string, unknown>,
): Promise<void> {
	const url = await resolveServerUrl(codeRoot);
	// Request budget: open waits up to OPEN_TIMEOUT_SECONDS inside the IDE tool;
	// allow transport overhead on top.
	const timeoutMs =
		toolName === OPEN_TOOL
			? (OPEN_TIMEOUT_SECONDS + 60) * 1000
			: 60_000;

	const init = await postJsonRpc(
		url,
		{
			jsonrpc: "2.0",
			id: 1,
			method: "initialize",
			params: {
				protocolVersion: PROTOCOL_VERSION,
				capabilities: {},
				clientInfo: { name: "pi-task-workflow", version: "1.0.0" },
			},
		},
		15_000,
	);
	if (init.error) {
		throw new Error(init.error.message ?? "initialize failed");
	}

	// Best-effort initialized notification (ignore transport failures).
	try {
		await postJsonRpc(
			url,
			{
				jsonrpc: "2.0",
				method: "notifications/initialized",
			},
			5_000,
			true,
		);
	} catch (err) {
		// Intentional local degradation: some servers accept tools/call without this.
		void sanitizeError(err);
	}

	const call = await postJsonRpc(
		url,
		{
			jsonrpc: "2.0",
			id: 2,
			method: "tools/call",
			params: { name: toolName, arguments: args },
		},
		timeoutMs,
	);
	if (call.error) {
		throw new Error(call.error.message ?? `${toolName} rpc error`);
	}
	if (call.result?.isError) {
		// Do not surface raw tool payload; short failure marker only.
		throw new Error(`${toolName} returned isError`);
	}
}

/** Open exact worktree project and wait for readiness. Returns a move_task note. */
export async function openWorktreeProject(codeRoot: string, worktree: string): Promise<string> {
	try {
		await callOnce(codeRoot, OPEN_TOOL, {
			path: worktree,
			timeoutSeconds: OPEN_TIMEOUT_SECONDS,
			// Route the MCP call through an already-open integration project when present.
			project_path: codeRoot,
		});
		return `Opened JetBrains project for worktree ${worktree}.`;
	} catch (err) {
		return `JetBrains project open degraded for ${worktree}: ${sanitizeError(err)}. Filesystem tools remain available.`;
	}
}

/** Close exact worktree project. Returns a move_task note. */
export async function closeWorktreeProject(codeRoot: string, worktree: string): Promise<string> {
	try {
		await callOnce(codeRoot, CLOSE_TOOL, {
			project_path: worktree,
		});
		return `Closed JetBrains project for worktree ${worktree}.`;
	} catch (err) {
		return `JetBrains project close degraded for ${worktree}: ${sanitizeError(err)}.`;
	}
}
