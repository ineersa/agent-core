# PHP code mode

[Architecture map](README.md) · [Tools and MCP](tools-and-mcp.md) · [Processes and queues](processes-and-queues.md)

Code mode lets the model write one PHP script that calls existing tools, processes
intermediate results, and returns selected data. The intermediate results stay out
of model history but remain available for inspection.

This is a discovery proposal, not an implemented feature or a finalized public API.
Source references describe `0175446eca68f3db6aa6f3e07f9d3123524c0d19`. The trust
model incorporates the user's clarification after the initial discovery report.

## What changes for the model

Today, each round of tool calls returns results to the model before the model can
choose the next computation. Code mode moves that computation into PHP.

```mermaid
flowchart LR
    subgraph Current[Current tool loop]
        M1[Model selects calls] --> T1[Tools return rows]
        T1 --> M2[Model consumes rows and selects next calls]
        M2 --> T2[Tools return more rows]
        T2 --> M3[Model combines results]
    end
    subgraph Proposed[Proposed code mode]
        M4[Model supplies one PHP script] --> PHP[PHP calls tools and combines rows]
        PHP --> Final[Selected final value]
        Final --> M5[Model continues]
        PHP --> Artifacts[Inspectable intermediate calls and results]
    end
```

For example, a script could call two available MCP data tools, join their rows,
calculate totals, and return CSV. The model would receive the CSV and a compact
execution summary, not both source datasets. This does not require new database or
export tools.

The proposed v1 has one script input, one `tool(name, arguments)` bridge, serial
calls, and one final return value. It has no workflow definitions, automatic
retries, saved program state, child-agent orchestration, or cross-session execution.

## Where existing execution can be reused

Built-ins, extensions, and MCP tools converge on `ToolRegistry`. They differ in
registration and argument handling, but code mode should not call their handlers
directly.

```mermaid
flowchart TB
    Builtin[HatfieldToolProviderInterface] --> Registry[ToolRegistry]
    Extension[ExtensionToolRegistryBridge] --> Adapter[ExtensionToolHandlerAdapter]
    Adapter --> Registry
    MCP[MCP discovery and session catalog] --> Registrar[McpToolRegistrar]
    Registrar --> Registry
    Registry --> Set[Resolved active tool set]
    Set --> Model[Model-visible schemas]
    Model --> Dispatch[ExecuteToolCall on execution bus]
    Dispatch --> Worker[ExecuteToolCallWorker on tool or mcp transport]
    Worker --> Executor[ToolExecutor]
    Executor --> Access[Stored-result lookup, cancellation, active allowlist]
    Access --> Toolbox[RegistryBackedToolbox]
    Toolbox --> Rewrite[Argument rewrite hooks and definition refresh]
    Rewrite --> Native[Native Symfony AI Toolbox]
    Native --> Hooks[Tool-call hooks and approval policy]
    Hooks --> Args[Argument resolution]
    Args --> Typed[Validator for typed DTO arguments]
    Args --> Raw[Raw dynamic argument map]
    Typed --> Handler[Registered handler]
    Raw --> Handler
    Handler --> Result[Value, exception, or control outcome]
```

This is the current path, abbreviated to show the reusable parts. A hook can block,
replace a result, or require approval before the handler executes.

`ToolExecutor` provides execution context, cancellation checks, result reuse, and
allowlist enforcement. Its allowlist check is skipped when `tools_ref` is absent.
A code-mode request therefore needs host-supplied run identity and an active tool
reference. Script arguments must not supply either field.

`RegistryBackedToolbox` applies rewrites and native Symfony argument resolution.
Typed built-ins use DTO validation. Raw extension and MCP handlers receive their
argument maps; their schemas do not imply the same host-side validation.

The bridge should use this execution path while adding nested-call identity and an
internal result destination. Calling `ToolExecutor` alone does not provide those
features. Calling a registry handler directly would skip policy and context.

