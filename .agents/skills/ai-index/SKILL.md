---
name: ai-index
description: Defines the AI index workflow for this repository, including docblock-summary rules, targeted-read navigation, index regeneration, and summary writing prompts. Use when working with ai-index.toon/docs/*.toon files, castor dev:index-methods.
license: MIT
metadata:
  author: agent-core
  version: "1.3"
---

# AI Documentation Index

## Quick start

```bash
castor dev:index-methods
```

## Core rules

- Source of truth is **PHP docblock summaries in source files**.
- Every class must have a summary in the first description sentence (enforced by `--strict`).
- Method summaries are not indexed — use IDE tools or targeted reads for method understanding.
- Extraction stops before the first blank line or any `@tag`.
- Generated files must not be edited manually:
  - `src/**/ai-index.toon`
  - `src/**/docs/*.toon`
- Root `ai-index.toon` is curated and updated intentionally.

## Navigation workflow

1. Read root `ai-index.toon`.
2. Read namespace `ai-index.toon`.
3. Read class `docs/<Class>.toon` for method coordinates (`start`, `end`, `limit`, `symbolLine`, `symbolColumn`).
4. Use `symbolLine` + `symbolColumn` with IDE semantic tools first.
5. Read targeted source windows using `offset=start, limit=limit`.

## Maintenance workflow

Use Castor for all index operations:

- `castor dev:index-methods` — changed files
- `castor dev:index-methods --all --force` — full regeneration
- `castor dev:index-methods --strict --all` — read-only validation
- `castor dev:index-methods --migrate --all` — one-time migration from `.toon` summaries into source docblocks

`castor dev:check` includes strict summary validation (`summaries`).

## 1) Global summary prompt

```text
Write a PHP docblock summary for the target symbol.

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
castor dev:index-methods --strict --all
LLM_MODE=true castor dev:check
```
