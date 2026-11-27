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

Handlers implement one of:

| Interface | Method |
|---|---|
| `ExtensionToolHandlerInterface` | `__invoke(array $arguments): mixed` |
| `ContextualExtensionToolHandlerInterface` | `__invoke(array $arguments, ToolInvocationContextDTO $context): mixed` |

`ToolInvocationContextDTO` carries `runId`, optional `cancellationToken`
(`ToolCancellationTokenInterface::isCancellationRequested()`) and optional
`timeoutSeconds`. Poll the token during long work; the host cannot preempt PHP
handlers. Return a string or array; output capping happens after execution.

## Hook interfaces

| Register with | Implement | Method |
|---|---|---|
| `registerToolCallRewriteHook` | `ToolCallRewriteHookInterface` | `rewriteArguments(ToolCallContextDTO): ?array` |
| `registerToolCallHook` | `ToolCallHookInterface` | `onToolCall(ToolCallContextDTO): ToolCallDecisionDTO` |
| (same hook object) | `ApprovalAnswerHookInterface` | `onApprovalAnswered(ApprovalAnswerContextDTO): void`, `resolveApprovalAnswer(ApprovalAnswerContextDTO): ToolCallDecisionDTO` |
| `registerToolResultHook` | `ToolResultHookInterface` | `onToolResult(ToolResultContextDTO): ToolResultDecisionDTO` |

`ToolCallContextDTO` has `toolCallId`, `toolName`, `arguments`, `orderIndex`,
nullable `runId`, `turnNo`, `cwd`, and `metadata`. `ToolResultContextDTO` adds
`isError`, `content` blocks and `details` (no `orderIndex`).

## Tool-call hooks

`registerToolCallHook()` runs before execution in registration order. First non-allow wins.

```php
ToolCallDecisionDTO::allow();
ToolCallDecisionDTO::block($reason, $details);
ToolCallDecisionDTO::replaceResult($result, $details);
ToolCallDecisionDTO::requireApproval($prompt, $questionId, $schema, $details);
```

A hook that throws denies the call (`extension_tool_call_hook_failed`).

When requiring approval, implement `ApprovalAnswerHookInterface` on the same hook to map
the human answer to allow / block / replace-result. The run pauses for the answer,
then the host resumes the **exact** original tool call and calls
`onApprovalAnswered()` and then `resolveApprovalAnswer()` on the originating hook only.
`ApprovalAnswerContextDTO` carries `questionId`, `answer` (string), `toolName`,
`approvalContext` (the original decision details), and nullable `runId` / `toolCallId`.
Allow runs the original call without an extra model turn. Returning
`requireApproval()` again, or throwing, denies the call (`approval_resume_failed`).

## Argument rewrites

`registerToolCallRewriteHook($toolName, $hook)` adjusts arguments for one tool name, or
for every tool with `'*'`, before tool-call hooks (including SafeGuard) and the handler
see them. Hooks for the exact name run first, then `'*'` hooks, each group in registration
order; each receives the latest arguments. Return the full rewritten map, or `null` to leave it unchanged.
Rewrite contexts set only `toolCallId`, `toolName`, `arguments` and `orderIndex`.

### Argument shapes

Rewrite and result hooks receive the flat provider-visible argument map (typed built-ins and raw dynamic tools alike); rewrite hooks must return the same flat shape.

## Tool-result hooks

`registerToolResultHook()` runs after tool execution in registration order. Return
`ToolResultDecisionDTO::keep()` or `ToolResultDecisionDTO::replace(?bool $isError,
?array $content, ?array $details)`; later hooks see the replaced values. A throwing
result hook is logged and skipped.

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

`registerRecurringJobSource($sourceId, $source)` contributes extension-owned definitions to Symfony Scheduler; `$sourceId` must be unique and nonempty. The host periodically calls `RecurringJobSourceInterface::jobs(string $runId)` with the session run ID. Sources return `RecurringJobDTO(id, handlerId, payload, every, from, until)`: a stable ID, a handler registered with `registerExtensionAgentJobHandler()`, a JSON-safe payload, a Symfony Scheduler interval such as `'10 minutes'`, and a lifetime. Jobs are skipped before `from` and from `until`; the first eligible occurrence runs on discovery. The handler must belong to the same enabled extension. Due occurrences use the existing extension-job worker on the tool transport; ordinary extension-agent dispatch retains its dedicated transport. Registration installs reconciliation only when an enabled extension contributes a source.

Set `ToolRegistrationDTO::mainOnly` for tools unavailable to children, including explicit child tool lists and nested calls.
