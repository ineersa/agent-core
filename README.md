# Hatfield

Hatfield is a coding agent that works in your terminal. Ask it to explain a codebase,
fix a bug, or implement a change. It can read and edit files, run commands, and use
tools from your MCP servers while you follow its progress in the conversation.

![Hatfield explaining a project's entry point in the terminal](docs/assets/demo.gif)

## Contents

- [Install](#install)
- [Start your first session](#start-your-first-session)
- [What you can do](#what-you-can-do)
- [Make it fit your workflow](#make-it-fit-your-workflow)
- [Documentation map](#documentation-map)
- [License](#license)

## Install

Hatfield supports Linux and macOS on x86_64 and ARM64. Windows is not supported.
The native binary includes PHP. Install it into `~/.local/bin`:

```bash
curl --proto '=https' --tlsv1.2 -fsSL \
	https://raw.githubusercontent.com/ineersa/agent-core/main/installer/bash-installer \
	| bash -s -- --static
```

Add that directory to your shell's `PATH` if it is not already there:

```bash
export PATH="$HOME/.local/bin:$PATH"
hatfield --version
```

The installer checks the release checksum and tests the executable before replacing
an existing installation. Run it again to upgrade. Add `--version=vX.Y.Z` to select
a release.

If you already have PHP 8.5 or later with the
[required extensions](docs/phar-packaging.md), install the PHAR instead:

```bash
curl --proto '=https' --tlsv1.2 -fsSL \
	https://raw.githubusercontent.com/ineersa/agent-core/main/installer/bash-installer \
	| bash -s --
```

See [installation and troubleshooting](docs/installation.md) for platform details,
checksums, and custom install locations.

## Start your first session

Hatfield includes provider definitions but leaves them disabled. Open the provider
setup screen to enable a provider and configure credentials:

```bash
hatfield providers:setup
```

Then start Hatfield in your project:

```bash
cd /path/to/your/project
hatfield agent
```

For command-line options, run `hatfield agent --help`.
To see a list of available commands, run `hatfield list`.

To update your provider catalog after an upgrade or refresh model metadata, run:

```bash
hatfield providers:update
```

This rebases the catalog onto the bundled definitions and refreshes metadata for
known models. It does not automatically enable providers or add new models discovered
upstream. See the [provider catalog](docs/ai-catalog.md) for update behavior.

### Install optional agent definitions

Agent definitions are opt-in. Install them into `~/.hatfield/agents/`:

```bash
hatfield agents:init
```

After upgrading, refresh with `hatfield agents:init --force`. This overwrites
edits to bundled definitions, not other agents. Start a new session to load them.
See [agent requirements and customization](docs/agents.md).

Built-in skills such as `subagents` refresh automatically in `~/.hatfield/skills/`
at startup, replacing local edits with the running build's copies. Extension skills
follow their extension's installation and update rules instead.

### What you can do

- Start in a repository and describe the task in plain language.
- Inspect tool results and changes before you commit them. Hatfield runs commands
  with your local permissions, so treat tool access as access to your workspace.
- Return to earlier work with `/resume`, name sessions with `/rename`, and start
  fresh with `/new`.
- Switch models with `/model`. Use `/help` for commands and `/hotkeys` for keybindings.
- Delegate research to named subagents and follow their work through `/agents-live`.
  Agent definitions and optional extensions depend on your setup.

Setup is manual, not an automatic first-run wizard. For custom or local providers,
see [model settings](docs/settings-models.md). For catalog updates and sparse
credential overrides, see the [provider catalog](docs/ai-catalog.md).
OAuth login helpers are available for OpenAI Codex and Grok CLI:

```bash
hatfield auth:codex
hatfield auth:grok
```

Use the command for your provider. Other providers can use API-key credentials
configured through provider setup or [model settings](docs/settings-models.md).

## Make it fit your workflow

Ask Hatfield to change its settings in plain language. Its `settings` tool can read,
set, or remove user and project overrides. Specify which scope you want to change,
then use `/settings-show` to check the current settings.

User settings live in `~/.hatfield/settings.yaml`. Repository settings live in
`.hatfield/settings.yaml` and override user settings. Keep overrides small rather
than copying the defaults. Put credentials in user settings or reference environment
variables with `env:NAME`.

Add [MCP servers](docs/mcp.md) for external tools, [skills](docs/skills.md) for reusable
instructions, and [prompt templates](docs/prompt-templates.md) for repeated tasks.
[Named agents](docs/agents.md) let you give a child a specific role and tool policy.

Built-in [SafeGuard](docs/approvals.md) checks tool calls and can allow, block, or
request approval before execution. It is separate from optional extension packages.

The optional `code_mode` tool can run PHP scripts that call other tools. It stays
disabled until you set `tools.code_mode.enabled: true` and restart. Raw PHP inside
those scripts bypasses toolbox hooks, and any launcher sandbox such as
`hatfield-safe` is inherited rather than added by the tool. See the
[tool catalog](docs/tools.md) and [settings](docs/settings.md).

Optional extension packages provide task workflow, file rewind, and observational
memory. Installing and enabling extensions is separate from launching the agent.
See [extension settings](docs/settings-agents.md) and the
[public Extension API](.hatfield/extensions/extension-api/docs/extension-api.md).

You can also ask Hatfield to build a project-specific extension using the public
Extension API. Ask it to place the extension under `.hatfield/extensions`, configure
Composer autoloading, and add its class to `extensions.enabled` in project settings.
Review the code before enabling it, then start a new session to load it. Extensions
can add tools and commands tailored to the repository.

## Documentation map

| You want to… | Read |
|---|---|
| Install, upgrade, or troubleshoot a release | [Installation and upgrades](docs/installation.md) |
| Use the editor, navigate sessions, or cancel work | [Terminal usage](docs/terminal-usage.md) |
| Configure Hatfield | [Settings](docs/settings.md) |
| Connect a model provider | [Provider catalog](docs/ai-catalog.md), [model settings](docs/settings-models.md) |
| Resume work or understand what is saved | [Sessions and history](docs/session-storage.md) |
| Control tool approvals | [Approvals](docs/approvals.md), [human input](docs/human-input.md) |
| See available tools and their limits | [Tool catalog](docs/tools.md) |
| Use external tools | [MCP](docs/mcp.md) |
| Delegate to another agent | [Agents, subagents, and forks](docs/agents.md) |
| Reuse task instructions | [Skills](docs/skills.md), [prompt templates](docs/prompt-templates.md) |
| Reduce a long conversation's model context | [Compaction](docs/compaction.md) |
| Manage long-running commands | [Background processes](docs/background-processes.md) |
| Build an extension | [Extension API](.hatfield/extensions/extension-api/docs/extension-api.md) |
| Understand the implementation | [Architecture diagrams](architecture/README.md), [runtime](docs/async-runtime-architecture.md), [TUI](docs/tui-architecture.md) |

The architecture guide uses Markdown with Mermaid flowcharts and sequence diagrams.
View it on GitHub or in a Markdown preview with Mermaid support.

The agent can also read the packaged product documentation through `hatfield_docs`.
Ask it to consult that catalog for configuration help. Extension package docs stay
with their packages.

## License

Hatfield is licensed under the [MIT License](LICENSE).