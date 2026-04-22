---
name: ai-index
description: Defines the AI index workflow for this repository, including targeted-read navigation and index regeneration. Use when working with ai-index.toon/docs/*.toon files and castor dev:index-methods.
license: MIT
metadata:
  author: agent-core
  version: "1.6"
---

# AI Documentation Index

## Quick start

```bash
castor dev:index-methods
```

## Core rules

- Source of truth is **PHP source code**.
- Generated files must not be edited manually:
  - `src/**/docs/*.toon`
  - `callgraph.json`
- `src/**/ai-index.toon` is generator-managed, except curated description fields:
  - `description`
  - `subNamespaces[*].description`
- Root `ai-index.toon` is curated and updated intentionally.
- Do not reintroduce class or file `summary` fields.

## What's in a class `.toon` file

Per-class `docs/<Class>.toon` files contain:

- **Class-level**: type
- **Class sections** (`sections`) for targeted reads:
  - `classDoc`
  - `constants`
  - `properties`
  - `constructor` (includes `commentStart` + `signatureLine` when available)
- **Constructor inputs** (`constructorInputs`) from `__construct` params:
  - `param`, `type`, `required`
- **DI wiring** (`wiring`) from compiled Symfony container metadata:
  - `serviceDefinitions` (service id + visibility/autowire/autoconfigure + resolved service args)
  - `aliases` (service id alias -> target)
  - `injectedInto` (reverse DI edges showing which classes consume this service)
- **Per method**:
  - `start`, `end`, `limit` — read window for the full method (use `read(path, offset=start, limit=limit)`)
  - `symbolLine`, `symbolColumn` — coordinates for IDE semantic tools
  - `signature` — full method signature (modifiers, params, return type)
  - `callees` — methods this method calls (e.g. `CommandRouter::route`)
  - `callers` — methods that call this method (e.g. `RunOrchestrator::onApplyCommand`)

Callees and callers are auto-generated from PHPStan call-graph analysis.

## Navigation workflow

1. Read root `ai-index.toon`.
2. Read namespace `ai-index.toon`.
3. Read class `docs/<Class>.toon` for method coordinates and call relationships.
4. Use `symbolLine` + `symbolColumn` with IDE semantic tools for definition/navigation.
5. Read targeted source windows using `offset=start, limit=limit`.
6. Check `callees`/`callers` to understand method relationships quickly.

## Curated description maintenance

Curated descriptions live in:

- root `ai-index.toon` → top-level `description` + `namespaces[*].description`
- namespace `src/**/ai-index.toon` → `description` + `subNamespaces[*].description`

Description style:

- One sentence, architecture-responsibility focused.
- Explain boundary and role, not implementation trivia.
- Keep concise and specific.

Recommended prompt template (for `index-maintainer` agent):

```text
Update curated descriptions in root ai-index.toon and affected src/**/ai-index.toon files.
Keep one-sentence architecture-focused descriptions.
Preserve Toon schema and generated fields.
Do not add summary fields.
Regenerate indexes with castor and validate with dev:check.
```

## Maintenance workflow

Use Castor for all index operations:

- `castor dev:index-methods` — changed files
- `castor dev:index-methods --all --force` — full regeneration (auto-generates callgraph.json + DI wiring map)
- `castor dev:callgraph` — regenerate callgraph.json only

`castor dev:check` runs: cs-fix → phpstan → test → full index regeneration.

## Validation checklist

- [ ] Generated `.toon` files were produced by `castor dev:index-methods` (not manually edited).
- [ ] Method coordinates and signatures align with source after edits.
- [ ] Run quality checks with `LLM_MODE=true castor dev:check`.
