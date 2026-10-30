<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;

/**
 * Typed return value from a tool handler that opts into deferred completion.
 *
 * The execution worker resolves the owner-prepared ExecuteToolCall correlation
 * using {@see self::$deferredId} and returns without dispatching ToolCallResult
 * until CompleteDeferredToolCall runs. An optional immediate completion lets a
 * tool publish finished output or a background acknowledgment through the same
 * deferred path without a separate lifecycle protocol.
 */
final readonly class DeferredToolCompletionOutcome
{
    public function __construct(
        public string $deferredId,
        public ?CompleteDeferredToolCall $completion = null,
    ) {
        if ('' === trim($this->deferredId)) {
            throw new \InvalidArgumentException('Deferred tool completion requires a non-empty deferredId.');
        }
        if (null !== $this->completion && $this->completion->deferredId !== $this->deferredId) {
            throw new \InvalidArgumentException('Deferred completion payload must use the same deferredId.');
        }
    }
}
