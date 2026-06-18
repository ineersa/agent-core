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

## Limitations in Phase 0

- No OAuth support.
- No runtime broker — config loading only; servers are not connected.
- No dynamic tool registration in this phase.
- MCP tools are not yet callable from Hatfield.

These will be added in subsequent phases per `.pi/plans/mcp-client-implementation-plan.md`.

## SDK boundary

The official `mcp/sdk` PHP package is used behind a Hatfield-owned client boundary
under `src/CodingAgent/Mcp/Client/`. SDK types (`Mcp\*`) are isolated to that
namespace and never leak into AgentCore, TUI, or ExtensionApi.

This isolation protects against API churn during the SDK's pre-1.0 development.
