<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Event;

use Symfony\AI\Platform\Result\ToolCall;

/**
 * App-owned failure event for a registry-backed tool call.
 *
 * Symfony AI's native ToolCallFailed event carries only the internal resolved
 * parameter map, not the original provider ToolCall — insufficient to
 * reconstruct the exact flat rewritten arguments published to extension
 * result hooks. RegistryBackedToolbox dispatches this event from its catch
 * boundary while the rewritten call is still in scope, exactly once for every
 * native resolution/validation/handler failure.
 *
 * The exception is the effective original failure (resolver/validator/handler
 * throwable), not a generic wrapper.
 */
final readonly class ToolCallFailedEvent
{
    public function __construct(
        public ToolCall $toolCall,
        public \Throwable $exception,
    ) {
    }
}
