<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Typed return value from a tool handler that opts into deferred completion.
 *
 * The execution worker resolves the owner-prepared ExecuteToolCall correlation
 * using {@see self::$deferredId} and returns without dispatching ToolCallResult
 * until CompleteDeferredToolCall runs. Immediate finished/background Bash
 * outcomes use ordinary {@see ToolResult} instead of an optional completion.
 */
final readonly class DeferredToolCompletionOutcome
{
    public function __construct(
        public string $deferredId,
    ) {
        if ('' === trim($this->deferredId)) {
            throw new \InvalidArgumentException('Deferred tool completion requires a non-empty deferredId.');
        }
    }
}
