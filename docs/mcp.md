# MCP Client Configuration

Hatfield can use tools exposed by external MCP (Model Context Protocol) servers.

This document covers configuration. For the full implementation plan, see
`.pi/plans/mcp-client-implementation-plan.md`.

## Configuration files

MCP servers are configured in standalone JSON files:

| Scope | Path | Purpose |
|---|---|---|
| Global (user) | `~/.hatfield/mcp.json` | Default MCP servers available to all projects |
| Project | `.hatfield/mcp.json` | Project-specific overrides and additions |

Both files are optional. Missing or empty files are treated as "no MCP servers configured" — no error.

## Merge behavior

Project config overrides global config by server name using **whole-server replacement**.
Fields inside a server definition are NOT deep-merged.

```text
global mcpServers < project mcpServers
```

Special case: a project server entry with only `{ "enabled": false }` disables an inherited server.

## Schema

```jsonc
{
  "mcpServers": {
    "<server-name>": {
      // Whether this server is active (default: true)
      "enabled": true,

      // ── STDIO transport ──
      "command": "npx",             // Command to run (required for STDIO)
      "args": ["-y", "@scope/mcp"], // Command arguments (optional)
      "env": {                      // Environment variables (optional)
        "API_KEY": "${MY_API_KEY}"  // ${VAR} references are interpolated
      },
      "cwd": ".",                   // Working directory relative to project cwd (optional)

      // ── HTTP transport ──
      "url": "https://example.com/mcp", // Server endpoint (required for HTTP)
      "headers": {                      // Request headers (optional)
        "Authorization": "Bearer ${MCP_TOKEN}"  // ${VAR} references are interpolated
      },

      // ── Common ──
      "timeoutMs": 30000,          // Tool-call timeout in milliseconds (default: 30000)
      "startupTimeoutMs": 30000,   // STDIO startup timeout in milliseconds (default: 30000)
      "excludeTools": ["risky_tool"] // Tools to exclude from registration (optional)
    }
  }
}
```

Each server must define exactly ONE transport:

- **STDIO:** `command` (required) + optional `args`, `env`, `cwd`
- **HTTP:** `url` (required) + optional `headers`

Defining both `command` and `url` in the same server is an error.

## Transport examples

### STDIO server

```jsonc
{
  "mcpServers": {
    "filesystem": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-filesystem", "."],
      "cwd": ".",
      "timeoutMs": 30000
    }
  }
}
```

### HTTP server with bearer token

```jsonc
{
  "mcpServers": {
    "github": {
      "url": "https://api.githubcopilot.com/mcp",
      "headers": {
        "Authorization": "Bearer ${GITHUB_MCP_TOKEN}"
      }
    }
  }
}
```

## Environment variable interpolation

`${VAR_NAME}` references in `env` and `headers` values are resolved from runtime
environment variables at config load time.

- Missing env vars → configuration error (server not available)
- Empty env vars → configuration error (sending `Bearer ` or empty API keys is almost certainly broken)
- Literal empty strings without `${...}` references are allowed unchanged

Error messages include the variable name and server/field context but never the resolved secret value.

## Project override example

Global config (`~/.hatfield/mcp.json`):

```jsonc
{
  "mcpServers": {
    "playwright": {
      "command": "npx",
      "args": ["-y", "@playwright/mcp"],
      "env": {
        "PLAYWRIGHT_BROWSERS_PATH": "/tmp"
      }
    }
  }
}
```

Project override (`.hatfield/mcp.json`):

```jsonc
{
  "mcpServers": {
    // Fully replace playwright definition — old env/args do not survive
    "playwright": {
      "command": "npx",
      "args": ["-y", "@playwright/mcp", "--headless"]
    },

    // Disable an inherited server
    "filesystem": {
      "enabled": false
    }
  }
}
```

## Phase 1 — Broker transport and consumer

Phase 1 adds the runtime messenger foundation:

- Dedicated `mcp` Messenger transport/queue for MCP lifecycle messages.
- The headless controller supervises exactly one `mcp` consumer per session.
- MCP session initialize is dispatched automatically on `start_run` and `resume`.
- Lifecycle message handlers (initialize, refresh catalog, disconnect) are registered
  on `agent.command.bus` and routed to the `mcp` transport.
