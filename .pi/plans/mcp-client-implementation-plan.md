# MCP Client Implementation Plan

## Status

Planning document for adding **MCP client support** to Hatfield/agent-core.

This plan is based on:

- our design discussion;
- scout reports for the current agent-core architecture;
- scout reports for opencode and pi-mcp-adapter MCP implementations;
- researcher report for `modelcontextprotocol/php-sdk`.

No source-code inspection is required to understand this plan. It is written for an implementor who knows nothing about the prior discussion.

---

## 1. Goal

Add client-side MCP support so Hatfield can use tools exposed by external MCP servers.

The implementation should support:

- local STDIO MCP servers;
- HTTP MCP servers;
- static/manual HTTP headers, including bearer tokens;
- MCP tool discovery;
- MCP tool invocation through the existing Hatfield tool pipeline;
- simple project/user configuration via `mcp.json`.

The first implementation should be intentionally simple:

- no ToolSearch/deferred activation;
- no OAuth flow;
- no MCP server implementation;
- no TUI-specific MCP UI;
- no attempt to expose Hatfield itself as an MCP server;
- no parallel MCP execution requirement for v1.

---

## 2. Core architectural decision

MCP must be implemented as a **CodingAgent app-layer tool provider**, not as a new runtime transport.

Do **not** implement MCP as:

- a new `AgentSessionClient`;
- a TUI concern;
- an AgentCore domain concern;
- an ExtensionApi public surface;
- a parallel tool execution system.

MCP tools should flow through the existing tool architecture:

```text
MCP config
  → MCP broker discovers server tools
  → MCP catalog stores tool schemas
  → MCP tool registrar exposes tools as dynamic Hatfield tools
  → LLM sees normal tool schemas
  → LLM calls normal Hatfield tool name
  → existing ToolExecutor invokes McpToolHandler
  → McpToolHandler requests MCP broker call
  → MCP broker calls MCP SDK client
  → result maps back to normal Hatfield tool result
```

This preserves all existing behavior around:

- tool visibility;
- tool batching;
- tool execution modes;
- allow/deny lists;
- output capping;
- HITL/tool hooks where applicable;
- result commitment;
- LLM continuation after tool results.

---

## 3. Why a dedicated MCP broker is required

The system currently has multiple tool workers, currently 4 and configurable.

If MCP STDIO clients live inside normal tool workers, this can happen:

```text
tool worker 1 → starts filesystem MCP server
tool worker 2 → starts another filesystem MCP server
tool worker 3 → starts another filesystem MCP server
tool worker 4 → starts another filesystem MCP server
```

That is unacceptable for STDIO MCP because:

- each STDIO connection spawns a child process;
- servers may keep state;
- process cleanup becomes unreliable;
- resource usage multiplies by worker count;
- tool behavior may become inconsistent depending on which worker receives the call.

Therefore v1 should introduce a dedicated **MCP broker / connection manager** running behind a single Messenger consumer.

Recommended process shape:

```text
HeadlessController
  → ConsumerSupervisor
      → run_control consumer
      → llm consumer
      → tool consumers × N
      → scheduler consumer
      → mcp consumer × 1
```

The single `mcp` consumer owns all MCP SDK clients for the session.

Normal tool workers never own STDIO MCP processes. They only send requests to the MCP broker and wait for the result.

---

## 4. High-level v1 architecture

