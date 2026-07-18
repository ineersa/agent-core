<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Compaction;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Result of a synchronous in-memory message-snapshot compaction.
 *
 * Does not mutate RunStore/EventStore. Callers decide how to apply
 * {@see $messages} (e.g. fork child preparation).
 *
 * Structural no-ops ({@see structuralNoOp()}) include prepare skips and
 * `ineffective_compaction` after a model summary that did not shrink the
 * estimate — both return the original messages with {@see isFailure()} false.
 */
final readonly class MessageSnapshotCompactionResult
{
    /**
     * @param list<AgentMessage> $messages Output messages (compacted or original on no-op)
     */
    private function __construct(
        public array $messages,
        public bool $compacted,
        public bool $structuralNoOp,
        public ?string $skipReason,
        public ?string $failureReason,
        public ?string $failureMessage,
    ) {
    }

    /**
     * @param list<AgentMessage> $messages
     */
    public static function compacted(array $messages): self
    {
        return new self(
            messages: $messages,
            compacted: true,
            structuralNoOp: false,
            skipReason: null,
            failureReason: null,
            failureMessage: null,
        );
    }

    /**
     * Structural prepare skip — original messages are returned unchanged.
     *
     * @param list<AgentMessage> $messages
     */
    public static function structuralNoOp(array $messages, string $skipReason): self
    {
        return new self(
            messages: $messages,
            compacted: false,
            structuralNoOp: true,
            skipReason: $skipReason,
            failureReason: null,
            failureMessage: null,
        );
    }

    public static function failed(string $reason, string $message): self
    {
        return new self(
            messages: [],
            compacted: false,
            structuralNoOp: false,
            skipReason: null,
            failureReason: $reason,
            failureMessage: $message,
        );
    }

    public function isFailure(): bool
    {
        return null !== $this->failureReason;
    }
}
