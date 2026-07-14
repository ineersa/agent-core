import { register } from "node:module";

register(new URL("./resolve-hook.mjs", import.meta.url), import.meta.url);
