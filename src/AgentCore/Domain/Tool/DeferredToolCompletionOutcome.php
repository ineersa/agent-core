<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Typed return value from a tool handler that opts into deferred completion.
 *
 * The execution worker persists the original ExecuteToolCall correlation and
 * returns without dispatching ToolCallResult until CompleteDeferredToolCall runs.
 */
final readonly class DeferredToolCompletionOutcome
{
    public function __construct(
        public ?string $reason = null,
    ) {
    }
}
