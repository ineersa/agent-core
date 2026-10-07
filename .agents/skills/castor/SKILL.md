---
name: castor
description: "Use for Castor task discovery, QA, packaging, or task implementation."
license: MIT
metadata:
  author: Hatfield
  version: "1.2"
---

# Castor

- Run `castor list` to discover tasks; use existing tasks from the exact worktree.
- Run all QA through Castor. Load the `testing` skill before test work.
- Do not export `LLM_MODE` or add a command-rewriting extension.
- Before adding or debugging tasks in `castor.php` or `.castor/`, read [the framework reference](references/castor-framework-reference.md).
- Load individual files under `references/upstream/` only when the framework reference does not answer the question.
