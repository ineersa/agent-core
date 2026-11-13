---
builtin: true
description: Agent discovery, prompt paths, skills, and extension loading settings.
---

# Agent and Prompt Settings

Behavioral details for agents and prompts live in dedicated docs. This page lists the
settings keys and discovery rules only.

- Agent runtime behavior → [agents.md](agents.md)
- Prompt templates → [prompt-templates.md](prompt-templates.md)
- Core settings → [settings.md](settings.md)
- Approvals / SafeGuard → [approvals.md](approvals.md)

## Agents (`agents:`)

| Key | Meaning | Default |
|---|---|---|
| `agents.enabled` | Master switch for agent discovery | `true` |
| `agents.paths` | Extra definition files/dirs (highest precedence) | `[]` |
| `agents.subagent_tool_timeout_seconds` | Deferred-batch deadline for foreground subagent tool | `86400` (min `60`) |
| `agents.subagent_excluded_tools` | Tool names always removed from child runs | `hatfield_docs` |
| `agents.extensions.always_on` | Extension classes always loaded for children | includes SafeGuard |

### Discovery order (low → high)

1. `~/.agents/*.md`
2. `<cwd>/.agents/*.md`
3. `~/.hatfield/agents/*.md`
4. `<cwd>/.hatfield/agents/*.md`
5. `agents.paths` entries (file or directory)

Directories are scanned **non-recursively** for `*.md`. Later layers override earlier definitions by name.
Bundled starter agents install via `hatfield agents:init` into `~/.hatfield/agents/`.

Child extensions: effective allowlist = `agents.extensions.always_on` ∪ optional frontmatter `extensions` on the agent definition. Omitted frontmatter extensions means **no** optional child extensions (does not inherit global `extensions.enabled`).

## Forks (`forks:`)

| Key | Meaning |
|---|---|
| `forks.model` | Fallback model when the `fork` tool omits `model`; if unset, parent model |
| `forks.thinking_level` | Fallback thinking when the tool omits `thinking`; if unset, parent reasoning |
| `forks.extensions.always_on` | Extension classes always loaded for fork children |
| `forks.extensions.enabled` | Additional optional extension classes for forks only |

Fork effective extensions = `forks.extensions.always_on` ∪ `forks.extensions.enabled`. This is **separate** from agent-definition frontmatter extensions and from parent `extensions.enabled`. Behavioral detail: [agents.md](agents.md).

## Prompts (`prompts:`)

Top-level `prompts:` is a list of additional template files or directories (see [prompt-templates.md](prompt-templates.md)).

Auto-discovery (before settings paths):

1. `~/.hatfield/prompts/*.md`
2. `<cwd>/.hatfield/prompts/*.md`

Repeatable CLI `--prompt-template` paths are scanned last. The first duplicate
name wins, so these do not override an auto-discovered name. Use
`--no-prompt-templates` with explicit CLI paths to suppress the earlier sources.

## Skills

Skill discovery is controlled by CLI/runtime flags and registered package skill directories.
Built-in skills ship under the install tree (`src/CodingAgent/Resources/skills`) and are
**materialized into `~/.hatfield/skills/` even when `--no-skills` is set** (so later sessions stay current);
`--no-skills` only disables auto-discovery scanning of project/user/extension skill dirs for the current run.
CLI `--skills-path` entries are always scanned. Extensions may `registerSkill()` for package-local skills (lowest precedence when auto-discovery is on).

Behavioral detail (discoverable vs on-demand-only, `/skill:<name>`, frontmatter): [skills.md](skills.md).

## Extensions (`extensions:`)

| Key | Meaning |
|---|---|
| `extensions.enabled` | List of fully-qualified extension classes to load for the parent session |
| `extensions.settings.<key>` | Opaque settings bag returned by `ExtensionApiInterface::getSettings()` |

Extensions load from project `.hatfield/extensions/vendor/autoload.php` when present.
Registrations are process-local and load on console command startup, including
workers. Restart Hatfield after changing extension code or enablement; `/new`
inside an already running host does not reload PHP classes.

Keep `.hatfield/extensions/composer.json` with `config.prepend-autoloader: false`.
Otherwise the extensions autoloader can shadow host packages such as `symfony/tui`.

Built-in SafeGuard and Scheduling need no Composer package; use
`hatfield extensions:setup` to select enablement. SafeGuard is in child always-on
defaults; Scheduling is optional and disabled by default.
Project packages (task-workflow, observational-memory) document setup and settings
in **package-local README files** shipped with each extension repository/package.
For task workflow, read the installed `ineersa/hatfield-ext-task-workflow` package's
`README.md`. Extension-specific settings are **not** core `hatfield_docs` catalog entries.

## MCP

MCP is configured in JSON files, not the main settings YAML — see [mcp.md](mcp.md).
