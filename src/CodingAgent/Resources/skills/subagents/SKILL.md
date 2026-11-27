---
name: subagents
description: "Use for child-agent delegation or agent-definition configuration."
---

# Subagents

- Follow the `subagent`, `agent_resume`, and `agent_retrieve` tool contracts; do not duplicate launches when relevant child context exists.
- Each `subagent` or `fork` call launches one child; each `agent_resume` call continues one child. Submit independent tool calls together for concurrent execution. None accepts a tasks array.
- Keep delegation bounded and preserve worktree ownership. Serialize writers in one worktree.
- For automated code review, main loads the `code-review` skill and coordinates a fresh `reviewer` and `auditor` concurrently, then one fresh `verifier`. A reviewer handoff alone is not a verified review; preserve explicit manual/skipped review choices.
- Run at most one review cycle per task/change by default (reviewer + auditor + at most one verifier). Main must explicitly ask the user and obtain approval before any additional cycle or review retry. If asking is unavailable, stop after one cycle. Fixes, new commits and incomplete reports do not authorize another cycle; children return the need for approval to main.
- Before changing agent frontmatter, MCP selectors, child tools, extensions, skills, or timeouts, read [frontmatter](references/frontmatter.md).
- Do not assume children inherit optional extensions or parent skills; configure required ones explicitly.
- Use the runtime catalog for discovered agent names. Remove an agent by removing its definition, not with an unsupported `disabled` field.
