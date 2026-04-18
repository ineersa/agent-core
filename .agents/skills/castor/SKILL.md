---
name: castor
description: Runs and discovers project tasks via Castor for Docker lifecycle, Symfony console, Composer, tests, and quality checks. Use when the user mentions castor, task runner, dev:up, dev:down, prod compose, containerized PHP, migrations in Docker, or replacing make with project tasks.
license: MIT
metadata:
  author: Symfony Web Template
  version: "1.0"
---

# Castor

This repository uses Castor as the single task runner for local and production-like operations.

## Rules

- Prefer `castor` over ad hoc `docker compose` / host PHP when a task exists.
- Use `dev:*` for local development (`compose.yaml` + `compose.override.yaml`).
- Use `prod:*` only for production-like compose (`compose.yaml` + `compose.prod.yaml`).
- For the catalog of this repo’s tasks, run `castor list`, `castor list dev`, or `castor list prod`.

## Quick start

```bash
castor dev:setup
castor dev:bootstrap
castor dev:up
```

## When adding or debugging Castor tasks

Task entry points live in `castor.php`, `.castor/helpers.php`, `.castor/dev.php`, and `.castor/prod.php`. Vendored Castor docs live under `references/upstream/` (`getting-started/`, `going-further/`). For a concise index of built-in functions, attributes, and environment variables, see [references/castor-framework-reference.md](references/castor-framework-reference.md).
