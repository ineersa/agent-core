# Startup "loaded resources" Block — Investigation Report

**Date**: 2026-06-26
**Scope**: TUI startup block showing what is actually loaded into a conversation (context / skills / prompts / themes / agents / extensions), with conflict surfacing
**Purpose**: Reference material for GitHub issue "Show loaded-resources block on TUI startup (like Pi)". This report holds the file/line evidence; the issue stays short.

---

## 1. Goal

On opening Hatfield, show a block of what will actually be loaded into the
conversation — analogous to Pi's startup block:

```
[Context]    AGENTS.md paths loaded
[Skills]     skills loaded
[Prompts]    prompt templates
[Themes]     themes
[Agents]     agent definitions
[Extensions] extensions
```

Plus conflict surfacing: when two sources map to the same name, show the
conflict and which skill/agent/etc from which file actually won.

Styling: use **theme colors** to style the blocks, dimmed body text, `warning`
color for conflicts.

## 2. Reference implementation (Pi)

Pi renders exactly this block in
`pi-mono/packages/coding-agent/src/modes/interactive/interactive-mode.ts`,
method `showLoadedResources()` (starts ~line **1341**).

Key mechanics worth mirroring:
- `sectionHeader(name, color)` → `theme.fg(color, "[${name}]")`, default color `mdHeading`.
- `formatCompactList(items)` → `theme.fg("dim", "  " + sorted items joined by ", ")`.
- `addLoadedSection(name, collapsedBody, expandedBody, color)` wraps each section in an **`ExpandableText`** widget (collapsed compact list / expanded per-path list). Adds a `Spacer(1)` after.
- Sections emitted, in order: **Context → Skills → Prompts → Extensions → Themes**. (Pi has no "Agents" section — Hatfield should add one.)
- `buildScopeGroups()` + `formatScopeGroups()` group resources by **source scope** (builtin / package / user) for the expanded view, using `sourceInfo` / `sourcePath` for provenance.
- Conflict diagnostics are rendered separately (not inline) under `[Skill conflicts]` / `[Prompt conflicts]` headers, in `theme.fg("warning", ...)`.
- Gated by `quietStartup` setting + `verbose` flag: compact when quiet, expanded when verbose/forced.

Data sources used by Pi (all already loaded at startup via `resourceLoader`):
`getAgentsFiles()`, `getSkills()` (skills + diagnostics), `getPrompts()` (prompts + diagnostics), `getExtensions()` (extensions + errors), `getThemes()` (themes + diagnostics).

## 3. What Hatfield already has (loaders + diagnostics)

Hatfield already discovers **all six** categories and already tracks
collisions/diagnostics for most. There is **no aggregated startup block** today.

### 3.1 Context (AGENTS.md)
- `src/CodingAgent/SystemPrompt/AgentsContextDiscovery.php` — discovers `AGENTS.md`/`AGENTS.MD` from global dirs + ancestor walk (`.hatfield` then `.agents`). Dedupes by realpath. Nearest ancestor wins. **No collision diagnostic** (nearest-wins is deterministic, not a conflict surface).

### 3.2 Skills
- `src/CodingAgent/Skills/SkillDiscovery.php`
  - `discover()`: search paths = CLI `--skills-path` (highest) then auto-discovery `{cwd}/.hatfield/skills`, `{cwd}/.agents/skills`, then home equivalents.
  - **First-discovered wins** on name collision.
  - **`getCollisions()`** (line ~144): returns `list<array{winner: string, ignored: string, name: string}>`. ✅ conflict data already exists.
  - `SkillDefinition` (name, description, skillFile, skillDirectory, model-invocable flag).
- `src/CodingAgent/Skills/SkillsContextBuilder.php` — orchestrates discovery + registry + rendering for the LLM `<skills_instructions>` block.
- `src/CodingAgent/Skills/SkillContextRenderer.php` — renders `<skills_instructions>` + `<skill>` blocks for the LLM (NOT the TUI block we want).
- `src/CodingAgent/Skills/SkillRegistry.php`, `SkillsConfig.php`.

### 3.3 Prompt templates
- `src/CodingAgent/PromptTemplate/PromptTemplateLoader.php` — first lowercase name wins; later dupes → collision diagnostic. Explicit missing paths → `invalid_path`.
- `src/CodingAgent/PromptTemplate/PromptTemplateDiagnostic.php` — DTO: `type` (`collision|read_error|yaml_error|invalid_path`), `message`, `name`, `winnerPath`, `loserPath`. ✅ conflict data.
- `PromptTemplateLoadResult.php`, `LoadedPromptTemplate.php`, `PromptTemplateService.php`, `PromptTemplatesRuntimeConfig.php`.

### 3.4 Agents
- `src/CodingAgent/Agent/Definition/AgentDefinitionDiscovery.php`, `AgentDefinitionCatalog.php` (enabled agents), `AgentDefinitionDTO.php`, `AgentDefinitionParser.php`.
- `src/CodingAgent/Agent/Definition/AgentDefinitionDiagnosticDTO.php` — `type` (`collision|invalid_definition|invalid_path|missing_path`), `message`, `path`, `name`, `winnerPath`, `loserPath`. ✅ conflict data.
- `src/CodingAgent/Agent/Context/AgentsContextBuilder.php` + `AgentContextRenderer.php` — render `<agents>` LLM block (foreground-launchable agents only).

### 3.5 Themes
- `src/Tui/Theme/ThemeRegistry.php` (line ~183 has a path-for-diagnostics comment), `ThemeLoader.php`, `ThemePalette.php`, `ThemeColorEnum.php`, `DefaultTheme.php`, `TuiTheme.php`.
- Custom themes from `config/themes/` (YAML). Built-in themes excluded from "loaded" listing in Pi.
- `ThemeColorEnum` is the source of truth for colors (`mdHeading`, `dim`, `warning`, etc.) — reuse for styling.