Sources: [ToolRegistry](../src/CodingAgent/Tool/ToolRegistry.php),
[RegistryBackedToolbox](../src/CodingAgent/Tool/RegistryBackedToolbox.php),
[ToolExecutor](../src/AgentCore/Application/Handler/ToolExecutor.php),
[extension registration](../src/CodingAgent/Extension/ExtensionToolRegistryBridge.php),
[extension handler adapter](../src/CodingAgent/Extension/ExtensionToolHandlerAdapter.php).

## Proposed process and call flow

PHP runs in a separate process for cancellation, resource limits, and crash
isolation. It receives a minimal bootstrap with `tool()`, not the application
Composer autoloader, container, registry, or provider clients.

The diagram uses **script owner** and **host bridge** as proposed responsibilities,
not names of existing classes. Their exact runtime placement remains to be designed.
This sequence shows successful completion. Approval and failure exits appear below.

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    participant Model
    participant Owner as Script owner
    participant PHP as PHP subprocess
    participant Bridge as Host bridge
    participant Worker as Existing tool or mcp consumer
    participant Store as Owned artifacts
    participant RC as run_control

    Model->>Owner: Outer code-mode call with script source
    Owner->>Owner: Reserve outer execution identity
    Owner->>PHP: Start minimal bootstrap and script
    loop Each serial tool call
        PHP->>Bridge: tool(name, arguments) over bounded IPC
        Bridge->>Bridge: Assign nested ID and check current tool access
        Bridge->>Store: Record dispatch intent and correlation
        Bridge->>Worker: Nested request through execution bus
        Worker->>Worker: ToolExecutor, toolbox, hooks, handler
        Worker-->>Bridge: Internal nested outcome
        Bridge->>Store: Record outcome and permitted payload
        Bridge-->>PHP: Typed value or terminal stop
        PHP->>PHP: Filter, aggregate, or transform values
    end
    PHP-->>Owner: Final return value
    Owner->>Owner: Reap process and finalize host-owned status
    Owner->>RC: Outer result with bounded value and inspection reference
    RC-->>Model: One matching tool result, then next model step
```

Nested requests and responses are new internal routing work. The existing worker
and result handler currently serve top-level calls. A nested response must return
to the bridge without creating an extra model tool message.

MCP calls must still reach the MCP consumer that owns the connections. A direct
invoker call from another worker would bypass routing and could reconnect through
a second client. The script owner also cannot occupy the only tool worker while
waiting for that worker to execute a nested built-in call. Controller-owned
supervision or an explicit worker handoff must resolve this dependency. Increasing
worker counts is not a deadlock fix.

Sources: [ExecuteToolCallWorker](../src/AgentCore/Application/Handler/ExecuteToolCallWorker.php),
[MCP routing middleware](../src/CodingAgent/Mcp/Messenger/McpExecuteToolCallRoutingMiddleware.php),
[Messenger routes](../config/packages/messenger.yaml),
[McpToolInvoker](../src/CodingAgent/Mcp/Tool/McpToolInvoker.php).

## Values must separate from model presentation

The current path loses information before a script could consume it. Native
Symfony tool results can contain PHP values, but `ToolExecutor::toDomainResult()`
also renders text and runs output processors. Output capping can remove
`details.raw_result` and retain only a reference to rendered text.

MCP loses structure earlier. `McpSdkClientAdapter::callTool()` explicitly omits
`structuredContent`. `McpResultMapper` joins text blocks and replaces binary content
with placeholders. Some built-ins, such as `SettingsTool` and `BgStatusTool`, already
return TOON strings.

```mermaid
flowchart TB
    subgraph Current[Current result path]
        SDK[MCP SDK result] --> Drop[Adapter retains content and isError only]
        Drop --> Mapper[Mapper joins text and substitutes binary placeholders]
        Native[Native handler value] --> Convert[ToolExecutor renders content and stores raw_result]
        Mapper --> Convert
        Convert --> Cap[Output cap may remove raw_result]
        Cap --> Projection[ToolCallResultHandler appends model message]
    end
    subgraph Proposed[Proposed nested result path]
        Value[Native value or preserved MCP result] --> Outcome[Policy and terminal-outcome classification]
        Outcome --> Codec[Bounded data normalization and IPC encoding]
        Codec --> PHP[PHP value or explicit omitted-result reference]
        Outcome --> Artifact[Owned inspection artifact]
        PHP --> Selected[Script selects final value]
        Selected --> Presentation[Existing final presentation and output cap]
        Presentation --> Outer[Outer model tool result only]
    end
