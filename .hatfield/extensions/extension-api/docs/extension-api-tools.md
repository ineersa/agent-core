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

## Tool-result hooks

`registerToolResultHook()` observes/adjusts post-execution results in registration order.
Each hook sees the latest result state.

## Naming and safety

- Prefer clear, collision-resistant tool names.
- Never shell-interpolate untrusted strings; use `exec()` argv APIs for processes.
- Do not bypass approvals by calling host internals.

See also [extension-api.md](extension-api.md) and [extension-api-runtime.md](extension-api-runtime.md).
