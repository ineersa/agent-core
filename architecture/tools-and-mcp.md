# Tools and MCP

[Architecture map](README.md) · [Child agents](extensions-and-agents.md)

## Tool catalog and owning implementations

The [bundled tool reference](../docs/tools.md) describes user-visible purpose and
limits. These are host-owned registrations, not a promise that every run exposes
every tool. MCP and enabled extensions contribute additional registrations.

| Names | Owning source |
|---|---|
| `read`, `write`, `edit`, `view_image` | `ReadFileTool`, `WriteFileTool`, `EditFileTool`, `ViewImageTool` under `src/CodingAgent/Tool` |
| `bash`, `bg_status` | `BashTool`, `BgStatusTool`, `BackgroundProcessManager` |
| `ask_human` | `AskHumanTool` and runtime human-input continuation |
| `settings`, `hatfield_docs` | `SettingsTool`, `HatfieldDocsTool` |
| `subagent`, `agent_resume`, `agent_retrieve`, `fork` | `src/CodingAgent/Agent/Tool` handlers and providers |
| Task-board operations | task-workflow extension registrations |
| `recall` | observational-memory extension registration |
| Server-advertised names, including IDE tools | MCP catalog and `McpToolRegistrar` |

## Design from definition to invocation

```mermaid
flowchart TB
    Provider[HatfieldToolProviderInterface] -->|definition| DTO[ToolDefinitionDTO]
    DTO --> Metadata[Name, description, handler, schema, execution mode, prompt guidance]
    Metadata --> Registry[ToolRegistry]
    Registry --> Permanent[Permanent registrations contribute prompt metadata]
    Registry --> Dynamic[Dynamic registrations carry runtime schemas]
    Permanent --> Resolver[CodingAgentToolSetResolver]
    Dynamic --> Resolver
    Resolver --> Allowed[Allowed and excluded names for active run]
    Allowed --> Toolbox[RegistryBackedToolbox]
    Toolbox --> Definition[One-definition native Symfony AI Toolbox]
    Definition --> Factory[SingleToolFactory returns canonical metadata]
    Definition --> Args[Native argument resolution and Validator for typed handlers]
    Args --> Handler[Invoke handler]
    Raw[Dynamic raw arguments] --> Handler
    Handler --> Result[Result normalization and output cap]
```

Registration owns execution mode; file mutation tools are sequential. Tool scheduling,
Messenger transport choice, and process cancellation are separate concerns. AgentCore
owns batch transitions through its contracts, while CodingAgent owns concrete tool
registration and framework integration. A provider schema is not an authorization rule.

## Registration becomes model-visible schemas

```mermaid
flowchart TB
    Builtin["Built-in HatfieldToolProviderInterface"] --> Registry[ToolRegistry]
    Extension["ExtensionApi registerTool<br/>ToolRegistrationDTO"] --> Registry
    Servers[Configured MCP servers] --> Connect[Connect and discover tool catalog]
    Connect --> Names[Namespaced MCP tools and runtime schemas]
    Names --> Registry
    Registry --> Policy[Active run tool allowlist and child policy]
    Policy --> Set[Per-turn toolset]
    Set --> Schemas[Symfony AI tool metadata and JSON schemas]
    Schemas --> Model[Provider request]
    Model --> Calls[Assistant tool calls with flat arguments]
    Calls --> Routing[Execution routing]
```

Typed built-ins use DTO field schemas and validation. Dynamic MCP arguments follow
the server's runtime schema rather than the built-in DTO validation path.

## Built-in argument ownership (inventory)

| Tool | Argument owner | Input constraints | Kept in handler |
|---|---|---|---|
| `read` | `ReadFileArgumentsDTO` + `ReadFileTarget` | path/offset/limit; target policy | I/O read failures |
| `write` | `WriteFileArgumentsDTO` | path/content | write I/O failures |
| `edit` | `EditFileArgumentsDTO` + `EditFileTarget` | path/patch; target exists | patch apply / lock failures |
| `view_image` | `ViewImageArgumentsDTO` (path only) | path shape | single handler inspection owns vision/size/MIME/dimensions + metadata |
| `bash` | `BashArgumentsDTO` + `BashTimeoutMax` | command; timeout bounds | process lifecycle, cancel, exit failures |
| `bg_status` | `BgStatusArgumentsDTO` | action; conditional pid | process lookup / stop / log failures |
| `ask_human` | `AskHumanArgumentsDTO` | question/kind/choices exclusivity | none (interrupt payload only) |
| `settings` | raw `$arguments` + local denormalize/validate into `SettingsArgumentsDTO` + `SettingsPath`; explicit flat `parametersJsonSchema` | operation/path; conditional scope/value; omitted `value` via uninitialized property | writer/resolver failures |
| `hatfield_docs` | `HatfieldDocsArgumentsDTO` | operation; conditional id | catalog/unknown-id / doc load failures |
| `subagent` / `agent_resume` / `agent_retrieve` / `fork` | respective Arguments DTOs (+ task schema providers where needed) | typed launch/resume/retrieve fields | active parent run context / locator wiring |
| MCP / extension raw tools | runtime `parametersJsonSchema` + `raw_arguments` | server/extension schema | handler or remote server |

Justified non-DTO path: only tools whose schema is defined at runtime (MCP and
public extension adapters), plus Settings: it keeps the historical flat
provider schema on the raw-array path and validates through a local
Serializer/Validator denormalization into `SettingsArgumentsDTO`. `value`
presence uses an uninitialized property (Serializer), not a legal-JSON
sentinel. Input errors use Symfony validation messages instead of
`ToolCallException` with a separate hint.

