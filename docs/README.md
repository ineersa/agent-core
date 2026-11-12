# Documentation

## Use and configure Hatfield

| Task | Guide |
|---|---|
| Install or upgrade | [Installation](installation.md) |
| Use the terminal, paste attachments, navigate or cancel | [Terminal usage](terminal-usage.md) |
| Change settings or discover effective configuration | [Settings](settings.md) |
| Connect providers and select models | [Model settings](settings-models.md), [provider catalog](ai-catalog.md) |
| Define agents, tools, skills or prompts | [Agent settings](settings-agents.md), [agents](agents.md), [skills](skills.md), [prompt templates](prompt-templates.md) |
| Choose tools or enable PHP orchestration | [Tools and code mode](tools.md) |
| Run background or recurring work | [Background work and schedules](background-processes.md) |
| Connect MCP servers | [MCP](mcp.md) |
| Handle approvals and questions | [Approvals](approvals.md), [human input](human-input.md) |
| Resume, select history or repair | [Sessions](session-storage.md) |
| Reduce model context | [Compaction](compaction.md) |
| Build an extension | [Public Extension API](../.hatfield/extensions/extension-api/docs/extension-api.md) |

The model's `hatfield_docs` tool exposes selected operational guides and the public
Extension API. Use `list` to discover IDs and `read` with an ID to load one. It does
not browse arbitrary files or automatically include optional-package READMEs.
Catalog selection uses `builtin: true` frontmatter; architecture and developer
references are intentionally not selected.

## Develop and operate the implementation

- [Architecture index](../architecture/README.md): ownership, queues, cooperative
  execution, state, code mode, scheduling and diagrams.
- [TUI testing](tui-testing.md), [LLM replay](llm-replay.md),
  [temporary test artifacts](test-artifacts.md).
- [Distribution](distribution.md), [PHAR](phar-packaging.md),
  [native packaging](static-packaging.md), [static build tools](../tools/static/README.md).
- [Local Datadog setup](datadog.md); [logging architecture](../architecture/logging.md).

Repository validation uses Castor. `castor docs:validate` checks the selected
catalog, package-safe links and maintained-document size limits; it is not a
Mermaid renderer or a factual-accuracy check.