```text
┌──────────────────────────────────────────────────────────────┐
│ Session/controller startup                                    │
│                                                              │
│  Load MCP config                                              │
│    ~/.hatfield/mcp.json                                       │
│    .hatfield/mcp.json                                         │
│                                                              │
│  Start normal Messenger consumers                             │
│  Start one mcp Messenger consumer                             │
└──────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌──────────────────────────────────────────────────────────────┐
│ MCP consumer / broker                                         │
│                                                              │
│  McpConnectionManager                                         │
│    - owns PHP SDK Client objects                              │
│    - owns STDIO child processes through SDK StdioTransport     │
│    - owns HTTP SDK clients                                    │
│    - one client per MCP server per session                    │
│                                                              │
│  McpToolDiscovery                                             │
│    - calls listTools()                                        │
│    - maps schemas                                             │
│    - writes session MCP catalog                               │
│                                                              │
│  McpCallToolHandler                                           │
│    - handles call requests                                    │
│    - calls SDK Client::callTool()                             │
│    - stores correlated result                                 │
└──────────────────────────────────────────────────────────────┘
                            ▲
                            │ request/reply
                            ▼
┌──────────────────────────────────────────────────────────────┐
│ Normal tool worker                                            │
│                                                              │
│  Existing ToolExecutor                                        │
│    → McpToolHandler::__invoke(array $args)                    │
│       → dispatch McpCallToolCommand to mcp transport          │
│       → wait for correlated McpCallToolResult                 │
│       → return normal tool result payload                     │
└──────────────────────────────────────────────────────────────┘
```

---

## 5. PHP MCP SDK usage

Use the official PHP SDK package:

```text
mcp/sdk
```

Researcher findings:

- current researched version: `0.6.0`;
- package is experimental/pre-1.0;
- client API is synchronous/blocking;
- uses PHP Fibers internally;
- STDIO transport uses `proc_open()`;
- HTTP transport uses PSR-18 / PSR-17 discovery or explicit clients/factories;
- OAuth can be ignored for now;
- bearer/static headers are enough for simple HTTP servers.

Expected API shape from the report:

```php
$client = Client::builder()
    ->setClientInfo('hatfield', $version)
    ->build();

$transport = new StdioTransport($command, $args, $cwd, $env);
// or:
$transport = new HttpTransport($endpoint, $headers);

$client->connect($transport);
$tools = $client->listTools();
$result = $client->callTool($name, $arguments);
$client->disconnect();
```

Important implementation rule:

> Isolate the SDK behind our own interfaces/classes. Do not leak SDK classes into AgentCore, TUI, ExtensionApi, or broad application code.

Reason: SDK is pre-1.0 and may break between minor releases.

---

## 6. Configuration format

Use standalone MCP config files, not the main settings YAML initially.

Recommended paths:

```text
~/.hatfield/mcp.json      # user/global MCP config
.hatfield/mcp.json        # project-local MCP config, overrides global
```

Merge behavior:

```text
global mcpServers < project mcpServers
```

Project config overrides server definitions by server name.

### 6.1 Minimal config schema

Use a pi-mcp-adapter-like shape because it is simple and familiar:

```jsonc
{
  "mcpServers": {
    "filesystem": {
      "enabled": true,
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-filesystem", "."],
      "env": {
        "EXAMPLE": "value"
      },
      "cwd": ".",
      "timeoutMs": 30000,
      "startupTimeoutMs": 30000,
      "excludeTools": ["dangerous_tool"]
    },

    "github": {
      "enabled": true,
      "url": "https://api.githubcopilot.com/mcp",
      "headers": {
        "Authorization": "Bearer ${GITHUB_MCP_TOKEN}"
      },
      "timeoutMs": 30000,
      "excludeTools": []
    }
  },

  "settings": {
    "toolPrefix": "server"
  }
}
```

### 6.2 Server definition rules

Each server must define exactly one connection type:

- STDIO server: `command` + optional `args`, `env`, `cwd`;
- HTTP server: `url` + optional `headers`.

Invalid:

```jsonc
{
  "command": "...",
  "url": "..."
}
```

Invalid:

```jsonc
{
  "enabled": true
}
```

unless the only intent is a project-local override disabling an inherited server:

```jsonc
{
  "mcpServers": {
    "someInheritedServer": {
      "enabled": false
    }
  }
}
```

### 6.3 Supported fields in v1

```text
enabled?: boolean
command?: string
args?: string[]
env?: object<string,string>
cwd?: string
url?: string
headers?: object<string,string>
timeoutMs?: int
startupTimeoutMs?: int
excludeTools?: string[]
```

