// Actual resolve hook implementation registered by ts-extension-resolve.mjs.

import { existsSync } from "node:fs";
import path from "node:path";
import { pathToFileURL } from "node:url";

export async function resolve(specifier, context, nextResolve) {
	try {
		return await nextResolve(specifier, context);
	} catch (err) {
		if (err?.code !== "ERR_MODULE_NOT_FOUND") {
			throw err;
		}
		if (!specifier.startsWith(".") && !specifier.startsWith("/")) {
			throw err;
		}

		const parent = context.parentURL ? new URL(context.parentURL) : null;
		const base = parent ? path.dirname(parent.pathname) : process.cwd();
		const candidates = [
			`${specifier}.ts`,
			`${specifier}.js`,
			path.join(specifier, "index.ts"),
			path.join(specifier, "index.js"),
		];

		for (const candidate of candidates) {
			const absolute = path.isAbsolute(candidate)
				? candidate
				: path.resolve(base, candidate);
			if (existsSync(absolute)) {
				return nextResolve(pathToFileURL(absolute).href, context);
			}
		}

		throw err;
	}
}
