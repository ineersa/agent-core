# Tool Execution

How Hatfield registers, schedules, and executes tools inside CodingAgent.

## Registration

Built-in tools implement `HatfieldToolProviderInterface` and are tagged into `ToolRegistry`.
Extensions register via `ExtensionApiInterface::registerTool(ToolRegistrationDTO)`.
MCP servers contribute tools after connection ([mcp.md](mcp.md)).

Each tool has: name, description, JSON Schema parameters, handler, execution mode
(`sequential` / `parallel`), and optional prompt summary/guidelines/timeout metadata.

## Scheduling

`tools.execution.default_mode` and `max_parallelism` configure worker policy.
Per-tool mode is owned by registration (`ToolDefinitionDTO`), not arbitrary settings overrides.
File mutation tools are sequential.

There is **no** global ToolExecutor timeout that rewrites successful late results.
Bash, subagent, MCP, and explicit registration budgets enforce their own deadlines.

## Pipeline

1. Model emits tool calls.
2. Registry/toolbox validates names + arguments.
3. Tool-call hooks run (allow / block / replace / require approval).
4. Workers execute handlers with cancellation tokens where supported.
5. Tool-result hooks may adjust presentation.
6. Results append to the run and return to the model.

Output capping persists oversized text under `tools.output_cap.*` and injects inspection notices ([settings.md](settings.md)). Document-like tools (`hatfield_docs`, handoff-style tools) use the larger doc cap.

## Approvals and human input

- Approvals: [approvals.md](approvals.md)
- Questions: [human-input.md](human-input.md)

## Related

- Runtime topology: [async-runtime-architecture.md](async-runtime-architecture.md)
- Extension authoring: Extension API docs under `.hatfield/extensions/extension-api/docs/`