Optional later fields, not required for v1:

```text
lifecycle?: "eager" | "lazy" | "keep-alive"
directTools?: boolean | string[]
includeTools?: string[]
exposeResources?: boolean
bearerTokenEnv?: string
```

For v1, do not implement OAuth.

### 6.4 Environment interpolation

Support environment interpolation in:

- `env` values;
- `headers` values;
- possibly `cwd` later, but not required.

Examples:

```jsonc
{
  "headers": {
    "Authorization": "Bearer ${MCP_TOKEN}"
  },
  "env": {
    "API_KEY": "${SOME_API_KEY}"
  }
}
```

If an env var is missing, fail server initialization with a clear diagnostic. Do not silently send literal `${TOKEN}`.

---

## 7. Tool naming

MCP tool names must be namespaced to avoid collisions with built-in Hatfield tools.

Default naming:

```text
{serverName}_{toolName}
```

Examples:

```text
filesystem_read_file
github_search_issues
playwright_browser_click
```

Sanitization:

- allow letters, numbers, `_`, `-`;
- replace any other character with `_`;
- preserve a map from Hatfield tool name back to `(serverName, mcpToolName)`.

Collision policy:

- if an MCP dynamic tool collides with a permanent tool, fail registration for that MCP tool and log a diagnostic;
- if two MCP tools collide after sanitization, fail the second and log a diagnostic;
- do not silently overwrite tools.

---

## 8. Tool discovery and catalog

Important constraint:

> MCP tool schemas must be known before the LLM call.

The LLM cannot call an MCP tool unless its schema was already included in the active tool set.

Therefore execution-time lazy connection alone is not enough.

### 8.1 V1 catalog strategy

Use a session-scoped MCP catalog.

The broker discovers tools and writes a catalog that other processes can read.

Possible storage options:

1. session file:

```text
.hatfield/sessions/<runId>/mcp-tools.json
```

2. DB table keyed by `run_id`;
3. existing run/session state extension if appropriate.

For v1, session file is likely simplest.

Catalog contents:

```jsonc
{
  "runId": "123",
  "generatedAt": "2026-06-12T...Z",
  "servers": {
    "filesystem": {
      "status": "connected",
      "transport": "stdio",
      "tools": [
        {
          "hatfieldName": "filesystem_read_file",
          "serverName": "filesystem",
          "mcpName": "read_file",
          "description": "Read a file",
          "inputSchema": {
            "type": "object",
            "properties": {}
          }
        }
      ]
    }
  }
}
```

### 8.2 Discovery timing

V1 should discover tools at session startup or before the first LLM turn.

Recommended behavior:

```text
session start
  → request MCP broker to initialize enabled servers
  → broker connects/listTools
  → broker writes catalog
  → dynamic MCP tools are registered from catalog before LLM input creation
```

If a server fails discovery:

- do not fail the entire session by default;
- record server status as failed;
- exclude that server's tools from the active toolset;
- emit/log a structured diagnostic.

If all MCP servers fail:

- session continues without MCP tools.

### 8.3 Catalog refresh

For v1, catalog can be static for the session.

Later:

- refresh on `/mcp reconnect`;
- refresh on tool-list-changed notifications if SDK exposes them;
- refresh between turns;
- persist cross-session cache for fast startup.

---

## 9. Lifecycle policy

Use different lifecycle policies by transport type.

### 9.1 STDIO lifecycle

STDIO must be session-scoped keep-alive.

Do **not** connect/disconnect STDIO per tool call.

STDIO flow:

```text
session start or first MCP discovery
  → broker starts STDIO MCP server through SDK StdioTransport
  → broker performs MCP initialize/connect
  → broker calls listTools
  → broker keeps client open for the session
  → tool calls reuse same client
  → session/controller shutdown closes client
```

Rationale:

- spawning per call is slow;
- servers may hold state;
- repeated `proc_open()` increases orphan risk;
- duplicated STDIO servers across tool workers must be avoided.

