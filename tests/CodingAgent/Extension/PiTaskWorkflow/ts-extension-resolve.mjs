// Node ESM loader hook for extension modules that use extensionless relative imports.
// Used only by the checked-in TypeScript behavioral harness under tests/.
//
// Pi extension sources import "./types" / "./exec" without extensions. Node 22
// type-stripping can execute .ts files, but still requires resolvable module URLs.
//
// Loaded via: node --experimental-strip-types --import ./ts-extension-resolve.mjs <harness>

import { register } from "node:module";

register("./ts-extension-resolve.hooks.mjs", import.meta.url);
