---
name: ai-index
description: Defines the AI index workflow for this repository, including docblock-summary rules, targeted-read navigation, index regeneration, and summary writing prompts. Use when working with ai-index.toon/docs/*.toon files, castor dev:index-methods.
license: MIT
metadata:
  author: agent-core
  version: "1.4"
---

# AI Documentation Index

## Quick start

```bash
castor dev:index-methods
```

## Core rules

- Source of truth is **PHP docblock summaries in source files**.
- Every class must have a summary in the first description sentence (enforced by `--strict`).
- Method summaries are not indexed — use IDE tools, call graph data, or targeted reads for method understanding.
- Extraction stops before the first blank line or any `@tag`.
- Generated files must not be edited manually:
  - `src/**/ai-index.toon`
  - `src/**/docs/*.toon`
  - `callgraph.json`
- Root `ai-index.toon` is curated and updated intentionally.

## What's in a class `.toon` file

Per-class `docs/<Class>.toon` files contain:

- **Class-level**: type, summary
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

Callees and callers are auto-generated from PHPStan call-graph analysis. Use them instead of `ide_call_hierarchy` — the data is already in the file you're reading.

## Navigation workflow

1. Read root `ai-index.toon`.
2. Read namespace `ai-index.toon`.
3. Read class `docs/<Class>.toon` for method coordinates and call relationships.
4. Use `symbolLine` + `symbolColumn` with IDE semantic tools for definition/navigation.
5. Read targeted source windows using `offset=start, limit=limit`.
6. Check `callees`/`callers` to understand method relationships without querying IDE tools.

## Maintenance workflow

Use Castor for all index operations:

- `castor dev:index-methods` — changed files
- `castor dev:index-methods --all --force` — full regeneration (auto-generates callgraph.json + DI wiring map)
- `castor dev:index-methods --strict --all` — read-only validation
- `castor dev:callgraph` — regenerate callgraph.json only

`castor dev:check` runs: cs-fix → phpstan → test → summaries validation → full index regeneration.

## 1) Global summary prompt

```text
Write a PHP docblock summary for the target class.

Rules:
- Output one clear sentence as the first description line.
- Describe purpose and behavior, not implementation trivia.
- Be concrete about runtime/architecture responsibility.
- Do not use vague fluff (e.g., "This class handles things").
- Keep @param/@return/@throws tags after the summary.
- Preserve existing multiline type tags exactly.
```

## 2) Class summary prompt

```text
Given a PHP class/interface/enum, write its docblock summary line.
Focus on the class' responsibility in the architecture (what it coordinates, represents, or enforces).
Avoid describing constructor wiring details unless that is the core purpose.
```

### Class examples

Good:
- `Coordinates run progression by routing commands, dispatching effects, and persisting state transitions.`
- `Represents a tool invocation request with normalized provider-facing payload fields.`

Weak:
- `This class handles things.`
- `Class for run logic.`

---

## Validation checklist

- [ ] Every class has a docblock summary.
- [ ] Summaries are specific and behavior-focused.
- [ ] Tags remain valid for PHPStan.

Then run:

```bash
LLM_MODE=true castor dev:check
```
