---
builtin: true
description: Registering extension tools, handlers, hooks, rewrites, results, and approvals.
---

# Registering Extension Tools

## Tool registration

```php
$api->registerTool(new ToolRegistrationDTO(
    name: 'my_tool',
    description: 'Does one clear thing',
    parametersJsonSchema: [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string'],
        ],
        'required' => ['path'],
        'additionalProperties' => false,
    ],
    handler: $handler,
    promptSummary: 'my_tool — short prompt line',
    promptGuidelines: ['When to use…'],
    timeoutSeconds: 30,
));
```

Handlers implement `ExtensionToolHandlerInterface` (arguments only) or
`ContextualExtensionToolHandlerInterface` (arguments + `ToolInvocationContextDTO`).
Respect `ToolCancellationTokenInterface` when performing long work.

## Tool-call hooks

`registerToolCallHook()` runs before execution in registration order. First non-allow wins.

```php
ToolCallDecisionDTO::allow();
ToolCallDecisionDTO::block($reason, $details);
ToolCallDecisionDTO::replaceResult($result, $details);
ToolCallDecisionDTO::requireApproval($prompt, $questionId, $schema, $details);
```

When requiring approval, implement `ApprovalAnswerHookInterface` to map the human answer
to allow / block / replace-result. Allow re-runs the **exact** original tool call.

## Argument rewrites

`registerToolCallRewriteHook($toolName, $hook)` can adjust arguments for a specific tool
before handler execution (policy normalization, path rewriting, etc.).

### Argument shapes

Rewrite hooks (and result hooks, via `ToolCallContextDTO` / the succeeded/failed events)
receive the **provider-visible flat argument map** for **both** typed built-in tools
(`read`, `write`, `edit`, `bash`, `bg_status`, `view_image`, `ask_human`, `subagent`,
`fork`, `agent_retrieve`, `hatfield_docs`) and raw dynamic tools (MCP tools,
extension-registered tools, `settings`), e.g. `['path' => './file.txt', 'offset' => 10]`.

Typed built-ins expose DTO fields at the top level of the map (matching their flat
provider-visible JSON Schema). The host wraps the flat map internally for native
Symfony AI DTO resolution, so hooks never see an `arguments` nesting envelope.
Rewrite hooks must return the same flat shape.

## Tool-result hooks

`registerToolResultHook()` runs after tool execution in registration order.

**Host behavior today is observational:** Symfony AI `ToolCallSucceeded` / `ToolCallFailed`
events expose readonly result data, so replacement decisions from
`ToolResultDecisionDTO` are **not applied** back to the live tool result even if the DTO
exposes a replace kind. Write hooks for side effects/logging; do not rely on result
mutation until a host release documents applied replacements.

## Naming and safety

- Prefer clear, collision-resistant tool names.
- Never shell-interpolate untrusted strings; use `exec()` argv APIs for processes.
- Do not bypass approvals by calling host internals.

See also [extension-api.md](extension-api.md) and [extension-api-runtime.md](extension-api-runtime.md).
