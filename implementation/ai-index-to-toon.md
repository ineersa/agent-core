# ai-index: JSON → TOON Migration

## Goal

Convert all `ai-index.json` files to `ai-index.toon` (Token-Oriented Object Notation) for ~30-60% token reduction when agents read indexes. JSON remains the authoring format internally; TOON is the published/consumed format.

## Context

- TOON spec: https://toonformat.dev/
- PHP package: https://github.com/HelgeSverre/toon-php (`composer require helgesverre/toon`)
- Only the **index-maintainer** subagent produces indexes — it will learn TOON via a skill
- Agents **read** TOON (they're good at this) — no generation burden on consumers
- Castor task validates all `.toon` files and reports errors

## Phases

### Phase 1: Foundation

**1.1 Install PHP dependency**
```
composer require helgesverre/toon
```

**1.2 Create TOON skill** (`.agents/skills/toon/SKILL.md`)
- Embed the TOON format overview (objects, arrays, tabular format, quoting rules)
- Include concrete examples of our index schema in TOON
- Focus on: tabular arrays for uniform file/namespace entries, key folding for nested paths
- **Scripting escape hatch**: document that the subagent can generate and run disposable PHP scripts using `Toon::encode()` / `Toon::decode()` for targeted updates. Example patterns:
  ```php
  // Read existing index, modify in PHP, write back as TOON
  $data = Toon::decode(file_get_contents('src/Domain/ai-index.toon'));
  $data['files'][] = ['file' => 'NewFile.php', 'type' => 'class', 'responsibility' => '...', 'docs' => 'docs/NewFile.md'];
  file_put_contents('src/Domain/ai-index.toon', Toon::encode($data));
  ```
  This is simpler and more reliable than hand-writing TOON for partial updates.
- This skill gets added to index-maintainer's `skills:` frontmatter

**1.3 Update index-maintainer subagent** (`.agents/index-maintainer.md`)
- Add `toon` to `skills:` list
- Change output: write `ai-index.toon` instead of `ai-index.json`
- Keep JSON validation as fallback, add TOON validation step
- Remove JSON-specific instructions, replace with TOON examples

**1.4 Update index-maintainer skill** (`.agents/skills/index-maintainer/SKILL.md`)
- Change schema examples from JSON to TOON
- Update file references: `ai-index.toon` instead of `ai-index.json`
- Update validation checklist

### Phase 2: Conversion Script

**2.1 Create `scripts/convert-index-to-toon.php`**
- Scan all `ai-index.json` files under `src/` and root
- For each: `json_decode(file) → HelgeSverre\Toon\encode() → write ai-index.toon`
- Print summary: X files converted, Y bytes saved
- Exit 1 on any conversion error

**2.2 Create `scripts/validate-index-toon.php`**
- **Optional argument**: specific file or directory path — validates only that target
  - `php scripts/validate-index-toon.php` — validate all
  - `php scripts/validate-index-toon.php src/Domain/ai-index.toon` — validate one file
  - `php scripts/validate-index-toon.php src/Domain/` — validate all `.toon` in directory
- For each file: decode TOON → verify it round-trips (decode → encode → compare structure)
- Check: all referenced `docs/*.md` files exist
- Check: all referenced sub-namespace `index` paths exist
- Print per-file errors with clear context
- Exit 1 if any file fails
- Model uses targeted validation after edits: `php scripts/validate-index-toon.php src/Domain/ai-index.toon`

### Phase 3: Castor Integration

**3.1 `castor dev:index-toon`**
- Runs `scripts/convert-index-to-toon.php`
- Then runs `scripts/validate-index-toon.php`
- Reports results

**3.2 `castor dev:index-validate [path]`**
- Runs validation script with optional path argument
- `castor dev:index-validate` — validate all `.toon` files
- `castor dev:index-validate src/Domain/ai-index.toon` — validate specific file
- Model uses this for fast feedback after targeted edits
- Returns structured output (parseable by model) with per-file status

### Phase 4: Reading Policy Updates

**4.1 Update AGENTS.md**
- Change all references from `ai-index.json` to `ai-index.toon`
- Update reading policy: agents read `.toon` files
- Note: JSON files can be kept during transition or removed

**4.2 Update pi extension** (`.pi/extensions/index-maintainer.ts`)
- Change file scanning to look for `.toon` files instead of `.json` when detecting existing indexes
- No other logic changes needed (git diff still works the same)

### Phase 5: Cleanup (after validation)

- Remove all `ai-index.json` files
- Remove JSON validation from subagent
- Update root `ai-index.toon` as the single entry point

## File changes summary

| Action | Path |
|--------|------|
| Create | `.agents/skills/toon/SKILL.md` |
| Create | `scripts/convert-index-to-toon.php` |
| Create | `scripts/validate-index-toon.php` |
| Modify | `.agents/index-maintainer.md` (skills list, output format) |
| Modify | `.agents/skills/index-maintainer/SKILL.md` (schema examples) |
| Modify | `AGENTS.md` (reading policy) |
| Modify | `.pi/extensions/index-maintainer.ts` (file extension refs) |
| Create | Castor tasks in `castor.php` |
| Delete | All `ai-index.json` files (Phase 5) |
| Create | All `ai-index.toon` files (via conversion script) |

## Example: Root index in TOON

```
spec: agent-core.ai-docs/v1
package: ineersa/agent-core
updatedAt: 2026-04-18
description: Agent loop engine — event-sourced run lifecycle, LLM orchestration, tool execution, and hook system for Symfony.
rootFile: src/AgentLoopBundle.php
config:
  services: config/services.php
  messenger: config/messenger.php
  doctrine: config/doctrine.php
namespaces[7]{namespace,fqcn,path,description,index}:
  DependencyInjection,Ineersa\AgentCore\DependencyInjection,src/DependencyInjection,Bundle extension loading config validation framework config prepend,src/DependencyInjection/ai-index.toon
  Contract,Ineersa\AgentCore\Contract,src/Contract,Stable interfaces for runner API storage abstractions tools hooks and extensions,src/Contract/ai-index.toon
  Domain,Ineersa\AgentCore\Domain,src/Domain,Framework-agnostic core models run state commands events message envelopes tool DTOs,src/Domain/ai-index.toon
  Application,Ineersa\AgentCore\Application,src/Application,Runtime coordination and flow orchestrator reducer command router effect dispatchers,src/Application/ai-index.toon
  Infrastructure,Ineersa\AgentCore\Infrastructure,src/Infrastructure,Concrete adapters integrations Doctrine Mercure Messenger storage Symfony AI bridge,src/Infrastructure/ai-index.toon
  Api,Ineersa\AgentCore\Api,src/Api,Public transport-facing API contracts controllers DTOs planned for later stages,src/Api/ai-index.toon
  Command,Ineersa\AgentCore\Command,src/Command,Console operational commands agent-loop health etc,src/Command/ai-index.toon
```

## Risks & mitigations

| Risk | Mitigation |
|------|------------|
| Model produces invalid TOON | Validation script catches errors; agent re-runs with feedback |
| TOON library bugs | Keep JSON as backup until validated; report upstream |
| Agent confused by format change | TOON skill + examples in subagent prompt; reading is reliable |
| Long descriptions with commas | Use pipe delimiter `\|` for tabular arrays if needed |