```

The proposed split happens before model formatting, not before policy. Result hooks,
denials, and failure classification still apply. It cannot be implemented by reading
`raw_result` after all current processors have finished.

### Script input and output

The recommended input is one required script-source string, executed as a PHP body.
Local variables hold intermediate values, and `return` supplies the final value.
File-path execution and dependency installation are not part of v1.

`tool(string $name, array $arguments)` takes the existing runtime tool name and flat
argument map. A successful call returns a small envelope that distinguishes
structured data, text, and an omitted value. Exact field names remain unapproved.

Structured data supports scalars, lists, and objects as data. A structured null is
different from no structured value. Supported DTOs use Symfony Serializer
normalization on the host. Executable PHP objects, resources, cyclic values,
non-finite numbers, and invalid text encodings produce bounded diagnostics.

A separate process requires serialization. A framed, bounded JSON-compatible IPC
format is sufficient; a model-facing TOON round trip is not. The codec must preserve
list versus object identity and define integer range behavior. It must bound frame
size, nesting depth, and total bytes rather than allocate an arbitrary payload first.

### Different result kinds need different handling

| Result | Proposed PHP behavior | Required change or limit |
|---|---|---|
| Native structured value | Receive data without model-text conversion | Separate data normalization from presentation |
| MCP structured result | Receive `structuredContent`, with content blocks retained separately | Extend the internal SDK adapter and mapper path |
| Text-only result | Receive a string | Decode JSON or TOON explicitly only when the script knows the format |
| Binary content | Receive metadata and an inspection reference | Retain permitted bytes in owned storage, not base64 in model history |
| Oversized intermediate value | Receive an explicit omitted-result reference | Never present truncated data as complete; bounded text inspection can use `read` |
| Failure, denial, approval, or deferred completion | Receive a control outcome, not application data | Classify at the owning boundary; do not infer status from arbitrary text |

Some existing hook denials are arrays or TOON strings containing `denied`, rather
than `isError=true`. The typed outcome work must address that inconsistency at the
hook boundary. Scanning ordinary output for words such as `error` is not a substitute.

The final return value uses the same supported data types. Stdout and stderr are
bounded diagnostics, not an implicit second return channel. The outer result includes
the selected value, execution status, completed-call count, and inspection reference.
Existing output capping limits final model presentation. A separate aggregate
artifact budget prevents capping from causing unlimited disk growth.

Sources: [ToolExecutor conversion](../src/AgentCore/Application/Handler/ToolExecutor.php),
[MCP SDK adapter](../src/CodingAgent/Mcp/Client/McpSdkClientAdapter.php),
[MCP result mapper](../src/CodingAgent/Mcp/Tool/McpResultMapper.php),
[output-cap processor](../src/CodingAgent/Tool/OutputCapToolResultProcessor.php),
[tool-result projection](../src/AgentCore/Application/Pipeline/ToolCallResultHandler.php),
[hook outcomes](../src/CodingAgent/Extension/ExtensionToolHookEventSubscriber.php).

## Trust matches bash; bridge calls retain tool policy

The user confirmed arbitrary PHP execution under the same trust model as bash.
Code mode does not require an OS sandbox or a new filesystem or network deny policy.
The subprocess boundary protects worker lifecycle and state, not against hostile code.

```mermaid
flowchart LR
    Script[PHP script] --> Bridge[Only supplied application function: tool]
    Bridge --> Policy[Active tool set, rewrites, hooks, approvals]
    Policy --> Allowed[Built-ins, extensions, available MCP tools]
    Policy --> Denied[Reject agent launch and continuation paths]
    Script --> Native[Native PHP filesystem, network, and process functions]
    Native --> OS[Existing host permissions, as with bash]
    Bootstrap[Minimal bootstrap] --> Script
    Absent[No application autoloader or container supplied] -.-> Bootstrap
