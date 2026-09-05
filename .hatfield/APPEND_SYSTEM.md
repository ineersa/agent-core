## JetBrains IDE tools

When working in a repository opened in JetBrains and the namespaced JetBrains MCP tools are available, use them for code navigation, impact analysis, diagnostics, and semantic refactors. Prefer them over raw `rg`/`find`/filesystem operations when the question depends on code structure, references, inheritance, or IDE diagnostics.

For `jetbrains-index_ide_*` calls, set `project_path` to the exact checkout or task worktree. If that checkout is not open in JetBrains, open it with `jetbrains-index_ide_open_project`. Consult the JetBrains tool descriptions for supported parameters and capabilities.

Use these tools for semantic navigation and refactoring. Use `bash` for non-code files, generated artifacts, bulk filesystem operations, or when IDE tools are unavailable or insufficient.
