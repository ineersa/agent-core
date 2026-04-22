<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolCall;

/**
 * Resolves idempotency keys for tool calls to ensure consistent deduplication.
 */
interface ToolIdempotencyKeyResolverInterface
{
    public function resolveToolIdempotencyKey(ToolCall $toolCall): ?string;
}