### 3.6 Extensions
- `src/CodingAgent/Extension/ExtensionManager.php`, `ExtensionLoaderSubscriber.php`, `ExtensionToolRegistryBridge.php`, `ExtensionHookRegistry.php`.
- `src/CodingAgent/ExtensionApi/HatfieldExtensionInterface.php`.
- (Need to confirm whether a per-extension "conflict/duplicate" diagnostic exists; Pi surfaces `getExtensions().errors`.)

## 4. Where it should hook in (TUI startup)

- `src/CodingAgent/CLI/AgentCommand.php` wires TUI mode through
  `Ineersa\Tui\Application\InteractiveMode`.
- `src/Tui/Application/InteractiveMode.php` — the TUI run loop (Pi analog: `interactive-mode.ts`). This is where `showLoadedResources()` should be invoked after initial setup, before the first run.
- `src/Tui/Application/SessionInitializer.php` — handles new/resume session init; the loaded-resources block is independent of session resume (resources are process-global) so it belongs in `InteractiveMode` startup, gated by a `quietStartup`-style setting.

Open question: does Hatfield have a `quietStartup` setting today? If not, the
block can be always-on (collapsed) with expand-on-key.

## 5. Gap summary (what needs building)

1. **A renderer** (TUI layer) that takes the six sources and emits styled,
   dimmed, expandable sections using `ThemeColorEnum` — Pi's
   `showLoadedResources()` + `addLoadedSection()` + `formatCompactList()` is
   the model. Needs an `ExpandableText`-equivalent widget (check
   `src/Tui/Transcript/TranscriptBlockWidget.php` / `TranscriptBlockFactory.php`
   for an existing expandable block mechanism).
2. **An aggregator** that collects, for each category: the loaded list (name +
   source/path + scope) AND its diagnostics/collisions. Hatfield has the data;
   it's spread across the builders above and currently consumed only for the
   **LLM** context, not the **TUI**.
3. **Conflict surfacing**: a section/line per category showing winner vs loser
   paths, styled with `warning` color. Skill/Prompt/Agent diagnostics already
   carry winner/loser paths; Context is nearest-wins (no conflict); Themes/Ext
   need a check.
4. **A setting** (optional) — `quietStartup` equivalent to gate collapsed vs
   expanded; verify against `docs/settings.md`.

## 6. Architecture / boundary notes

- The aggregator reads from `CodingAgent` services. Per `AGENTS.md`, **TUI must
  not depend on `CodingAgent` internals** — it may only talk to runtime via
  `Runtime/Contract`, `Protocol`, and `AgentSessionClient`.
  - ⇒ The clean path is for `CodingAgent` to expose a **runtime contract** /
    DTO (e.g. a `LoadedResourcesSummary`) that the TUI renders. Avoid pulling
    the discovery services directly into `src/Tui/`.
  - Alternatively the summary is built in `AgentCommand`/`InteractiveMode`
    wiring and passed in. Confirm the preferred seam during task-start.
- Renderer belongs in `src/Tui/` and must use `ThemeColorEnum`/`ThemePalette`
  for all coloring (no hard-coded ANSI).
- Deptrac will enforce the above; verify with `castor deptrac`.

## 7. Test plan (per AGENTS.md TUI pyramid)

- **Virtual / in-process** (`castor test`): render the block from a fixed
  summary DTO, assert section headers, dim body, warning conflict lines, and
  collapse/expand — using `VirtualTuiHarness` / `ScreenBuffer` under
  `tests/Tui/Screen/`. No live controller needed.
- **Contract**: aggregator produces correct winner/loser for a planted skill
  collision, prompt collision, agent collision (each loader's diagnostic path).
- **Minimal tmux** (`castor test:tui`, `#[Group('tui-e2e-replay')]`) only if the
  block needs real terminal/pty behavior (likely not — pure render).
- No new `llm-real` lane needed (no LLM-visible change). But double check the
  block is **display-only** and not accidentally injected into the system/user
  prompt (the existing `*ContextBuilder`s feed the LLM; the new TUI renderer
  must NOT reuse those outputs for display, only for data).

## 8. Files to reference during implementation

Pi reference:
- `pi-mono/packages/coding-agent/src/modes/interactive/interactive-mode.ts` (`showLoadedResources`, `addLoadedSection`, `formatCompactList`, `buildScopeGroups`, `formatScopeGroups`, `formatDiagnostics`).

Hatfield loaders/diagnostics:
- `src/CodingAgent/SystemPrompt/AgentsContextDiscovery.php`
- `src/CodingAgent/Skills/SkillDiscovery.php` (+ `getCollisions`)
- `src/CodingAgent/Skills/SkillDefinition.php`, `SkillRegistry.php`, `SkillsConfig.php`
- `src/CodingAgent/PromptTemplate/PromptTemplateLoader.php`, `PromptTemplateDiagnostic.php`, `PromptTemplateLoadResult.php`
- `src/CodingAgent/Agent/Definition/AgentDefinitionDiscovery.php`, `AgentDefinitionCatalog.php`, `AgentDefinitionDiagnosticDTO.php`
- `src/CodingAgent/Agent/Context/AgentsContextBuilder.php`
- `src/CodingAgent/Extension/ExtensionManager.php`, `ExtensionLoaderSubscriber.php`
- `src/Tui/Theme/ThemeRegistry.php`, `ThemeColorEnum.php`, `ThemePalette.php`
- `src/Tui/Application/InteractiveMode.php`, `SessionInitializer.php`
- `src/CodingAgent/CLI/AgentCommand.php`