- Structured logs include `component=mcp`, `run_id`, `session_id`, `mcp_event`,
  `server_name`, and `transport` fields where applicable — never raw env values,
  `server_name`, and `transport` fields where applicable — never raw env values,
  headers, tokens, or secrets.

## Phase 2 — Connection manager, discovery, and session catalog

Phase 2 (MCP-03) adds real SDK connection management and tool discovery:

- `McpConnectionManager` owns one SDK client per `(runId, serverName)` in the MCP broker process.
- STDIO servers are session-scoped keep-alive — started once per session and reused.
- On session initialize and catalog refresh: load current MCP config, connect to each
  enabled server, list tools, and atomically write the session catalog.
- **Session catalog** is written to `.hatfield/sessions/<runId>/mcp-tools.json`
  where `session_id === run_id`.  This is the source of truth for available MCP tools
  during a session.  It is NOT a cache — `app.cache` is not used as canonical catalog.
- Catalog metadata includes `generatedAt`, `generation`, and `configHash` for invalidation.
- Rediscovery is a full snapshot replacement (temp file + atomic rename).  Readers see
  either the previous complete snapshot or the next complete snapshot, never a partial file.
- Tool names are namespaced as `{server}_{tool}` with sanitization to safe identifiers.
- Failed server discovery is warning-only and recorded with diagnostic-safe error messages
  in the catalog — it must not crash the session. An empty/failed catalog snapshot is
  written on config or discovery failure to invalidate any previously-discovered tools.
- The catalog remains asynchronous: MCP-04 will handle LLM catalog synchronization and
  dynamic ToolRegistry registration.

### Session catalog path

```text
<projectCwd>/.hatfield/sessions/<runId>/mcp-tools.json
```

### Catalog shape

```jsonc
{
  "schemaVersion": 1,
  "runId": "...",
  "generatedAt": "2026-06-18T12:00:00Z",
  "generation": 1,
  "configHash": "sha256-...",
  "servers": {
    "<serverName>": {
      "serverName": "<serverName>",
      "transport": "stdio|http",
      "status": "connected|failed",
      "errorMessage": null,
      "tools": [
        {
          "hatfieldName": "server_tool",
          "serverName": "server",
          "mcpName": "tool",
          "description": "...",
          "inputSchema": { "type": "object", ... }
        }
      ]
    }
  }
}
```

### Rediscovery and invalidation

- **Triggers:** start_run, resume, and explicit McpRefreshCatalogCommand.
- **Full snapshot:** each rediscovery replaces the entire catalog file atomically.
- **Invalidation:** a new generation (with new `configHash` if config changed) replaces
  the old catalog.  Failed discovery writes an empty catalog, so stale tools from
  a previous successful discovery are never silently retained.
- **Disconnect:** McpDisconnectSessionCommand closes broker-owned SDK clients.
  The catalog file is retained as a historical/debug session artifact.
  Full lifecycle cleanup/orphan hardening remains MCP-06.

## Limitations in current phase

- No OAuth support.
- MCP tool catalog is not yet read by LLM schema resolution (MCP-04).
- No dynamic ToolRegistry registration from catalog (MCP-04).
- No broker request/reply tool invocation (MCP-05).
- HTTP transport uses PSR-18/PSR-17 discovery (explicit injection deferred).
- Deep graceful shutdown/orphan cleanup is not yet implemented (MCP-06).
- MCP config failures during initialize are warning-only — normal sessions
  continue unaffected.

These will be addressed in subsequent phases per `.pi/plans/mcp-client-implementation-plan.md`.

## SDK boundary

The official `mcp/sdk` PHP package is used behind a Hatfield-owned client boundary
under `src/CodingAgent/Mcp/Client/`. SDK types (`Mcp\*`) are isolated to that
namespace and never leak into AgentCore, TUI, or ExtensionApi.

This isolation protects against API churn during the SDK's pre-1.0 development.
