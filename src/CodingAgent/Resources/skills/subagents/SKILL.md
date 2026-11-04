---
name: subagents
description: "Use for child-agent delegation or agent-definition configuration."
---

# Subagents

- Follow the `subagent`, `agent_resume`, and `agent_retrieve` tool contracts; reuse relevant child context while it remains eligible. Wait for running children rather than duplicating them. After an explicit parent-lifetime or context-budget resume refusal, launch a replacement with the relevant handoff.
- Each `subagent` or `fork` call launches one child; each `agent_resume` call continues one child. Submit independent tool calls together for concurrent execution. None accepts a tasks array.
- Keep delegation bounded and preserve worktree ownership. Serialize writers in one worktree.
- Before changing agent frontmatter, MCP selectors, child tools, extensions, skills, or timeouts, read [frontmatter](references/frontmatter.md).
- Do not assume children inherit optional extensions or parent skills; configure required ones explicitly.
- Use the runtime catalog for discovered agent names. Remove an agent by removing its definition, not with an unsupported `disabled` field.
