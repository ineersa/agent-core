# Agent Definitions

Agent definitions configure specialized child agents (`scout`, `reviewer`, `researcher`, `worker`, and custom agents). Each definition lives in a Markdown file with YAML frontmatter.

**This is a discovery/catalog feature only.** Agent launch, runtime execution, TUI controls, artifacts, and background/foreground orchestration are NOT implemented yet. This document covers the definition format, discovery, and catalog.

## File format

Agent definitions use Markdown with YAML frontmatter:

```markdown
---
name: scout
description: Fast read-only codebase reconnaissance
tools:
  - read
  - ide_find_file
  - ide_search_text
mcp:
  mode: none
inheritProjectContext: true
inheritAgentsMd: true
systemPromptMode: replace
maxDepth: 1
backgroundAllowed: true
foregroundAllowed: true
parallelAllowed: true
---

You are a scout. Explore the codebase read-only and return dense findings...
```

## Fields

| Field | Type | Required | Default | Description |
|---|---|---|---|---|
| `name` | string | yes | — | Unique agent name. Lowercase `[a-z][a-z0-9-]{0,47}`. |
| `description` | string | yes | — | Human-readable description. |
| `tools` | list\<string\> | yes | — | Explicit tool allowlist. |
| `mcp.mode` | enum | no | `none` | MCP tool policy: `none`, `all`, or `specific`. |
| `mcp.tools` | list\<string\> | no | `[]` | Allowed MCP tools when mode is `specific`. |
| `model` | string\|null | no | `null` | Optional model override. |
| `thinking` | string\|null | no | `null` | Reasoning/thinking override (`off`, `minimal`, `low`, `medium`, `high`, `xhigh`). |
| `skills` | list\<string\> | no | `[]` | Setup skills loaded from start. |
| `inheritProjectContext` | bool | no | `true` | Include project context in child system prompt. |
| `inheritAgentsMd` | bool | no | `true` | Include `AGENTS.md` in child system prompt. |
| `systemPromptMode` | enum | no | `replace` | How child system prompt is composed: `replace` or `append`. |
| `maxDepth` | int | no | `1` | Per-agent recursion cap (0–5). |
| `backgroundAllowed` | bool | no | `true` | Whether background launches are allowed. |
| `foregroundAllowed` | bool | no | `true` | Whether foreground launches are allowed. |
| `parallelAllowed` | bool | no | `false` | Whether parallel execution is allowed. |
| `disabled` | bool | no | `false` | Disable definition without deleting it. |
| `handoffFormat` | string\|null | no | `null` | Optional named handoff template. |

**There is no `type` field.** The `type` field was intentionally removed. It is treated as an unknown field and rejected during parsing.

The body after the closing `---` delimiter is stored as the agent's instructions.

## Discovery

Agent definitions are discovered from the following locations in deterministic order. Higher-numbered layers override lower-numbered layers by agent `name`.

### Precedence (highest wins)

1. **Built-in agents** bundled with Hatfield — `config/agents/*.md`
2. **User agents** — `~/.hatfield/agents/*.md`
3. **User agents** — `~/.agents/*.md`
4. **Project agents** — `.hatfield/agents/*.md`
5. **Project agents** — `.agents/*.md`
6. **Configured paths** — `agents.paths` settings (highest precedence)

Each directory is scanned non-recursively for `*.md` files (sorted lexicographically). Configured paths may be a single `.md` file or a directory of `*.md` files.

`.agents/` is a first-class location, not a legacy fallback. It is supported alongside Hatfield-native `.hatfield/agents/`.

### Override behavior

When two definitions have the same `name`, the higher-precedence one wins. An override diagnostic is recorded with winner and loser paths. The overridden definition is not lost — it is still reachable through diagnostics for debugging, but it does not appear in the catalog.

### Disabled definitions

Definitions with `disabled: true` are still loaded into the catalog and appear in `all()` and `disabled()` lookups. They are excluded from `enabled()` and `requireEnabled()` queries. Future launch/execution infrastructure must reject disabled agents.

### Missing paths

Auto-discovery directories that do not exist are silently skipped. Explicit configured paths that do not exist produce an actionable diagnostic with the full path.

### Invalid definitions

Definitions that fail to parse or validate produce a diagnostic and do not appear in the catalog. One invalid file does not abort all discovery.

## Built-in agents

Hatfield ships with four built-in agent definitions:

| Agent | Purpose | Tools | Fields of note |
|---|---|---|---|
| `scout` | Fast read-only codebase reconnaissance | read, ide_find_file, ide_search_text, ide_file_structure, semantic-search | parallelAllowed, backgroundAllowed |
| `reviewer` | Code review and correctness analysis | read, ide_find_file, ide_search_text, ide_file_structure, ide_find_references, ide_call_hierarchy, ide_type_hierarchy, semantic-search | parallelAllowed |
| `researcher` | External research and documentation lookup | read, websearch__*, context7__* | parallelAllowed, has web/docs tools (docs use wildcard shorthand; built-in definition enumerates concrete tool IDs) |
| `worker` | General-purpose implementation | read, write, edit, bash, bg_status, ide_find_file, ide_search_text, ide_file_structure, semantic-search | parallelAllowed: false, has mutation tools |

All built-in agents:
- Have `mcp.mode: none`
- Have `maxDepth: 1`
- Allow both foreground and background execution
- Use `systemPromptMode: replace`
- Use `inheritProjectContext: true` and `inheritAgentsMd: true`
- Do **not** include a `type` field

### Adding custom agents

Create a Markdown file in any discovery location. Example:

```markdown
---
name: my-custom-agent
description: Custom agent for specialized analysis
tools:
  - read
  - ide_search_text
  - semantic-search
mcp:
  mode: none
maxDepth: 1
---

Your custom instructions here.
```

### Per-project agents

For project-specific agents, add `.md` files under `.hatfield/agents/` or `.agents/`. These override user and built-in definitions with the same name.

### User-level agents

For personal agents available across projects, add `.md` files under `~/.hatfield/agents/` or `~/.agents/`. These can be overridden by project definitions with the same name.

## Settings

```yaml
agents:
  enabled: true
  paths: []
```

- `agents.enabled` (bool, default `true`): Whether agent discovery is enabled. When `false`, discovery returns an empty catalog.
- `agents.paths` (list of strings, default `[]`): Additional explicit file or directory paths. These have the highest precedence (override all auto-discovery locations).

Paths support standard Hatfield resolution: `~` (home), `%kernel.project_dir%`, and relative paths (resolved against the project CWD).

Example:

```yaml
agents:
  paths:
    - ~/shared/agents/custom-reviewer.md
    - .hatfield/team-agents
```

## Catalog API

The catalog (`AgentDefinitionCatalog`) provides:

- `get(string $name): ?AgentDefinitionDTO` — lookup by name
- `require(string $name): AgentDefinitionDTO` — lookup, throws if missing
- `requireEnabled(string $name): AgentDefinitionDTO` — lookup, throws if missing or disabled
- `all(): list<AgentDefinitionDTO>` — all definitions including disabled
- `enabled(): list<AgentDefinitionDTO>` — enabled definitions only
- `disabled(): list<AgentDefinitionDTO>` — disabled definitions only
- `diagnostics(): list<AgentDefinitionDiagnosticDTO>` — discovery diagnostics

## See also

- [Settings](settings.md) — `agents.enabled`, `agents.paths`
- [Implementation plan](../.pi/plans/agents-subagents-implementation-plan.md)
