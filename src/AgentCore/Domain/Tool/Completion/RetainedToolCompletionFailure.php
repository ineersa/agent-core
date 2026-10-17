<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

/** Bounded typed inspection failure for retained completion evidence. */
final class RetainedToolCompletionFailure extends \RuntimeException
{
    public function __construct(
        private readonly RetainedToolCompletionFailureCodeEnum $failureCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function code(): RetainedToolCompletionFailureCodeEnum
    {
        return $this->failureCode;
    }
}
