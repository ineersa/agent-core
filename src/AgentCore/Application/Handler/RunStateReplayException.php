<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;

/**
 * Thrown when a {@see RunStateRebuilderInterface} implementation cannot rebuild a valid RunState
 * from the canonical event stream — for example, when the event history
 * has non-contiguous sequences or incompatible payload shapes.
 */
final class RunStateReplayException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly RunStateReplayFailureReason $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function duplicateSequences(string $message): self
    {
        return new self($message, RunStateReplayFailureReason::DuplicateSequences);
    }

    public static function missingSequences(string $message): self
    {
        return new self($message, RunStateReplayFailureReason::MissingSequences);
    }

    public function reason(): RunStateReplayFailureReason
    {
        return $this->reason;
    }
}