## Execute a batch, then continue the model

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    participant Core as run_control handlers
    participant Hooks as Tool-call hooks
    participant Queue as tool / agent / mcp
    participant Worker as ExecuteToolCallWorker
    participant Executor as ToolExecutor
    participant Store as Stored results
    participant Toolbox as FaultTolerantToolbox
    participant Handler as Registered tool handler
    participant Collector as ToolCallResultHandler / batch collector

    Core->>Core: Persist assistant tool calls and batch identity
    Core->>Hooks: Evaluate call policy
    alt Require approval
        Hooks-->>Core: Suspend exact call awaiting answer
    else Block or replace
        Hooks-->>Core: Result without invoking original handler
    else Allow
        Core->>Queue: ExecuteToolCall
        Queue->>Worker: Deliver envelope
        Worker->>Executor: Execute domain ToolCall
        Executor->>Executor: Check tool access and identity
        Executor->>Store: Find previously stored result
        alt Recorded outcome exists
            Store-->>Executor: Reuse result
        else No recorded outcome
            Executor->>Toolbox: Execute registered tool
            Toolbox->>Handler: Resolve arguments and invoke
            Handler-->>Toolbox: Value or error
            Toolbox-->>Executor: Tool result
            Executor->>Store: Record outcome
        end
        Executor-->>Worker: Result
        Worker->>Core: ToolCallResult on command bus
    end
    Core->>Collector: Match current batch and tool-call identity
    alt Other calls still pending
        Collector->>Collector: Retain partial batch results
    else Batch complete
        Collector->>Core: Ordered tool messages
        Core->>Core: Commit messages, dispatch AdvanceRun
    end
```

Result hooks and output capping can transform the returned presentation. Large
outputs become bounded text plus a temporary-file inspection notice. A stored result
lookup does not prevent two concurrent workers from starting the same side effect.

## Two human continuations

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    actor User
    participant Model
    participant Tool as Tool execution
    participant Hook as Approval policy
    participant Runtime as Run control and pending request
    participant TUI as Question UI

    alt Model asks a question
        Model->>Tool: ask_human(question, kind or choices)
        Tool->>Runtime: Suspend with model question
        Runtime-->>TUI: human_input.requested
        TUI-->>User: Render question
        User->>TUI: Answer or cancel
        TUI->>Runtime: answer_human for exact request
        Runtime->>Tool: Resume waiting question call with answer
        Tool->>Runtime: Model-visible answer result
        Runtime->>Model: Continue with result at next model step
    else Policy requires tool approval
        Tool->>Hook: Check original tool call
        Hook->>Runtime: RequireApproval and hook identity
        Runtime-->>TUI: Approval question
        TUI-->>User: Allow, block, or replace-result choice
        User->>TUI: Decision
        TUI->>Runtime: answer_human for exact request
        Runtime->>Hook: Originating ApprovalAnswerHookInterface
        alt Allow
            Hook->>Tool: Redispatch exact stored call
            Tool->>Runtime: Tool result
        else Block or replace
            Hook->>Runtime: Result without original execution
        end
        Note over Hook,Model: Approval itself needs no extra model decision turn
    end
```

A cancelled model question returns `Cancelled by user`; it is not an answer to
invent or an approval. Parent and child requests must retain their owning run ID.

## MCP connection and invocation lifecycle

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    participant Config as User and project mcp.json
    participant Runtime as Session runtime
    participant Q as mcp consumer
    participant Manager as McpConnectionManager
    participant Server as STDIO or HTTP server
    participant Catalog as Session tool catalog
    participant Model as Model tool call

    Config->>Runtime: Merge by server name, project replaces whole server entry
    Runtime->>Q: McpInitializeSessionCommand
    Q->>Manager: Initialize session connections
    Manager->>Server: Connect and negotiate
    Server-->>Manager: Advertised tools
    Manager->>Catalog: Register filtered namespaced tools
    Catalog-->>Model: Schemas in allowed per-turn toolset
    Model->>Runtime: Call MCP-backed tool
    Runtime->>Q: ExecuteToolCall with mcp transport stamp
    Q->>Manager: Invoke on active connection
    Manager->>Server: Tool name and arguments
    Server-->>Manager: Result or protocol error
    Manager-->>Q: Converted tool result
    Q-->>Runtime: ToolCallResult through run_control
    opt User requests reconnect while no active run
        Runtime->>Q: Disconnect and refresh catalog commands
        Q->>Manager: Drop clients and rediscover
        Manager->>Server: Reconnect
        Manager->>Catalog: Rewrite discovered catalog
    end
    Runtime->>Q: Stop owned consumer
    Q->>Manager: Best-effort graceful disconnect
    Manager->>Server: Close transport
```

Connection construction timeouts and in-flight tool deadlines are different.
Hatfield cannot enforce arbitrary per-call MCP timeout or cancellation through the
current SDK integration. Unmanaged grandchildren of STDIO servers can escape graceful
shutdown; do not interpret disconnect as a sandbox guarantee.

Sources: [tool execution](../docs/tool-execution.md), [MCP](../docs/mcp.md),
[approvals](../docs/approvals.md), [human input](../docs/human-input.md),
[Messenger routing](../config/packages/messenger.yaml).
