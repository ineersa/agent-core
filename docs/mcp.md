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

```jsonc
{
  "mcpServers": {
    "<server-name>": {
      "enabled": true,
      "transport": "stdio",   // or HTTP/SSE transports supported by the client
      "command": "npx",
      "args": ["-y", "some-mcp-server"],
      "env": { "FOO": "bar" },
      "url": "https://example.example/mcp",
      "headers": { "Authorization": "Bearer ..." },
      "timeout": 60
    }
  }
}
```

Exact transport fields depend on server type. Prefer least-privilege env vars. Do not commit secrets.

## Runtime behavior

- MCP tools are registered into the Hatfield tool registry for the session after servers connect and advertise tools.
- Tool names are namespaced/unique per registry rules to avoid collisions with built-ins.
- Child/subagent availability follows the same tool policy as other tools subject to `agents.subagent_excluded_tools` and child allowlists.
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
