# Tool Execution

How Hatfield registers, schedules, and executes tools inside CodingAgent. See the
[tool design and execution diagrams](../architecture/tools-and-mcp.md) and the
[user-facing tool catalog](tools.md).

## Registration

Built-in tools implement `HatfieldToolProviderInterface` and are tagged into `ToolRegistry`.
Extensions register via `ExtensionApiInterface::registerTool(ToolRegistrationDTO)`.
MCP servers contribute tools after connection ([mcp.md](mcp.md)).

Each tool has: name, description, JSON Schema parameters, handler, execution mode
(`sequential` / `parallel`), and optional prompt summary/guidelines/timeout metadata.

## Scheduling and transport

`tools.execution.default_mode` and `max_parallelism` configure worker policy.
Per-tool mode is owned by registration (`ToolDefinitionDTO`), not arbitrary settings overrides.
File mutation tools are sequential.

There is **no** global `ToolExecutor` timeout that rewrites successful late results.
Bash and deferred subagent supervision own their deadlines. Registration can declare
a tool-specific budget, but MCP call-level cancellation and deadlines are not
enforced by the current integration. MCP connection timeouts are a separate concern.

Messenger default routing sends `ExecuteToolCall` to the `tool` transport. Overrides:

- `subagent` and `agent_resume` → `agent` transport (`SubagentExecuteToolCallRoutingMiddleware`)
- MCP-backed calls → `mcp` transport (`McpExecuteToolCallRoutingMiddleware`)
- `fork` stays on the default `tool` transport

See [async-runtime-architecture.md](async-runtime-architecture.md).

## Pipeline

1. Model emits tool calls (flat provider arguments: typed built-ins expose DTO fields at the top level; raw dynamic tools keep their flat runtime schema map).
2. Registry/toolbox validates names + arguments (typed built-ins via native Symfony AI resolution + Validator; raw dynamic tools pass through to their handler/server).
3. Tool-call hooks run (allow / block / replace / require approval).
4. Workers execute handlers with cancellation tokens where supported.
5. Tool-result hooks may adjust presentation.
6. Results return to `run_control`, append to the run, and feed the next model step.

Output capping persists oversized text under `tools.output_cap.*` and injects inspection notices ([settings.md](settings.md), [session-storage.md](session-storage.md)). Document-like tools (`hatfield_docs`, handoff-style tools) use the larger doc cap.

## Result reuse versus exclusion

`ToolExecutor` can reuse a stored result for the same run/`tool_call_id` or matching tool idempotency key. That is **dedupe / idempotent result reuse**, not mutual exclusion and not an exactly-once execution guarantee.

At-least-once Messenger delivery can still re-enter a handler. Concurrent duplicate work is constrained by run-control identity checks, tool-batch snapshots, and transport claim behavior — not by “a stored result exists, therefore no other worker can run.” Treat reuse as recovery of an already-recorded outcome.

## Approvals and human input

- Approvals: [approvals.md](approvals.md)
- Questions: [human-input.md](human-input.md)

## Related

- Runtime topology: [async-runtime-architecture.md](async-runtime-architecture.md)
- Agents / fork / resume: [agents.md](agents.md)
- Extension authoring: Extension API docs under `.hatfield/extensions/extension-api/docs/`
