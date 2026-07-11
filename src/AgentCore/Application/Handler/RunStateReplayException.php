<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;

/**
 * Thrown when a {@see RunStateRebuilderInterface} implementation cannot rebuild a valid RunState
 * from the canonical event stream — for example duplicate sequence numbers or incompatible payload shapes.
 *
 * Sequence gaps (for example after cursor allocation without JSONL append) are tolerated and do not throw.
 */
final class RunStateReplayException extends \RuntimeException
{
    public const REASON_DUPLICATE_SEQUENCES = 'duplicate_sequences';

    public function __construct(
        string $message,
        public readonly ?string $reason = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isDuplicateSequences(): bool
    {
        return self::REASON_DUPLICATE_SEQUENCES === $this->reason;
    }
}
