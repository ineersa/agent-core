# Infrastructure\SymfonyAi

Bridge between the agent loop and Symfony AI ecosystem.

## Platform
Wraps Symfony AI's platform for `PlatformInterface` — the main entry point for LLM calls.

## SymfonyPlatformInvoker
Handles raw provider interaction — streaming, tool calls, response normalization.

## SymfonyToolExecutorAdapter
Adapts Symfony AI tool execution to `ToolExecutorInterface`.

## SymfonyMessageMapper
Bidirectional conversion between `AgentMessage`/`MessageBag` and Symfony AI message objects.

## StreamDeltaReducer
Accumulates streaming text deltas into a coherent assistant message during LLM streaming.

## RunCancellationToken
Implements `CancellationTokenInterface` — checks RunStatus from RunStore to detect cancellation.
