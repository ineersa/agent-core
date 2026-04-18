# Stage 01 - JS Parity Contracts (Hooks and Events)

## Goal
Define explicit parity contracts with the JS agent loop so behavior is predictable and testable before implementation.

## Source of Truth
Parity target is based on:
- `packages/agent/src/types.ts`
- `packages/agent/src/agent-loop.ts`
- `packages/agent/src/agent.ts`

## Hook Parity Matrix
Implement these hooks in Symfony with equivalent semantics.

1. `convertToLlm(messages): MessageBag`
   - Required for custom message support.
   - Must be called before each LLM call.
   - Must return Symfony AI-compatible messages (`SystemMessage|UserMessage|AssistantMessage|ToolCallMessage`).
2. `transformContext(messages, cancelToken): AgentMessage[]`
   - Optional preprocessing before `convertToLlm`.
3. `getSteeringMessages(): AgentMessage[]`
   - Polled at loop boundaries, not mid-token.
4. `getFollowUpMessages(): AgentMessage[]`
   - Polled only when loop would otherwise stop.
5. `beforeToolCall(context, cancelToken): BeforeToolCallResult`
   - Executes after arg validation.
   - Can block tool execution.
6. `afterToolCall(context, cancelToken): AfterToolCallResult`
   - Runs before final tool result emission.
7. `beforeProviderRequest(model, input, options): ProviderRequest`
   - Optional per-turn request mutation before `PlatformInterface::invoke(...)`.
   - Semantics match Symfony AI `InvocationEvent` (model/input/options override).

## Core Extensibility Seams
Add minimal extension seams now so higher-level runtime features (for example compaction/session workflows) can be implemented later without changing core loop internals.

### Extensible Commands
- Core command kinds are fixed: `steer|follow_up|cancel|human_response|continue`.
- Allow extension command kinds using namespaced prefix `ext:` (for example `ext:compaction:compact`).
- Extension command envelope supports optional reserved metadata: `options.cancel_safe: boolean` (default `false`, strict schema).
- Unknown extension command kinds must be rejected deterministically and persisted as rejected-command events.

### Extensible Boundary Hooks
- Keep core hook contracts above unchanged.
- Add boundary hook dispatcher for extension subscribers at stable points:
  - `before_command_apply`
  - `after_command_apply`
  - `before_turn_dispatch`
  - `after_turn_commit`
  - `before_run_finalize`
- Extension hooks can observe/mutate within declared context contracts but cannot bypass single-writer commit path.

### Extensible Events
- Core lifecycle event names remain fixed (`agent_start`, `turn_start`, ...).
- Allow custom events with namespaced type prefix `ext_` (for example `ext_compaction_start`).
- Custom events cannot violate core ordering barriers (for example no custom event may be emitted between assistant `message_end` and mandatory tool preflight start within the same transition).

## Event Parity Matrix
Emit these lifecycle events with stable ordering.

1. `agent_start`
2. `turn_start`
3. `message_start`
4. `message_update` (assistant streaming only)
5. `message_end`
6. `tool_execution_start`
7. `tool_execution_update`
8. `tool_execution_end`
9. `turn_end`
10. `agent_end`

## Ordering Rules
- `message_end(assistant)` is a barrier before tool preflight.
- In parallel mode:
  - preflight is sequential,
  - execution is concurrent,
  - final `tool_execution_end` and `tool` message order follows assistant source order.
- `agent_end` is final loop event for a run.

## Steering and Follow-up Rules
- Steering poll points:
  - at run start,
  - after each `turn_end`.
- Follow-up poll point:
  - only when no tool calls remain and no steering messages exist.

## State Contract
Define Symfony `RunState` fields matching JS intent.
- `is_streaming`
- `streaming_message` (optional)
- `pending_tool_calls` set
- `error_message` (optional)
- `messages` transcript

## Public Runner API
Define bundle API to mirror JS operations.

```php
interface AgentRunnerInterface
{
    public function start(StartRunInput $input): RunHandle;
    public function continue(string $runId): void;
    public function steer(string $runId, AgentMessage $message): void;
    public function followUp(string $runId, AgentMessage $message): void;
    public function cancel(string $runId, ?string $reason = null): void;
    public function answerHuman(string $runId, string $questionId, mixed $answer): void;
}
```

Extension features can be layered without changing this API by publishing extension commands into the mailbox (`ext:*`) and listening to boundary hooks/events.

## Compatibility Notes
- Keep message model close to Symfony AI message types (`system|user|assistant|tool`), but include custom message support.
- Internal tool outcomes can include `is_error` and `details`; when sent back to Symfony AI they are serialized into `ToolCallMessage` string content with preserved tool call id.
- v1 does not include a `getApiKey` hook; credentials come from configured Symfony AI bridges/providers.
- All event payloads must include `run_id`, `turn_no`, `seq`.
- Core loop does not include built-in compaction behavior; compaction-style features are expected to be implemented as extension command/hook/event packages on top.

## Deliverables
- PHP interfaces for hooks.
- PHP event classes for all lifecycle events.
- DTOs for message and tool contracts.
- Contract tests that assert event order for main flows.

## Acceptance Criteria
- Contract tests cover prompt, continue, tools, steering, follow-up, cancel.
- No hidden implicit transitions in handlers.
- Hook call order documented and tested.
- Extension seam tests prove that `ext:*` commands and `ext_*` events can be added without modifying core command reducer flow.
