# TransformContextHookInterface

**File:** `TransformContextHookInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Hook`

Intercepts the message list before LLM processing. Can filter, augment, reorder, or rewrite messages.
Returns the transformed message list. Accepts an optional `CancellationTokenInterface`.

# ConvertToLlmHookInterface

**File:** `ConvertToLlmHookInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Hook`

Converts the internal `AgentMessage` list into a `MessageBag` suitable for LLM provider consumption.
This is the bridge between domain messages and the provider-specific format.

# BeforeProviderRequestHookInterface

**File:** `BeforeProviderRequestHookInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Hook`

Called just before the LLM provider is invoked. Receives model, input, and options.
Can return a `ProviderRequest` to modify/override the request, or null to proceed unchanged.

# BeforeToolCallHookInterface

**File:** `BeforeToolCallHookInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Hook`

Intercepts before a tool call is executed. Can return a `BeforeToolCallResult` to modify parameters or skip the call entirely.

# AfterToolCallHookInterface

**File:** `AfterToolCallHookInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Hook`

Intercepts after a tool call completes. Can return an `AfterToolCallResult` to modify the result or inject additional data.

# CancellationTokenInterface

**File:** `CancellationTokenInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Hook`

Cooperative cancellation contract. Checked at hook points to abort long-running operations.

# NullCancellationToken

**File:** `NullCancellationToken.php`
**Namespace:** `Ineersa\AgentCore\Contract\Hook`

Read-only value object implementing `CancellationTokenInterface`. Always returns `false` — used as default when no cancellation is needed.

# SteeringMessagesProviderInterface

**File:** `SteeringMessagesProviderInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Hook`

Provides steering messages that are injected at the start of the context to guide agent behavior (system instructions, constraints).

# FollowUpMessagesProviderInterface

**File:** `FollowUpMessagesProviderInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Hook`

Provides follow-up messages appended after the main context. Useful for post-instructions or trailing context.
