---
name: ai-index
description: Defines AI index navigation and regeneration workflow for this repository.
license: MIT
metadata:
  author: ai-index
  version: "0.1"
---

# AI Documentation Index

## Quick start

```bash
vendor/bin/ai-index generate --changed
```

## Core rules

- Source of truth is **PHP source code**.
- Generated AI index files should not be edited manually.
- Namespace-level `ai-index.toon` files may keep curated description fields.

## Maintenance commands

- `vendor/bin/ai-index setup` — install/update agent templates and AGENTS.md section.
- `vendor/bin/ai-index wiring:export` — export Symfony DI wiring map.
- `vendor/bin/ai-index generate` — regenerate class/namespace AI indexes.

## Suggested workflow

1. Run `vendor/bin/ai-index wiring:export`.
2. Run `vendor/bin/ai-index generate --changed` for incremental updates.
3. Run `vendor/bin/ai-index generate --all --force` for full refresh.
