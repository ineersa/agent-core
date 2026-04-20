# Hooks Map

The Agent Core provides a hook system to intercept, modify, or cancel operations at key lifecycle boundaries.

## Available Hooks

1. **`ConvertToLlmHookInterface`**
   - **Purpose**: Modifies or formats messages right before they are sent to the LLM.
2. **`TransformContextHookInterface`**
   - **Purpose**: Allows modifying the agent's context or system prompt dynamically.
3. **`BeforeToolCallHookInterface`**
   - **Purpose**: Intercepts a tool call before execution. Can be used for validation, logging, or short-circuiting the call with a cached response.
4. **`AfterToolCallHookInterface`**
   - **Purpose**: Intercepts the result of a tool call before it's fed back into the agent's context. Useful for masking sensitive data or summarizing large outputs.
5. **`BeforeProviderRequestHookInterface`**
   - **Purpose**: Low-level hook right before the HTTP request to the LLM provider is made.
6. **`SteeringMessagesProviderInterface` / `FollowUpMessagesProviderInterface`**
   - **Purpose**: Provide dynamic steering or follow-up messages mid-run.

## Implementation Flow

Hooks are registered via the `HookSubscriberRegistry` and invoked by the `HookDispatcher` inside the Orchestrator and Workers.

```text
Worker -> HookDispatcher -> [Registered Hooks] -> (Modified Payload) -> Execution
```
