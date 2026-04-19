<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolCall;

/**
 * Defines the contract for resolving idempotency keys for tool calls within the AgentCore system. This interface ensures that repeated executions of the same tool call can be identified and handled consistently. It serves as a boundary for decoupling idempotency logic from the core tool execution flow.
 */
interface ToolIdempotencyKeyResolverInterface
{
    /**
     * Resolves the idempotency key for a given tool call.
     */
    public function resolveToolIdempotencyKey(ToolCall $toolCall): ?string;
}