The broker should own exactly one SDK client per STDIO server per session.

### 9.2 HTTP lifecycle

HTTP can be handled more flexibly.

For v1, broker HTTP too for uniformity:

```text
all MCP calls, STDIO and HTTP, go through mcp consumer
```

This simplifies:

- one code path;
- one result mapping path;
- one catalog path;
- one status path.

Downside:

- HTTP MCP calls are serialized through the single MCP consumer.

This is acceptable for v1.

Later optimization:

```text
STDIO → brokered single-owner
HTTP  → direct from tool workers or HTTP-specific parallel broker workers
```

### 9.3 Idle timeout

For v1:

- STDIO: no idle timeout; close at session end;
- HTTP: no idle timeout if brokered uniformly; close at session end.

Later:

- HTTP idle close after N minutes;
- STDIO optional idle close only if lifecycle is explicitly configured as lazy.

---

## 10. Messenger queue and request/reply design

Add a new Messenger transport:

```text
mcp
```

Run it with a single consumer per controller/session.

```text
messenger:consume mcp
```

The controller/supervisor should start it with the other consumers.

### 10.1 Commands/messages

Add app-layer messages under a CodingAgent MCP namespace.

Suggested messages:

```php
McpInitializeSessionCommand
  - runId
  - sessionId
  - configHash

McpCallToolCommand
  - correlationId
  - runId
  - serverName
  - mcpToolName
  - arguments
  - timeoutMs

McpRefreshCatalogCommand
  - runId
  - force

McpDisconnectSessionCommand
  - runId
```

Result DTO/store record:

```php
McpCallToolResultRecord
  - correlationId
  - runId
  - status: success|error|timeout
  - content
  - rawMetadata?
  - errorMessage?
  - createdAt
```

### 10.2 Request/reply mechanism

Because normal tool workers need a synchronous result from the single MCP consumer, v1 needs request/reply.

Recommended simple v1 approach:

```text
McpToolHandler
  → create correlationId
  → dispatch McpCallToolCommand to mcp transport
  → poll McpResultStore by correlationId until result or timeout
  → return result / throw timeout exception
```

This is simple and robust enough for v1.

Result store options:

- DB table keyed by `correlation_id`;
- session-local file store with locks.

DB is probably cleaner for multi-process request/reply.

Polling interval:

```text
50ms–100ms
```

Timeout:

```text
per-server timeoutMs, fallback default 30s
```

If timeout occurs:

- `McpToolHandler` returns/throws an error that existing `ToolExecutor` converts to an error tool result;
- broker may continue and later write stale result;
- stale result cleanup should delete old records.

### 10.3 Alternative request/reply options

Do not implement initially unless needed:

1. direct SDK call in tool worker
   - simplest;
   - rejected for STDIO because each worker owns its own process;

2. socket/pipe IPC to controller broker
   - cleaner long-term;
   - more complexity;

3. one queue per server
   - useful later for parallelism across MCP servers;

4. Symfony Messenger reply transport
   - possible if already supported cleanly, but not required for v1.

---

## 11. Components to add

Suggested namespace:

```text
src/CodingAgent/Mcp/
```

Do not place MCP SDK code in `src/AgentCore/` or `src/Tui/`.

### 11.1 Config

```text
src/CodingAgent/Mcp/Config/McpConfigLoader.php
src/CodingAgent/Mcp/Config/McpConfigDTO.php
src/CodingAgent/Mcp/Config/McpServerDefinitionDTO.php
src/CodingAgent/Mcp/Config/McpTransportTypeEnum.php
src/CodingAgent/Mcp/Config/McpConfigValidator.php
src/CodingAgent/Mcp/Config/McpEnvInterpolator.php
```

Responsibilities:

- load global/project `mcp.json`;
- merge definitions;
- validate exactly one of `command` or `url`;
- resolve `cwd` relative to project directory;
- interpolate env/header variables;
- expose typed DTOs.

### 11.2 SDK boundary

