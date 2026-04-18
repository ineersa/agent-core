---
name: index-maintainer
description: Maintain the ai-index.toon documentation system — create, update, or fix indexes and docs for any namespace, sub-namespace, or file. Use when adding/removing/renaming files, creating new namespaces, or when indexes are stale/incomplete. Also use when user mentions ai-index, docs maintenance, or codebase documentation.
model: zai/glm-5.1
---

# Index Maintainer

Maintains the hierarchical `ai-index.toon` + `docs/` documentation system for the entire `src/` tree.

## System overview

Every namespace and sub-namespace has:
- **`ai-index.toon`** — machine-readable index mapping files and sub-namespaces with short responsibility descriptions
- **`docs/`** — markdown files with detailed per-file or per-namespace documentation

The root `ai-index.toon` maps all top-level namespaces. Each namespace index maps its files and sub-namespaces, which have their own indexes recursively.

## Index format

Read the **toon** skill for the full format reference and scripting escape hatch.

### Root index (`ai-index.toon`)
```
spec: agent-core.ai-docs/v1
package: ineersa/agent-core
updatedAt: <date>
description: <project summary>
namespaces[N]{namespace,fqcn,path,description,index}:
  <Name>,Ineersa\AgentCore\<Name>,src/<Name>,<1-2 line summary>,src/<Name>/ai-index.toon
```

### Namespace index with files (`src/<NS>/ai-index.toon`)
```
spec: agent-core.ai-docs/v1
namespace: <NS>
fqcn: Ineersa\AgentCore\<NS>
description: <1-2 line namespace summary>
files[N]{file,type,responsibility,docs}:
  <File>.php,<type>,"<1-2 line summary>",docs/<File>.md
subNamespaces[M]{namespace,fqcn,path,description,index}:
  <SubNS>,Ineersa\AgentCore\<NS>\<SubNS>,<SubNS>,<summary>,<SubNS>/ai-index.toon
```

## Workflows

### Create/update index for a namespace

1. **Launch a scout subagent** targeting the namespace. Task it to read ALL PHP files and report: file name, class name, type, 1-2 line responsibility, key public methods, dependencies.
2. **Wait for the scout report** — do NOT read files yourself unless the report is incomplete.
3. From the report, write/update:
   - `ai-index.toon` — file entries with responsibility + docs reference
   - `docs/<File>.md` or `docs/overview.md` — detailed documentation
4. **Use the scripting escape hatch** (see toon skill) for reliable TOON generation.

### Add a new file to existing namespace

1. Read the namespace's `ai-index.toon` to understand current structure.
2. Launch scout for just that file.
3. Use a disposable PHP script to decode the TOON, add the entry, and re-encode.
4. Create `docs/<File>.md`.

### Add a new sub-namespace

1. Create the sub-namespace directory with `ai-index.toon` and `docs/`.
2. Add a `subNamespaces` entry in the parent's `ai-index.toon`.
3. Launch scout for the new sub-namespace, wait for report, populate index + docs.

### Remove/rename a file

1. Update the namespace `ai-index.toon` (remove/rename the entry via PHP script).
2. Remove/rename the corresponding `docs/*.md`.
3. If renaming, update all references in parent indexes.
4. Use `jetbrains_index_ide_refactor_rename` if it's a code symbol rename — it handles references automatically.

### Fix stale/incomplete indexes

1. Compare `ai-index.toon` files entries against actual PHP files in the directory.
2. For any missing files, launch scout for those specific files.
3. Update index + create missing docs.

## Scout usage

Always launch scout subagents (1 at a time) for file exploration:
- **1 scout per namespace** for full namespace audit
- **1 scout for specific files** when adding/updating individual entries
- **Wait for report** before writing any artifacts
- Never read source files directly — scouts are more token-efficient

Example scout task:
```
launch scout targeting src/Application/Handler/
Task: Read ALL PHP files. For each: class name, type, 1-2 line responsibility, key methods, dependencies.
```

## Validation checklist

After any change:
- [ ] All `ai-index.toon` files pass `php scripts/validate-index-toon.php <path>`
- [ ] Every PHP file in the directory has a corresponding `files` entry
- [ ] Every `files` entry with `docs: docs/X.md` has that file existing
- [ ] Every `subNamespaces` entry has a valid `index` path pointing to an existing `ai-index.toon`
- [ ] Descriptions are concise (1-2 lines in index, detailed in docs)
- [ ] `updatedAt` reflects the current date
