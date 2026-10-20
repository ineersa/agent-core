---
name: subagents
description: "Use for child-agent delegation or agent-definition configuration."
---

# Subagents

- Follow the `subagent`, `agent_resume`, and `agent_retrieve` tool contracts; do not duplicate launches when relevant child context exists.
- Each `subagent` or `fork` call launches one child. Submit independent tool calls together for concurrent execution; do not use a `subagent` tasks array.
- Keep delegation bounded and preserve worktree ownership. Serialize writers in one worktree.
- Before changing agent frontmatter, MCP selectors, child tools, extensions, skills, or timeouts, read [frontmatter](references/frontmatter.md).
- Do not assume children inherit optional extensions or parent skills; configure required ones explicitly.
- Use the runtime catalog for discovered agent names. Remove an agent by removing its definition, not with an unsupported `disabled` field.