```text
src/CodingAgent/Mcp/Client/McpSdkClientFactory.php
src/CodingAgent/Mcp/Client/McpClientInterface.php
src/CodingAgent/Mcp/Client/McpSdkClientAdapter.php
src/CodingAgent/Mcp/Client/McpConnectionManager.php
```

Responsibilities:

- create SDK clients;
- create `StdioTransport` or `HttpTransport`;
- connect/list/call/disconnect;
- hide SDK classes from the rest of the app;
- enforce one client per `(runId, serverName)` in the broker process.

### 11.3 Catalog

```text
src/CodingAgent/Mcp/Catalog/McpToolCatalog.php
src/CodingAgent/Mcp/Catalog/McpToolDefinitionDTO.php
src/CodingAgent/Mcp/Catalog/McpToolCatalogStoreInterface.php
src/CodingAgent/Mcp/Catalog/SessionFileMcpToolCatalogStore.php
```

Responsibilities:

- store discovered tools per session/run;
- map Hatfield tool names to MCP server/tool names;
- provide tool schemas for dynamic registration in each relevant process.

### 11.4 Tool integration

```text
src/CodingAgent/Mcp/Tool/McpToolRegistrar.php
src/CodingAgent/Mcp/Tool/McpToolHandler.php
src/CodingAgent/Mcp/Tool/McpToolNameMapper.php
src/CodingAgent/Mcp/Tool/McpResultMapper.php
```

Responsibilities:

- read catalog;
- register dynamic tools using existing `ToolRegistry::addDynamicTool()`;
- create one `McpToolHandler` per MCP tool;
- map handler invocation to broker request;
- map MCP content/result to normal Hatfield tool result shape.

### 11.5 Broker messages/handlers

```text
src/CodingAgent/Mcp/Message/McpInitializeSessionCommand.php
src/CodingAgent/Mcp/Message/McpCallToolCommand.php
src/CodingAgent/Mcp/Message/McpRefreshCatalogCommand.php
src/CodingAgent/Mcp/Message/McpDisconnectSessionCommand.php

src/CodingAgent/Mcp/Handler/McpInitializeSessionHandler.php
src/CodingAgent/Mcp/Handler/McpCallToolHandler.php
src/CodingAgent/Mcp/Handler/McpRefreshCatalogHandler.php
src/CodingAgent/Mcp/Handler/McpDisconnectSessionHandler.php
```

Responsibilities:

- run only in `mcp` Messenger transport;
- own connection manager lifecycle;
- write catalog;
- write correlated call results.

### 11.6 Result store

```text
src/CodingAgent/Mcp/Result/McpCallResultStoreInterface.php
src/CodingAgent/Mcp/Result/DoctrineMcpCallResultStore.php
src/CodingAgent/Mcp/Result/McpCallResultRecord.php
```

Responsibilities:

- persist broker responses by correlation ID;
- allow tool workers to wait/poll;
- cleanup old/stale results.

---

## 12. Result mapping

MCP `callTool()` returns MCP content blocks, not native Hatfield tool results.

Implement a mapper:

```text
McpResultMapper
  MCP text content       → string/text output
  MCP image content      → image block if supported, otherwise text placeholder/metadata
  MCP resource content   → text/resource description if supported
  MCP error              → error tool result
  unknown content block  → diagnostic text block
```

V1 can be conservative:

- preserve text content as text;
- JSON-encode unknown structured content;
- never include raw secrets in logs;
- include concise error messages for failures.

Existing output capping should apply after the tool result enters the normal Hatfield pipeline.

---

## 13. Error handling

Every caught exception must either be propagated or logged with diagnostics.

Server discovery errors:

```text
server failed to connect/list tools
  → catalog marks server failed
  → tools are not registered
  → session continues
  → structured warning log
```

Tool call errors:

```text
MCP call fails
  → broker writes error result
  → McpToolHandler returns/throws error
  → existing ToolExecutor converts to normal failed ToolResult
```

Timeouts:

```text
McpToolHandler waiting for broker result times out
  → return failed tool result
  → mark correlation stale
  → broker may later discard/write stale result
```

