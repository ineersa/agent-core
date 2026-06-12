# MCP-01 Config loader and SDK boundary

## Goal
Implement phase 0 of `.pi/plans/mcp-client-implementation-plan.md`.

Goal: introduce the MCP client dependency boundary and configuration loading without wiring runtime execution yet.

Scope:
- Add/use `mcp/sdk` behind Hatfield-owned adapter interfaces/classes only.
- Add config loading for `~/.hatfield/mcp.json` and project `.hatfield/mcp.json`.
- Support `mcpServers` definitions for STDIO (`command`, `args`, `env`, `cwd`) and HTTP (`url`, `headers`).
- Support `enabled`, `timeoutMs`, `startupTimeoutMs`, `excludeTools`.
- Merge global < project definitions.
- Validate exactly one of `command` or `url` unless a project override only disables an inherited server.
- Interpolate env vars in `env` and `headers`, failing clearly if missing.
- Keep SDK types isolated under `src/CodingAgent/Mcp/Client` or equivalent; do not leak SDK types into AgentCore, TUI, or ExtensionApi.

Non-goals:
- No broker consumer yet.
- No dynamic tool registration yet.
- No MCP tool invocation yet.
- No OAuth.

## Acceptance criteria
- Config tests cover empty config, global config, project override, disabling inherited server, invalid command+url, invalid missing transport, env/header interpolation, and missing env var failure.
- A typed MCP server definition model exists for STDIO and HTTP servers.
- SDK usage is wrapped behind Hatfield-owned interfaces/adapters and does not leak into AgentCore, Tui, or ExtensionApi.
- Documentation or inline config examples reference `.pi/plans/mcp-client-implementation-plan.md` as the design source.
- Relevant validation is run through Castor, not raw vendor/bin commands.

## Workflow metadata
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-06-12T18:06:16.421Z
