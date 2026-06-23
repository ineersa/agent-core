## JetBrains IDE tools

When working in a repository opened in JetBrains, use the `ide_*` tools for code navigation, impact analysis, diagnostics, and semantic refactors. Prefer them over raw `rg`/`find`/filesystem operations when the question depends on code structure, references, inheritance, or IDE diagnostics.

If multiple JetBrains projects are open, pass `project_path` for the repository you are working in.

### Fast discovery

- `ide_find_file` — find files by name/camel-case/wildcard.
- `ide_find_class` — find classes/interfaces/enums; faster than symbol search when you only need types.
- `ide_find_symbol` — find functions, methods, fields, classes, and other symbols by name.
- `ide_search_text` — indexed text/regex search, with filters for code/comments/strings and file masks.
- `ide_file_structure` — inspect a file's classes, methods, fields, functions, or Markdown headings before reading the whole file.
- `ide_find_definition` — jump from a reference/import/call to the defining declaration.
- `ide_open_file` — open/navigate to a file and line in the IDE when useful for diagnostics or user-visible navigation.

Use pagination cursors when result sets are truncated.

### Understanding impact and architecture

Use these before answering review, architecture, removal, or refactor-risk questions:

- `ide_find_references` — find usages of a symbol before changing/removing it.
- `ide_call_hierarchy` — use `direction: "callers"` for blast radius and `direction: "callees"` to inspect implementation flow.
- `ide_type_hierarchy` — inspect supertypes/subtypes of a class or interface.
- `ide_find_implementations` — find concrete implementations of interfaces/abstract classes or methods.
- `ide_find_super_methods` — inspect overridden/implemented parent methods.

Do not judge an API or symbol safe to change/remove without checking references and, when relevant, callers/implementations/hierarchy.

### Diagnostics and project state

- `ide_index_status` — check whether indexing/dumb mode is blocking code intelligence.
- `ide_project_status` — see which IDE projects are open; useful when tool calls require `project_path`.
- `ide_diagnostics` — get file problems, build errors, and test results. Use after edits or when investigating failures.
- `ide_sync_files` — sync IDE VFS/PSI after external file creation/modification/deletion if IDE results look stale.
- `ide_open_project` — open a project and wait for indexing when the needed repository is not already open.

### Semantic edits and refactors

- `ide_refactor_rename` — rename symbols or files with reference updates. Use instead of search/replace for code/resource renames.
- `ide_move_file` — move files with IDE refactoring support so imports, packages, namespaces, and references can be updated.

Do not use `mv`, `git mv`, or manual text replacement for source-file moves/renames when an IDE refactor tool applies. These tools modify files; call them only when the user has asked for the change and the target is clear.

### Workflow defaults

1. Start with IDE search/navigation tools for code questions.
2. Use structure/hierarchy/reference tools to gather semantic evidence before conclusions.
3. Prefer IDE refactor tools for moves/renames.
4. Fall back to bash/rg/find only for non-code files, generated artifacts, bulk filesystem operations, or when IDE tools are unavailable/insufficient.
