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
- Structured logs include `component=mcp`, `event_type`, `run_id`, `session_id`, `mcp_event`,
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
- **Config hash** includes all discovery-affecting fields (command, args, cwd, url,
  excludeTools, transport, timeouts, env/header keys) — config changes produce a new hash.
  Env/header values are SHA-256-hashed before inclusion so the catalog never stores secrets.
- **Cross-server duplicate detection** prevents sanitized name collisions (e.g. "a.b/tool"
  and "a_b/tool" both sanitize to "a_b_tool") from silently overwriting tools. The second
  occurrence is skipped with a warning.
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
  MCP-06 adds best-effort graceful shutdown via `WorkerStoppedEvent` subscriber.
  SIGKILL/OOM orphan recovery remains a documented limitation.

## Current design

- **Dynamic tool registration:** tools from MCP servers are registered into the
  Hatfield tool registry at session start via `McpToolRegistrar`. The LLM sees
  MCP tools alongside built-in tools in its schema output.
- **Tool invocation:** when the LLM calls an MCP-backed tool, `ExecuteToolCall`
  is routed to the single `mcp` Messenger consumer (via `McpExecuteToolCallRoutingMiddleware`)
  instead of the default `tool` transport. The mcp consumer runs the standard
  `ExecuteToolCallWorker`, which invokes `McpToolHandler` → `McpToolInvoker` →
  `McpConnectionManager::callTool()` → SDK client. Results flow back through
  the normal `ToolCallResult` pipeline — there is no separate request/reply
  mailbox or result store.
- **Incremental catalog publication:** tools from each successfully-discovered
  server are published as soon as that server's discovery completes. A slow
  or failing server does not block visibility of tools from faster servers.
- **Reconnect-once:** if a live client is missing at tool-call time (e.g.,
  STDIO process crashed), `McpConnectionManager::callTool()` attempts one
  reconnect before failing.
- **Graceful shutdown (best-effort):** `McpWorkerShutdownSubscriber` listens
  to Symfony Messenger's `WorkerStoppedEvent` and calls `disconnectAll()`
  when the mcp consumer process exits normally (SIGTERM). No waits, no
  drain loops — this is best-effort and does not recover from SIGKILL/OOM.

## Limitations

- **No OAuth support** for MCP server authentication.
- **No per-call timeout/cancellation:** the MCP SDK (`mcp/sdk`) has no
  per-call timeout or cancellation hook. Request timeout is fixed at
  client construction time from `mcp.json` `timeoutMs`. `ToolContext`
  timeout/cancellation does not cap in-flight SDK calls.
- **SIGKILL/OOM orphan processes:** when the mcp consumer process is
  SIGKILL'd or OOM-killed, the `WorkerStoppedEvent` subscriber never
  runs, and any STDIO MCP server subprocesses may remain orphaned.
  The controller already performs best-effort consumer-process cleanup
  on its own shutdown, but STDIO grandchildren may escape.
- **Single mcp consumer serialization:** only one mcp consumer process
  exists per session. All MCP tool calls are serialized through this
  consumer. Parallel MCP tool calls from multiple LLM turns or
  parallel tools within a turn are queued.
- **Catalog read on every MCP tool call:** the middleware reads the
  session catalog for routing decisions; config loading (for reconnect)
  is deferred until the client is actually needed.
- **MCP config failures during initialize are warning-only** — normal
  sessions continue unaffected, just without MCP tools.
- **Messenger consumer restarts (memory-limit recycle or normal graceful
  exit) trigger `WorkerStoppedEvent`**, which disconnects MCP clients.
  The next MCP tool call lazily reconnects, adding one-time startup
  latency after restart. This is intentional — dedicated keep-alive
  pings and idle timeout are deferred to a future phase.

## SDK boundary

The official `mcp/sdk` PHP package is used behind a Hatfield-owned client boundary
under `src/CodingAgent/Mcp/Client/`. SDK types (`Mcp\*`) are isolated to that
namespace and never leak into AgentCore, TUI, or ExtensionApi.

This isolation protects against API churn during the SDK's pre-1.0 development.
