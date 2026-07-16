<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;

/**
 * Fork virtual-compaction failure surfaced as a structured tool error.
 */
final class ForkCompactionSummarizationException extends ToolCallException
{
    public function __construct(
        string $error,
        private readonly ForkCompactionFailureReasonEnum $reason,
        ?string $hint = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($error, retryable: false, hint: $hint, previous: $previous);
    }

    public function reason(): ForkCompactionFailureReasonEnum
    {
        return $this->reason;
    }
}