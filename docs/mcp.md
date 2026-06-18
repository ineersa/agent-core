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
  headers, tokens, or secrets.

## Limitations in Phase 1

- No OAuth support.
- Configuration loading and validation work, but servers are NOT yet connected
  (MCP-03 / Phase 2).
- No tool discovery or catalog persistence (MCP-03).
- No dynamic tool registration (MCP-04).
- No broker request/reply tool invocation (MCP-05).
- MCP config failures during initialize are warning-only — normal sessions
  continue unaffected.

These will be added in subsequent phases per `.pi/plans/mcp-client-implementation-plan.md`.

## SDK boundary

The official `mcp/sdk` PHP package is used behind a Hatfield-owned client boundary
under `src/CodingAgent/Mcp/Client/`. SDK types (`Mcp\*`) are isolated to that
namespace and never leak into AgentCore, TUI, or ExtensionApi.

This isolation protects against API churn during the SDK's pre-1.0 development.
