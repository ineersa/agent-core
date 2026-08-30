# Hatfield

Hatfield is a local coding agent for the terminal: tools, sessions, human approvals,
MCP servers, subagents, and a public extension API — packaged as a modular Symfony CLI
monolith (no web server).

## Install

Default install path is `~/.local/bin` (ensure it is on `PATH`):

```bash
export PATH="$HOME/.local/bin:$PATH"
```

```bash
# PHAR (system PHP ≥ 8.5 + extensions; see docs/phar-packaging.md)
curl --proto '=https' --tlsv1.2 -fsSL   https://raw.githubusercontent.com/ineersa/agent-core/main/installer/bash-installer   | bash -s --

# Native static binary (no system PHP)
curl --proto '=https' --tlsv1.2 -fsSL   https://raw.githubusercontent.com/ineersa/agent-core/main/installer/bash-installer   | bash -s -- --static

# Pin a release (optional; omit for latest)
# ... | bash -s -- --version=vX.Y.Z
# ... | bash -s -- --static --version=vX.Y.Z
```

Release assets: `hatfield.phar`, `hatfield.linux-amd64`, `hatfield.linux-arm64`,
`hatfield.darwin-amd64`, `hatfield.darwin-arm64`, and `SHA256SUMS`.

- Installer, checksums, upgrades: [`docs/distribution.md`](docs/distribution.md)
- System PHAR requirements: [`docs/phar-packaging.md`](docs/phar-packaging.md)
- Static/native builds: [`docs/static-packaging.md`](docs/static-packaging.md)

```bash
hatfield --version
hatfield agent --help
hatfield agent                 # interactive TUI in the current directory
```

## First-run model setup

Built-in defaults ship **no** live provider/model — configure sparse `ai:` overrides before useful runs.
Secrets belong in `~/.hatfield/settings.yaml` (or env vars via `env:NAME`), not committed project files.

```yaml
# ~/.hatfield/settings.yaml (illustrative — use your real provider)
ai:
  default_model: openai/gpt-4.1
  default_reasoning: medium
  providers:
    openai:
      type: generic
      enabled: true
      base_url: https://api.openai.com
      api: openai-completions
      api_key: env:OPENAI_API_KEY
      supports_completions: true
      models:
        gpt-4.1:
          name: GPT-4.1
          context_window: 1047576
          max_tokens: 32768
          input: [text]
          tool_calling: true
          reasoning: false
```

Every selectable model must be listed under its provider. Full field reference:
[`docs/settings-models.md`](docs/settings-models.md). Codex OAuth helper (when used):
`hatfield auth:codex`.

## Features

- Interactive TUI agent sessions with replayable event storage
- Built-in tools (bash, files, settings, docs) plus MCP server tools
- Human input (`ask_human`) and tool approvals (SafeGuard / extensions)
- Named agent definitions and foreground `subagent` / `agent_retrieve`
- Prompt templates, skills, context compaction (`/compact`)
- Project extensions via `ineersa/hatfield-extension-api`
- PHAR and fused static binaries with the same packaged resources

## Settings

Precedence (later wins):

1. Built-in defaults (`config/hatfield.defaults.yaml` inside the install)
2. `~/.hatfield/settings.yaml`
3. Project `.hatfield/settings.yaml`

Sparse overrides only — do not copy the full defaults file. Details:
[`docs/settings.md`](docs/settings.md), models in [`docs/settings-models.md`](docs/settings-models.md),
agents/prompts/extensions in [`docs/settings-agents.md`](docs/settings-agents.md).

Session identity and storage: [`docs/session-storage.md`](docs/session-storage.md).

## Layout

```text
src/AgentCore/     Core loop, domain, contracts, storage
src/CodingAgent/   HTTP-less Symfony CLI app, tools, runtime boundary
src/Tui/           Terminal UI
src/Platform/      Provider bridges (e.g. Codex)
config/            YAML config (defaults, themes, services)
docs/              Canonical documentation (selected files are model-visible)
.hatfield/         Tracked project config + extensions; runtime dirs are ignored
tests/             Mirrors src modules
castor.php         Sole QA/test/lint/package task runner
depfile.yaml       Architecture boundaries (castor deptrac)
```

Architecture boundaries are enforced by Deptrac. Public extension contracts live in
`.hatfield/extensions/extension-api/` (`Ineersa\Hatfield\ExtensionApi`).

## Development

```bash
composer install
composer install -d .hatfield/extensions   # when using project extensions

castor check                 # full QA gate (includes docs:validate)
castor test                  # unit/integration
castor test:controller-replay
castor test:tui
castor deptrac
castor phpstan
castor dead-code
castor cs-check
castor docs:validate         # built-in catalog, links, ≤25k chars
castor phar:build
castor distribution:build
```

All QA goes through Castor — do not run raw `vendor/bin/*` in normal workflow.
See `.agents/skills/testing/SKILL.md` and `tests/AGENTS.md`.

## Model-visible docs (`hatfield_docs`)

Parent agents can `list` / `read` documents marked `builtin: true` under:

- `docs/*.md` (core product docs)
- `.hatfield/extensions/extension-api/docs/*.md` (public Extension API)

Installed extension packages keep their own README/docs and are **not** auto-discovered.
Use `hatfield_docs` operation `list` for the live catalog IDs.

## Documentation index

| Topic | Doc |
|---|---|
| Settings overview | [`docs/settings.md`](docs/settings.md) |
| Models / providers | [`docs/settings-models.md`](docs/settings-models.md) |
| Agents / prompts / extensions settings | [`docs/settings-agents.md`](docs/settings-agents.md) |
| Sessions | [`docs/session-storage.md`](docs/session-storage.md) |
| Agents / subagents | [`docs/agents.md`](docs/agents.md) |
| MCP | [`docs/mcp.md`](docs/mcp.md) |
| Prompt templates | [`docs/prompt-templates.md`](docs/prompt-templates.md) |
| Compaction | [`docs/compaction.md`](docs/compaction.md) |
| Human input | [`docs/human-input.md`](docs/human-input.md) |
| Approvals / SafeGuard | [`docs/approvals.md`](docs/approvals.md) |
| Background processes | [`docs/background-processes.md`](docs/background-processes.md) |
| Extension API | [`.hatfield/extensions/extension-api/docs/`](.hatfield/extensions/extension-api/docs/) |
| Distribution | [`docs/distribution.md`](docs/distribution.md) |
| TUI architecture | [`docs/tui-architecture.md`](docs/tui-architecture.md) |
| Async runtime | [`docs/async-runtime-architecture.md`](docs/async-runtime-architecture.md) |
| Testing | [`docs/tui-testing.md`](docs/tui-testing.md), [`docs/llm-replay.md`](docs/llm-replay.md) |

## Extensions (packages)

Public contracts: [`ineersa/hatfield-extension-api`](https://packagist.org/packages/ineersa/hatfield-extension-api).

| Extension | Package |
|---|---|
| task-workflow | `ineersa/hatfield-ext-task-workflow` |
| castor-llm-mode | `ineersa/hatfield-ext-castor-llm-mode` |
| file-rewind | `ineersa/hatfield-ext-file-rewind` |
| observational-memory | `ineersa/hatfield-ext-observational-memory` |

Enable classes under `extensions.enabled` in project settings; they register at **session start**.
Package-local docs stay with each extension repository/package (for example the file-rewind README); they are not auto-merged into `hatfield_docs`.
