<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;

/**
 * Typed corruption signal when duplicate sequence numbers are detected in persisted run event history.
 *
 * Raised by replay rebuilders ({@see RunStateRebuilderInterface} implementations such as session replay)
 * and by rewind preflight ({@see \Ineersa\CodingAgent\Session\Rewind\SessionRewindService::rewind()})
 * before appending a LeafSet event. Use {@see RunStateDuplicateSequenceReplayException} or {@see self::isDuplicateSequences()}
 * to distinguish this case from other failures.
 *
 * Sequence gaps (for example after cursor allocation without JSONL append) are tolerated and do not throw.
 * Incompatible or corrupt JSONL payload shapes are handled separately (skipped lines, denormalization failures, or other exceptions).
 */
class RunStateReplayException extends \RuntimeException
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
