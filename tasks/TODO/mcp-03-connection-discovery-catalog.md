# MCP-03 Connection manager, discovery, and session catalog

## Goal
Implement broker-owned MCP connections and tool discovery from `.pi/plans/mcp-client-implementation-plan.md`.

Goal: the single MCP broker owns SDK clients, connects to configured STDIO/HTTP servers, lists tools, and writes a session-scoped MCP catalog.

Scope:
- Implement `McpConnectionManager` or equivalent in the broker process.
- Maintain one SDK client per `(runId, serverName)`.
- STDIO: connect through SDK `StdioTransport`, list tools, keep client alive for the session.
- HTTP: connect through SDK `HttpTransport`, list tools; broker HTTP too in v1 for uniformity.
- Implement session-scoped catalog storage, e.g. `.hatfield/sessions/<runId>/mcp-tools.json` or a DB-backed store if chosen.
- Catalog maps Hatfield tool names to `(serverName, mcpToolName)` and stores descriptions/input schemas/status.
- Failed server discovery should not kill the session; mark server failed and omit its tools.

Depends on: MCP-01, MCP-02.

## Acceptance criteria
- A STDIO fixture/test MCP server can be connected and listed by the broker.
- An HTTP fixture/test MCP server can be connected and listed by the broker.
- A session-scoped MCP catalog is written with namespaced tool names and schemas.
- Failed MCP server discovery is logged/recorded and does not fail the whole run/session.
- The broker owns STDIO clients; normal tool workers do not start STDIO MCP servers.
- Castor tests/validation cover connection and catalog behavior.

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
- Created: 2026-06-12T18:06:33.823Z