Broker crash:

```text
Tool worker times out waiting for result
  → failed tool result
  → controller/supervisor should restart mcp consumer if current supervisor supports restarts
```

STDIO process crash:

```text
McpConnectionManager detects failed client/call
  → close/discard client
  → reconnect once if safe
  → otherwise mark server failed
```

Avoid infinite reconnect loops.

---

## 14. Logging and security

Use structured event-style logs.

Include fields like:

```text
component: mcp
run_id
session_id
server_name
transport: stdio|http
correlation_id
mcp_event: connect|disconnect|list_tools|call_tool|call_failed|catalog_written
```

Do not log:

- raw prompts;
- raw tool output by default;
- full environment;
- authorization headers;
- bearer tokens;
- API keys;
- entire MCP request arguments unless explicitly safe.

For config diagnostics, log redacted values:

```text
Authorization: Bearer ***
API_KEY: ***
```

---

## 15. Controller/supervisor integration

The controller should start the MCP consumer alongside existing workers.

Expected desired shape:

```text
ConsumerSupervisor launches:
  run_control: 1
  llm: configured
  tool: configured, currently 4
  scheduler_default: 1
  mcp: 1
```

The `mcp` consumer count should be fixed to 1 in v1.

Reason:

- one owner for STDIO server processes;
- serialized MCP calls;
- deterministic per-session MCP state.

Later configurable shape:

```text
mcp workers: 1 globally
or
mcp workers: 1 per server
or
mcp_http workers: N, mcp_stdio workers: 1 per server
```

Do not start multiple generic `mcp` consumers in v1.

---

## 16. Session scoping

MCP connections should be scoped to a run/session.

Current system invariant from project docs:

```text
session_id === run_id
```

Use `runId` consistently in:

- catalog path/store;
- result store records;
- broker messages;
- logs;
- cleanup.

The broker should not share STDIO connections across sessions in v1.

Reason:

- avoids cross-session state leaks;
- avoids permission/cwd confusion;
- makes cleanup easier;
- aligns with session isolation.

---

## 17. Shutdown and cleanup

Normal shutdown:

```text
controller shutdown
  → stop mcp consumer
  → McpConnectionManager::disconnectAll(runId)
  → SDK clients disconnect
  → STDIO transports/processes close
```

Also dispatch/handle explicit command when available:

```text
McpDisconnectSessionCommand(runId)
```

Known hard case:

```text
SIGKILL/OOM/segfault
  → PHP shutdown handlers may not run
  → STDIO child processes may survive
```

Mitigation options:

- rely on SDK/process closure for graceful paths;
- add broker startup orphan cleanup later;
- reuse existing process group cleanup patterns if SDK does not reliably kill child processes;
- add stale process detection if practical.

Do not pretend SIGKILL cleanup is solved in v1.

Document it as a known limitation.

---

## 18. Execution modes and parallelism

MCP dynamic tools should default to sequential execution mode for v1.

Reason:

- many MCP tools have side effects;
- single MCP consumer serializes calls anyway;
- avoids interleaving surprises in STDIO servers.

The existing tool system may dispatch multiple MCP tool calls, but the `mcp` consumer processes them one at a time.

This means v1 loses MCP parallelism. That is acceptable.

Future improvement:

```text
- HTTP MCP direct execution for parallelism;
- one MCP queue per server;
- one STDIO broker per server;
- parallel HTTP broker workers;
- per-tool/server execution mode from config.
```

---

## 19. CLI/TUI commands for v1

Not required for first implementation.

Optional later:

```text
/mcp status
/mcp tools
/mcp reconnect <server>
/mcp disable <server>
```

For v1, logs and tests are enough.

---

## 20. Implementation phases

### Phase 0 — dependency and config DTOs

Tasks:

- add `mcp/sdk` dependency;
- verify exact SDK API against installed version;
- add MCP config loader;
- add DTOs and validation;
- support global/project `mcp.json` merge;
- support env/header interpolation;
- add unit tests for config parsing and validation.

