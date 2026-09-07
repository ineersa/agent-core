---
name: castor
description: Runs and discovers Hatfield project tasks via Castor for tests, quality checks, packaging, and local agent launches. Use when the user mentions castor, task runner, QA lanes, PHAR builds, distribution, or replacing ad hoc shell with project tasks.
license: MIT
metadata:
  author: Hatfield
  version: "1.1"
---

# Castor

This repository uses Castor as the single task runner for local development and quality work. Prefer `castor` over raw `vendor/bin/*` or one-off shell when a task exists.

## Rules

- Discover tasks with `castor list`, then run the named task from the exact worktree you care about.
- All QA, tests, lint, static analysis, formatting, and docs validation go through Castor. See the `testing` skill for the command matrix, timeouts, and failure diagnostics.
- Project Castor execution is always compact: quiet/non-TTY tool output, JUnit reports, TOON log formats, `CASTOR_DISABLE_VERSION_CHECK` / `NO_COLOR` / `CLICOLOR=0`, and `castor list` defaults to `--format=md --short` (explicit `--format` / `--short` still win). Do not export `LLM_MODE` or rely on a rewrite extension.
- Packaging and release tasks live under `phar:*` and `distribution:*`. Docs selection validation is `castor docs:validate`.
- There is no Docker Compose or `dev:*` / `prod:*` lifecycle workflow in this repository. Do not invent compose up/down tasks from older Symfony templates.

## Common entry points

```bash
castor list
castor check
castor test
castor test:tui
castor docs:validate
castor phar:ensure
castor distribution:build
```

## When adding or debugging Castor tasks

Task entry points live in `castor.php` and `.castor/*.php` (helpers, e2e, qa, packaging, distribution). Vendored Castor framework docs live under `references/upstream/` (`getting-started/`, `going-further/`). For a concise index of built-in functions, attributes, and environment variables, see [references/castor-framework-reference.md](references/castor-framework-reference.md).
