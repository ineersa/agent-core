---
builtin: true
description: MCP server configuration, merge rules, tool exposure, and worker shutdown.
---

# MCP Client Configuration

Hatfield can call tools exposed by external MCP (Model Context Protocol) servers.

## Configuration files

| Scope | Path |
|---|---|
| User | `~/.hatfield/mcp.json` |
| Project | `<cwd>/.hatfield/mcp.json` |

Both optional. Missing/empty means no MCP servers.

## Merge behavior

Project config overrides user config by **server name** with whole-server replacement (not deep field merge).

Special case: project entry `{ "enabled": false }` disables an inherited server of the same name.

## Schema (JSON)

Each server entry is either **STDIO** (`command`) or **HTTP** (`url`). Transport is **derived** from which field you set — there is no user `transport` field. Do not set both `command` and `url`.

```jsonc
{
  "mcpServers": {
    "local-stdio": {
      "enabled": true,
      "command": "npx",
      "args": ["-y", "some-mcp-server"],
      "env": { "FOO": "bar" },
      "cwd": "/path/to/server",
      "timeoutMs": 30000,
      "startupTimeoutMs": 30000,
      "availability": "all",
      "excludeTools": []
    },
    "remote-http": {
      "enabled": true,
      "url": "https://example.example/mcp",
      "headers": { "Authorization": "Bearer ..." },
      "timeoutMs": 30000,
      "startupTimeoutMs": 30000,
      "availability": "specific"
    }
  }
}
```

| Field | Meaning | Default |
|---|---|---|
| `enabled` | Include this server | `true` |
| `command` | STDIO executable (implies STDIO transport) | — |
| `args` | STDIO argv list | `[]` |
| `env` | Extra env for STDIO child | `{}` |
| `cwd` | Working directory for STDIO child | inherit |
| `url` | HTTP endpoint (implies HTTP transport) | — |
| `headers` | HTTP request headers | `{}` |
| `timeoutMs` | Per-request timeout (ms) | `30000` |
| `startupTimeoutMs` | Startup/connect timeout (ms) | `30000` |
| `availability` | `all` (global inherit) or `specific` (selector-only) | `all` |
| `excludeTools` | Tool names to hide from the registry | `[]` |

Unknown fields are rejected by the config loader. Prefer least-privilege env vars. Do not commit secrets.

## Availability and child inheritance

`AgentMcpToolsResolver` maps agent frontmatter `tools` entries into MCP runtime names:

| `availability` | Meaning |
|---|---|
| `all` | Server tools participate in **global** MCP inheritance |
| `specific` | Tools are **not** in the global set; only explicit child selectors can add them |

When an agent definition **omits** `tools`, or lists tools **without** any `mcp:` selector, the child inherits the **global** MCP set (`availability: all` servers only).

When the definition includes one or more `mcp:` selectors:

| Selector | Policy |
|---|---|
| `mcp:-` | No MCP tools (`none`) — wins over other selectors |
| only `mcp:*` | All globally available MCP tools (`all`) |
| any other `mcp:…` | `specific` mode: start from global tools, then add matches |

Specific selector grammar (after the `mcp:` prefix):

- Exact runtime name: `mcp:websearch_query`
- Terminal prefix wildcard only: `mcp:websearch_*` (exactly one trailing `*`)
- Embedded/multiple `*` characters are **not** globs (exact match only)

Raw catalog runtime names listed without the `mcp:` prefix are still treated as MCP-owned for policy (they do not bypass availability). Non-MCP tool names pass through as ordinary allowlist entries.

Child denylist `agents.subagent_excluded_tools` still removes named tools after MCP merge.

## Runtime behavior

- MCP tools are registered into the Hatfield tool registry for the session after servers connect and advertise tools.
- Tool names are namespaced/unique per registry rules to avoid collisions with built-ins.
- Invocations use the MCP client session for the active run; transient disconnects may reconnect according to client manager policy.

## Shutdown

Workers perform **best-effort graceful disconnect** on worker stop (`McpWorkerShutdownSubscriber` / connection manager disconnect). STDIO child processes are signaled on shutdown; some grandchildren may still escape if servers spawn unmanaged trees — treat MCP servers as untrusted process boundaries.

## Operations tips

- Keep server lists small; each server adds process/network surface.
- Prefer project config for repo-specific servers and user config for personal defaults.
- After changing MCP JSON, start a new session so connections and tool catalogs refresh cleanly.

## Related

- Agents / child tools: [agents.md](agents.md)
- Settings overview: [settings.md](settings.md)

## Argument validation

Dynamic MCP tools keep raw-array handlers. Before invocation, Hatfield validates rewritten arguments against each tool's exact registered `inputSchema` (JSON Schema). Built-in tools use Symfony AI native DTO resolution and Validator constraints instead. Malformed runtime schemas are rejected at registration so invalid tools are never exposed.