Acceptance criteria:

- valid STDIO config loads;
- valid HTTP config loads;
- project config overrides global config;
- invalid `command`+`url` config fails clearly;
- missing env variable fails clearly;
- no SDK classes leak outside MCP client boundary.

### Phase 1 — broker and connection manager

Tasks:

- add `mcp` Messenger transport;
- update controller/supervisor config to run one `mcp` consumer;
- implement `McpSdkClientFactory`;
- implement `McpConnectionManager`;
- implement session initialize/disconnect messages;
- implement server connect/listTools;
- write catalog to session-scoped store;
- add structured logs.

Acceptance criteria:

- MCP consumer starts with controller;
- broker can connect to one STDIO test server and list tools;
- broker can connect to one HTTP test server and list tools;
- catalog is written with namespaced tool names;
- failed server does not crash session startup.

### Phase 2 — dynamic tool registration

Tasks:

- implement `McpToolCatalogStore` reader;
- implement `McpToolNameMapper`;
- implement `McpToolRegistrar`;
- register MCP tools as dynamic tools from catalog;
- ensure registration happens before LLM tool schema resolution;
- default MCP tools to sequential execution mode;
- enforce collision rules.

Acceptance criteria:

- discovered MCP tools appear in active tool set;
- LLM-visible names are namespaced;
- schemas match MCP input schemas;
- collisions are diagnosed, not silently overwritten.

### Phase 3 — tool invocation request/reply

Tasks:

- implement `McpCallToolCommand`;
- implement result store;
- implement broker-side `McpCallToolHandler`;
- implement tool-worker-side `McpToolHandler`;
- implement polling by correlation ID;
- implement timeouts;
- implement `McpResultMapper`.

Acceptance criteria:

- normal tool worker can invoke MCP tool through broker;
- STDIO server process is not duplicated across four tool workers;
- result returns through normal ToolExecutor pipeline;
- MCP errors become normal failed tool results;
- timeout becomes normal failed tool result.

### Phase 4 — lifecycle hardening and validation

Tasks:

- ensure disconnect on session/controller shutdown;
- add stale result cleanup;
- add reconnect-once behavior for crashed clients if safe;
- add focused integration tests;
- add docs for `.hatfield/mcp.json`;
- run full validation through Castor.

Acceptance criteria:

- broker closes clients on graceful shutdown;
- stale results are cleaned;
- tests prove no duplicate STDIO process per tool worker scenario, as far as practical;
- docs explain config, limitations, and no OAuth v1;
- `castor check` passes.

---

## 21. Testing strategy

Load the project `testing` skill before implementing or running tests.

All QA/test commands must use Castor.

### 21.1 Unit tests

Config:

- loads empty/no config;
- loads global config;
- project overrides global;
- disables inherited server;
- rejects both `command` and `url`;
- rejects neither `command` nor `url`;
- interpolates env vars;
- fails on missing env var;
- redacts secrets in diagnostics.

Name mapping:

- sanitizes names;
- maps Hatfield name to server/tool;
- detects collisions.

Result mapping:

- maps text content;
- maps unknown content safely;
- maps error result;
- does not expose secret metadata.

### 21.2 Integration tests

Use test MCP servers if practical:

- local STDIO fixture server with one simple tool, e.g. `echo`;
- local HTTP fixture server with one simple tool.

Test scenarios:

- broker connects and lists tools;
- catalog is written;
- dynamic tools register from catalog;
- tool worker calls broker and receives result;
- timeout behavior;
- failed server does not kill session.

### 21.3 Process/lifecycle tests

Important STDIO tests:

- with multiple tool workers configured, only broker owns STDIO connection;
- repeated MCP tool calls reuse same broker-side client;
- graceful shutdown disconnects/terminates STDIO server;
- stale result cleanup works.

### 21.4 End-to-end validation

Because this touches runtime/LLM-visible tool flow, final validation should include:

