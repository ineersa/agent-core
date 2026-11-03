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

Rewrite and result hooks receive the flat provider-visible argument map (typed built-ins and raw dynamic tools alike); rewrite hooks must return the same flat shape.

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

## Standalone invocation and owner notifications

`ExtensionApiInterface::tools()->invoke(ToolInvocationRequestDTO)` executes a registered tool through the current owner's native tool policy and result processors. Requests carry owner run ID, stable invocation ID, tool name, arguments and optional `retainOutput`. Invocation is synchronous and noninteractive. It cannot detach, launch deferred child work, or ask for human input. Approval-required operations are denied. Results expose processed content, model-facing text, cancellation and structured output references. Retained files belong to the session and survive provisional output cleanup. This capability does not promise exactly-once execution.

`notifyOwner(OwnerNotificationDTO)` submits text through the canonical background-completion mailbox. The notification identity is idempotent at admission. An interrupted invocation must set `interrupted`; native admission and consumption retire interrupted notifications without reviving the owner. Notification delivery does not imply immediate model execution.

`registerRecurringJobSource()` contributes extension-owned definitions to Symfony Scheduler. Sources return `RecurringJobDTO` values with a stable ID, registered handler ID, JSON-safe payload, interval and lifetime. The handler must belong to the same enabled extension. Due occurrences use the existing extension-job worker on the tool transport; ordinary extension-agent dispatch retains its dedicated transport. Registration installs reconciliation only when an enabled extension contributes a source.

Set `ToolRegistrationDTO::mainOnly` for tools unavailable to children, including explicit child tool lists and nested calls.
