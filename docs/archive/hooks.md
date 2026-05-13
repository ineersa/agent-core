# Hooks Map

Agent Core exposes provider-boundary hooks and delegates tool lifecycle interception to Symfony AI Toolbox events.

## Available Hooks

1. **`ConvertToLlmHookInterface`**
   - **Purpose**: Modifies or formats messages right before they are sent to the LLM.
2. **`TransformContextHookInterface`**
   - **Purpose**: Allows modifying the agent's context or system prompt dynamically.
3. **`BeforeProviderRequestHookInterface`**
   - **Purpose**: Low-level hook right before the provider request is dispatched.
4. **`SteeringMessagesProviderInterface` / `FollowUpMessagesProviderInterface`**
   - **Purpose**: Provide dynamic steering or follow-up messages mid-run.
5. **Symfony AI Toolbox events** (`ToolCallRequested`, `ToolCallSucceeded`, `ToolCallFailed`)
   - **Purpose**: Intercept, deny, short-circuit, or observe tool execution through standard Symfony event listeners (`#[AsEventListener]` or tagged listeners).

## Implementation Flow

Provider hooks are registered through `HookSubscriberRegistry` and invoked by `HookDispatcher`:

```text
RunOrchestrator/Platform -> HookDispatcher -> [Registered Hook Subscribers] -> (Modified Payload) -> Provider Invocation
```

Tool lifecycle hooks are handled by Symfony AI's Toolbox event dispatcher:

```text
ToolExecutor -> Toolbox::execute() -> Symfony EventDispatcher -> [ToolCallRequested / ToolCallSucceeded / ToolCallFailed listeners]
```