```text
castor test
castor deptrac
castor phpstan
castor cs-check
LLM_MODE=true castor check
```

If TUI behavior is changed later, then real TUI E2E proof is required. This initial MCP backend implementation should avoid TUI behavior changes.

---

## 22. Documentation to update

Add or update docs explaining:

- `.hatfield/mcp.json` location;
- config schema;
- STDIO examples;
- HTTP examples;
- bearer token via env var;
- no OAuth in v1;
- MCP tools are namespaced as `{server}_{tool}`;
- STDIO lifecycle is session-scoped;
- MCP calls are serialized in v1;
- troubleshooting failed server discovery.

Possible doc location:

```text
docs/mcp.md
```

Also cross-link from settings docs if appropriate.

---

## 23. Open questions and risks

### 23.1 Request/reply storage

Decision needed during implementation:

- DB table vs session file store for `McpCallToolResult`.

Recommendation:

- use DB table if existing Doctrine/Messenger infrastructure makes it straightforward;
- otherwise use session file store with locks for v1.

DB is cleaner for multi-process polling.

### 23.2 Discovery timing

Need exact hook point before LLM tool schema resolution.

Requirement:

```text
catalog must exist and MCP dynamic tools must be registered before LLM input processing
```

Implementor must identify the correct point in the current runtime flow.

### 23.3 Dynamic tool registration across processes

Dynamic tool registry state may be process-local.

Do not assume registering MCP tools in the broker process makes them visible in LLM/tool worker processes.

Safer approach:

```text
catalog is durable/session-scoped;
each process that needs tool definitions registers/loads from catalog before resolving active tools.
```

### 23.4 Broker single consumer bottleneck

V1 serializes all MCP calls.

Accepted for correctness.

Later optimize:

- HTTP direct execution;
- one MCP queue per server;
- multiple HTTP MCP workers;
- per-server concurrency config.

### 23.5 SDK pre-1.0 churn

Risk:

- `mcp/sdk` may change APIs.

Mitigation:

- isolate SDK behind `McpClientInterface` / `McpSdkClientAdapter`;
- keep SDK usage concentrated in one namespace;
- write adapter tests.

### 23.6 STDIO orphan processes

Risk:

- SIGKILL/OOM can leave child processes.

Mitigation:

- graceful shutdown cleanup in broker;
- consider process group cleanup if SDK does not handle it;
- add orphan cleanup later.

### 23.7 OAuth

V1 does not support OAuth.

Supported:

- no auth;
- static headers;
- bearer token via env var.

If an HTTP MCP server requires OAuth, it is unsupported in v1.

---

## 24. Recommended implementation order

1. Add config loader + DTOs + tests.
2. Add SDK adapter and connection manager with direct unit/integration coverage.
3. Add `mcp` Messenger transport and single consumer.
4. Add broker discovery and session catalog.
5. Add dynamic tool registrar from catalog.
6. Add request/reply result store.
7. Add `McpToolHandler` invocation through broker.
8. Add result mapping and error handling.
9. Add docs.
10. Run Castor validation.

Do not start by wiring into the LLM path before the broker/catalog pieces exist.

---

## 25. Final target behavior

Given config:

```jsonc
{
  "mcpServers": {
    "filesystem": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-filesystem", "."]
    }
  }
}
```

Expected runtime behavior:

```text
1. Hatfield starts session.
2. Controller starts one MCP consumer.
3. MCP broker starts filesystem STDIO server once for this session.
4. Broker lists tools and writes catalog.
5. Hatfield registers dynamic tools like filesystem_read_file.
6. LLM sees filesystem_read_file schema.
7. LLM calls filesystem_read_file.
8. Any normal tool worker receives the call.
9. McpToolHandler sends request to MCP broker.
10. MCP broker calls SDK Client::callTool('read_file', args).
11. Broker writes correlated result.
12. Tool worker returns normal ToolResult.
13. Existing pipeline commits result and advances the run.
14. Session shutdown closes MCP client/server.
```

That is the desired v1.
