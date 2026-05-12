# Stage 05 - Symfony AI Integration

## Goal
Use Symfony AI as the default provider platform while preserving agent loop control in the bundle orchestrator.

## Integration Boundaries
- Symfony AI handles provider communication and message exchange.
- Bundle orchestrator handles loop state, commands, hooks, and event ordering.
- Tool metadata and execution can reuse Symfony AI toolbox components.

## LLM Integration
Use Symfony AI `PlatformInterface` directly via a thin invoker service:
- Input: model id, `MessageBag`, tool definitions, options.
- Output: stream deltas (`TextDelta`, `ThinkingDelta`, `ToolCall*`) + final assistant message DTO.
- Supports request interception via `beforeProviderRequest` hook (model/input/options mutation before invoke).
- Uses optional `ModelResolverInterface` for per-turn provider/model selection.
- Credentials are provided by configured Symfony AI bridges/providers.
- Cancellation token is propagated to streaming invoke path so in-flight provider streams can be aborted.

## Message Conversion
Support both:
- native Symfony AI message DTOs,
- bundle `AgentMessage` DTOs (with custom roles).

Pipeline per turn:
1. `transformContext` hook.
2. `convertToLlm` hook -> `MessageBag`.
3. `ModelResolver` resolution (optional).
4. `beforeProviderRequest` hook.
5. Symfony AI request build and `PlatformInterface::invoke(..., ['stream' => true])` with cancellation signal wiring.
6. stream delta reduction and finalization.

## Cancellation Semantics
- Run cancel requests must abort active stream consumption, not only block next-turn scheduling.
- Invoker maps cancellation to normalized aborted outcome (`stop_reason=aborted`).
- Partial usage metadata is emitted when available.

## Tool Catalog Integration
Create `ToolCatalogResolver` with turn-aware dynamic descriptions.
- Resolve tool set per turn using run context.
- Keep tool name and schema stable.
- Allow description changes based on tenant/user/feature flags.

## Tool Bridge
Create `SymfonyToolExecutorAdapter`:
- Resolves tool metadata and validators from Symfony AI.
- Executes tool by name with validated args.
- Converts executed tool outcomes into Symfony `ToolCallMessage` payloads for the next LLM turn.
- Supports progress updates mapped to `tool_execution_update`.

## Hook Points
Integrate hook chain around Symfony AI operations:
- `transform_context`
- `convert_to_llm`
- `before_provider_request`
- `before_tool_call`
- `after_tool_call`
- `after_turn_commit`

Extensibility rules:
- Hook dispatcher supports both core hooks and extension boundary hooks.
- Extension hook names must be namespaced and resolved through registry.
- Unknown extension hooks are no-op (never fatal to run execution).

## Fallback and Error Mapping
- Map provider transport errors to assistant `stop_reason=error` messages.
- Preserve `aborted` vs `error` distinction.
- Emit normalized usage metadata even on partial failures when possible.

## Deliverables
- Symfony AI platform invoker service.
- Tool executor adapter.
- Tool catalog resolver with dynamic description support.
- Integration tests with fake provider.

## Acceptance Criteria
- One run executes fully through Symfony AI platform invoker.
- Hook chain is invoked in documented order.
- Dynamic tool descriptions appear in provider request payload.
- Cancel during streaming stops provider output promptly and transitions run consistently.
