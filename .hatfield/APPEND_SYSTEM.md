## JetBrains IDE tools

When working in a repository opened in JetBrains, use the namespaced JetBrains MCP tools for code navigation, impact analysis, diagnostics, and semantic refactors. Prefer them over raw `rg`/`find`/filesystem operations when the question depends on code structure, references, inheritance, or IDE diagnostics.

If multiple JetBrains projects are open, pass `project_path` for the repository you are working in.

### Fast discovery

- `jetbrains-index_ide_find_file` — find files by name/camel-case/wildcard.
- `jetbrains-index_ide_find_class` — find classes/interfaces/enums; faster than symbol search when you only need types.
- `jetbrains-index_ide_find_symbol` — find functions, methods, fields, classes, and other symbols by name.
- `jetbrains-index_ide_search_text` — search for text using the IDE word index or Find in Files.
- `jetbrains-index_ide_file_structure` — inspect a file's classes, methods, fields, functions, or headings.
- `jetbrains-index_ide_find_definition` — jump from a reference or call to its defining declaration.

### Understanding impact and architecture

- `jetbrains-index_ide_find_references` — find usages of a symbol across the project.
- `jetbrains-index_ide_call_hierarchy` — inspect callers or callees of a method/function.
- `jetbrains-index_ide_type_hierarchy` — inspect supertypes and subtypes of a class or interface.
- `jetbrains-index_ide_find_implementations` — find concrete implementations of interfaces or abstract classes.
- `jetbrains-index_ide_find_super_methods` — inspect overridden or implemented parent methods.

### Diagnostics and project state

- `jetbrains-index_ide_index_status` — check whether indexing is blocking code intelligence.
- `jetbrains-index_ide_project_status` — inspect open and managed project state.
- `jetbrains-index_ide_diagnostics` — get file, build, and test diagnostics.
- `jetbrains-index_ide_sync_files` — synchronize the IDE virtual file system after external changes.
- `jetbrains-index_ide_open_project` — open a project and wait for indexing.

### Semantic edits and refactors

- `jetbrains-index_ide_refactor_rename` — rename symbols or files and update references.
- `jetbrains-index_ide_move_file` — move files with language-aware reference and package updates.

Use these tools for semantic navigation and refactoring. Use `bash` only for non-code files, generated artifacts, bulk filesystem operations, or when IDE tools are unavailable or insufficient.
