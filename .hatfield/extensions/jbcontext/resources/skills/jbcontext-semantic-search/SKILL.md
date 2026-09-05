---
name: jbcontext-semantic-search
description: "Semantic code search with Hatfield code_search (jbcontext). Use when the relevant file or subsystem is unknown and you need meaning-based discovery before local reads."
version: 1.0.0
---

# Semantic code search

Use the Hatfield `code_search` tool for meaning-based discovery when you do not already know the relevant file, class, or symbol.

## Workflow

1. Run one focused natural-language query with `code_search`.
2. Open and inspect at least one returned file with `read`.
3. Inspect nearby code in that directory or subsystem.
4. If needed, run one narrowed retry with `path_filter` set to a project-relative directory from the best first hit.
5. Prefer IDE definition/references or direct reads once you know the symbol or path.

## Do not use for

- Builds, tests, or package installs
- Git operations
- Reviewing an existing diff
- Known files or symbols you can open directly

## Query tips

- Prefer descriptive behavior over single keywords.
- Include feature, class, or method names from the task when available.
- Keep the first query specific; narrow with `path_filter` only once.