```

The bridge rejects `subagent`, `fork`, and `agent_resume`, including aliases,
rewrites, and supported wrappers that expose the same launch or continuation.
Recursive code mode and deferred-agent completion are not composition paths.
The script receives no extension `agent()` or job-dispatch object.

These restrictions concern the provided bridge. They do not require banning bash,
auditing remote MCP servers for internal agent use, or preventing PHP from
deliberately loading application files or invoking a CLI. That would impose a
stronger restriction than the existing arbitrary-code tools.

The host assigns execution identity and checks the current resolved tool set on
each bridge call. Checks must also cover rewritten calls before handler invocation.
An earlier snapshot must not preserve a revoked capability. Parent code mode cannot
import MCP tools hidden behind `availability: specific` or child-only selectors.

Sources: [parent MCP visibility](../src/CodingAgent/Mcp/Tool/McpParentAvailabilityToolSetResolver.php),
[child tool policy](../src/CodingAgent/Agent/Execution/SubagentToolSetResolver.php),
[extension host API bridge](../src/CodingAgent/Extension/ExtensionToolRegistryBridge.php),
[existing process execution](../src/CodingAgent/Extension/ExtensionExecBridge.php).

## Approval cannot restart the whole script

Current approval suspends an exact tool call before its handler executes. Run control
stores the request, and the answer requeues that call with typed correlation. This
is safe for one unstarted handler. It is not safe for an outer script that has
already performed writes.

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    participant PHP as PHP script
    participant Bridge as Host bridge
    participant Hooks as Existing approval hooks
    participant Worker as Tool handler
    participant Store as Call ledger
    participant RC as Outer completion through run_control
    participant Model

    PHP->>Bridge: First tool call
    Bridge->>Worker: Execute through normal policy and dispatch
    Worker-->>Bridge: Successful result
    Bridge->>Store: Record completed call and result
    Bridge-->>PHP: Value
    PHP->>Bridge: Second tool call
    Bridge->>Hooks: Evaluate exact nested call
    Hooks-->>Bridge: Require approval before handler execution
    Note over Bridge,Hooks: Recommended v1 behavior, pending approval
    Bridge->>Store: First call completed; second call not executed
    Bridge-->>PHP: Terminal approval-required stop
    Bridge->>RC: Complete outer call with reason and references
    RC-->>Model: Matching outer tool result
    Note over PHP,Model: No outer approval resume and no automatic script restart
```

The recommendation is to stop v1 scripts when a nested call needs human input.
The protected handler does not run. The result explains which earlier calls completed
and identifies the approval-required call. An outer approval never authorizes later
nested calls. Explicit `ask_human` is also excluded under this recommendation.

This remains a product decision because it restricts composition of otherwise
available tools. The alternative is to keep the owned PHP process alive, paused on
the exact nested response while the user answers. That needs live-process ownership,
correlated approval delivery, and a deadline. A crash still ends the script.
Persisting PHP locals or replaying earlier calls would introduce workflow recovery
that is outside scope.

Sources: [approval suspension and answer handling](../src/CodingAgent/Extension/ExtensionToolHookEventSubscriber.php),
[run-control continuation](../src/AgentCore/Application/Pipeline/ApplyCommandHandler.php),
[SafeGuard approval decisions](../src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardToolCallHook.php).

## Failure and cancellation preserve partial outcomes

The host owns terminal status. The recommendation is to stop at the first failed
nested call and close admission to further calls, even if PHP catches a bridge
exception. A script return value cannot overwrite a recorded failure or cancellation.

