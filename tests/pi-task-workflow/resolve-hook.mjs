import { existsSync } from "node:fs";
import { dirname, resolve as pathResolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const SHIM = new URL("./pi-coding-agent-shim.mjs", import.meta.url).href;

export async function resolve(specifier, context, nextResolve) {
	if (specifier === "@earendil-works/pi-coding-agent") {
		return { url: SHIM, shortCircuit: true };
	}

	const isRelative =
		specifier.startsWith("./") ||
		specifier.startsWith("../") ||
		specifier.startsWith("/") ||
		specifier.startsWith("file:");

	if (isRelative && context.parentURL) {
		const parentPath = fileURLToPath(context.parentURL);
		const base = specifier.startsWith("file:")
			? fileURLToPath(specifier)
			: pathResolve(dirname(parentPath), specifier);

		if (!/\.(ts|js|mjs|cjs|json)$/.test(base)) {
			for (const suffix of [".ts", ".js", ".mjs"]) {
				const candidate = base + suffix;
				if (existsSync(candidate)) {
					return { url: pathToFileURL(candidate).href, shortCircuit: true };
				}
			}
		}
	}

	return nextResolve(specifier, context);
}
