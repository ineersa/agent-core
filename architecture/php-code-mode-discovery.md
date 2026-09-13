# PHP code mode (superseded discovery)

[Architecture map](README.md) · [Tools](../docs/tools.md)

This file is a historical discovery note. It is not the current design.

Hatfield now ships a direct `code_mode` tool: an owned PHP subprocess with a
minimal bootstrap and a synchronous `tool(name, arguments)` bridge into the
existing toolbox. Behavior, limits, trust model, and packaging requirements live
in [tools.md](../docs/tools.md) and [settings.md](../docs/settings.md)
(`tools.code_mode.enabled`).

Earlier drafts explored child-run / LLM-response adapters and heavier isolation.
Those proposals are obsolete. Do not implement from this document.