```mermaid
flowchart TD
    Call[Nested request] --> Dispatched{Dispatched?}
    Dispatched -->|No, policy denied| Denied[Record not executed]
    Dispatched -->|Yes| Result{Observed outcome}
    Result -->|Success| Completed[Record completed call and return value]
    Completed --> Next[Next serial call or final return]
    Result -->|Confirmed failure| Failed[Record failure and handler operation references]
    Result -->|Delivery lost| Unknown[Record outcome unknown]
    Result -->|Cancellation requested| Cancel[Close admission and request nested cancellation]
    Cancel --> Support{Nested operation can confirm stop?}
    Support -->|Yes| Cancelled[Record confirmed cancellation]
    Support -->|No| Unknown
    Denied --> Stop[Stop script and reap owned resources]
    Failed --> Stop
    Unknown --> Stop
    Cancelled --> Stop
    Stop --> Summary[Outer status, completed calls, failed or uncertain call, inspection reference]
```

A failed call may itself have partial effects. Its existing operation and log
references remain useful, so the outer result retains them. A lost response after
dispatch means unknown outcome, not proof that execution failed or never started.
This follows the [tool-failure work in PR 483](https://github.com/ineersa/agent-core/pull/483).

The script owner owns its subprocess, pipes, scratch directory, and pending requests.
Cancellation stops new calls, requests cancellation of the active nested operation,
and reaps owned processes. It must not signal unrelated, root-owned, or active-session
workers. Code mode offers no background execution. Native PHP process creation still
has the bash-equivalent trust model, not a security guarantee against deliberate detachment.

MCP is a known limit. The current invoker and connection manager do not pass per-call
cancellation or deadlines to the SDK. Stopping PHP cannot prove remote work stopped.
The implementation must either depend on tracked task
`add-proper-mcp-tool-call-cancellation` or report the uncertain remote outcome under
an approved limitation. Killing a shared MCP worker is not a substitute.

No automatic retries, rollback, script resume, or exactly-once guarantee is proposed.
Stored-result reuse covers recorded outcomes, not concurrent execution or a crash
before recording. The host must reserve the outer attempt before side effects and
refuse automatic re-entry on duplicate or ambiguous delivery. That requires limited
attempt evidence, not a stored PHP program counter or a new operation database.

Sources: [executor outcome reuse](../src/AgentCore/Application/Handler/ToolExecutor.php),
[MCP invocation limits](../src/CodingAgent/Mcp/Tool/McpToolInvoker.php),
[MCP connection manager](../src/CodingAgent/Mcp/Client/McpConnectionManager.php),
[run cancellation token](../src/AgentCore/Infrastructure/SymfonyAi/RunCancellationToken.php).

## Inspection stays separate from model history

Each nested call gets a host-generated ID and ordinal under the outer tool-call ID.
The ledger records tool identity, dispatch and terminal status, duration, and any
handler-provided operation reference. Payload retention follows the approved privacy
and storage limits.

```mermaid
flowchart LR
    Outcome[Nested call and result] --> Ledger[Owned ledger and permitted payload artifacts]
    Outcome --> Logs[Correlation IDs, status, bounded sanitized cause]
    Ledger --> Read[Explicit inspection with read or view_image]
    Final[Final script value and host status] --> Cap[Existing output cap]
    Cap --> History[Outer tool result in canonical history]
    History --> Model[Next model request]
    Ledger --> Ref[Compact inspection reference]
    Ref --> History
```

`OutputCap` already provides run-hashed temporary text storage, locks, path checks,
and cleanup. It does not provide a structured call ledger or binary retention.
Its 24-hour stale cleanup also means an inspection reference is not durable session
history. Expired or quota-omitted data must be identified as unavailable.

Existing bounded `read` can inspect the ledger and text, and `view_image` can inspect
supported images. Returning a reference does not mount data into PHP or grant new
permissions. Direct PHP file access still follows host permissions.

Logs exclude script source, raw arguments, raw outputs, credentials, and stack dumps.
Only the final value and compact status references enter model history by default.
Local artifacts can still contain secrets. The recommendation is owner-only access,
short retention, and metadata-only records where payload retention is prohibited.
Pattern-based error redaction is not proof that arbitrary data is non-sensitive.

Sources: [OutputCap](../src/CodingAgent/Tool/OutputCap.php),
[model result projection](../src/AgentCore/Application/Pipeline/ToolCallResultHandler.php),
[diagnostic sanitizer](../src/AgentCore/Contract/Tool/DiagnosticMessageSanitizer.php),
[logging privacy](../docs/datadog.md#principles).

## Remaining decisions and implementation order

The trust model is settled. A separate PHP process with one supplied application
bridge fits the existing bash permissions. In-process execution would share worker
state and complicate cancellation. A sandbox or subset interpreter would add a
security model the user has rejected for this task.

Four product decisions remain:

| Decision | Recommendation |
|---|---|
| Nested approval | Stop before the protected handler in v1; no automatic restart |
| Resource and artifact limits | Explicit execution and storage budgets, private short-lived artifacts; numeric limits still need approval |
| TOON decoding | Decide whether to bundle a standalone decoder without the application autoloader |
| MCP cancellation | Require cancellation work or explicitly approve unknown-remote-outcome reporting |

The implementation can then proceed in this order:

```mermaid
flowchart LR
    Process[Owned PHP process and minimal bootstrap] --> Values[Typed outcomes before presentation, including MCP data]
    Values --> Bridge[Serial bridge and internal nested routing]
    Bridge --> Records[Attempt evidence, inspection artifacts, bounded final output]
    Records --> Tool[One tool registration and usage documentation]
```

Process work includes crash handling and teardown in supported packaged runtimes.
Routing work must preserve MCP connection ownership and avoid worker self-deadlock.
The bridge retains current access policy and excludes agent orchestration.

CodingAgent owns concrete process, tool, MCP, and extension adapters. AgentCore gets
only neutral contracts that genuinely belong to shared execution. The
[Deptrac rules](../depfile.yaml) remain authoritative. No new public ExtensionApi
contract is justified solely to support this internal bridge.

## Deterministic proof plan

This discovery changes documentation only. The scenarios below describe required
proof for an approved implementation, not tests already written or run. The testing
skill and `tests/AGENTS.md` were loaded before test investigation.

| Scenario | Required observation | Lowest useful layer |
|---|---|---|
| Two data tools, join, sum, and CSV | Exact final CSV; source rows absent from model history and present in permitted artifacts | Container tests for values; one controller replay for projection |
| Structured null, list, object, text, and TOON | Distinctions survive IPC; text is decoded only explicitly | Codec and adapter tests |
| Denied write after a completed call | Denied handler never runs; earlier result remains inspectable; no later dispatch | Hook and bridge tests |
| Approval, hook failure, or excluded launch wrapper | Exact control outcome; no handler or child execution | Hook and bridge tests |
| Failure after a side effect | Bounded cause and operation reference; no retry or repeated side effect | Bridge and delivery tests |
| Lost response and duplicate outer delivery | Unknown outcome remains unknown; duplicate does not re-enter script | Deterministic delivery barriers |
| Cancellation during a nested request | Admission closes; owned process exits; nested cancellation or explicit unknown remote outcome | Process test with pipe or socket barrier |
| Oversized structured, text, binary, and stdout output | Transport and storage limits hold; inspectable references or explicit omission; no raw payload in logs or model details | Codec, artifact, and projection tests |
| Minimal bootstrap and owner crash | Bridge works without application autoload; internal service access is unavailable; owned resources are reaped | Real subprocess test |

Existing entry points include `ToolExecutorTest`, `ExtensionToolHookEventSubscriberTest`,
`McpResultMapperTest`, and `OutputCapToolResultProcessorContractTest`. DB-touching
cases use the isolated kernel test container. Tests should extend the owning contract
rather than duplicate it across layers.

Every normal case must finish within 10 seconds and own its temporary resources.
Synchronization uses explicit barriers, not arbitrary sleeps or timeout increases.
Direct PHP filesystem, network, and process operations are not expected to fail under
a sandbox policy. Live LLM proof is reserved for the eventual tool schema, not data
aggregation or process ownership.

Focused validation goes through Castor. The eventual CODE-REVIEW transition owns
`castor check`. For this report, `castor docs:validate` checks the existing built-in
document catalog; this repository-only proposal is outside that catalog. Source,
link, and diagram review must therefore also cover the report itself.
