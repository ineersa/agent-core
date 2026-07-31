<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Compaction;

/**
 * Public before-compaction context for extension hooks.
 *
 * Scalar / JSON-safe only. No RunState, AgentCore messages, Symfony AI types,
 * Messenger, Doctrine, or mutable prompt lists.
 *
 * Optional paired coverage watermark {@see $requiredStartSeq}..{@see $requiredEndSeq}:
 * - CompactRun: both present (MVP: 1..RunState.lastSeq captured under the run lock)
 * - Snapshot/fork in-memory compaction: both null (no canonical event range)
 */
final readonly class BeforeCompactionHookContextDTO
{
    public function __construct(
        public string $runId,
        public int $turnNo,
        public string $trigger,
        public ?int $requiredStartSeq,
        public ?int $requiredEndSeq,
        public int $tokenEstimateBefore,
        public int $messagesCompacted,
        public int $messagesRetained,
        public ?int $firstRetainedIndex,
        public bool $priorSummaryPresent,
        public ?string $customInstructions,
        public ?string $resolvedModel,
        public ?string $thinkingLevel,
    ) {
        if ('' === trim($this->runId)) {
            throw new \InvalidArgumentException('BeforeCompactionHookContextDTO.runId must be non-empty.');
        }
        if ($this->turnNo < 0) {
            throw new \InvalidArgumentException('BeforeCompactionHookContextDTO.turnNo must be >= 0.');
        }
        if ('' === trim($this->trigger)) {
            throw new \InvalidArgumentException('BeforeCompactionHookContextDTO.trigger must be non-empty.');
        }
        if ((null === $this->requiredStartSeq) !== (null === $this->requiredEndSeq)) {
            throw new \InvalidArgumentException('BeforeCompactionHookContextDTO requiredStartSeq/requiredEndSeq must both be set or both null.');
        }
        if (null !== $this->requiredStartSeq && $this->requiredStartSeq < 1) {
            throw new \InvalidArgumentException('BeforeCompactionHookContextDTO.requiredStartSeq must be >= 1 when present.');
        }
        if (null !== $this->requiredEndSeq && $this->requiredEndSeq < 0) {
            throw new \InvalidArgumentException('BeforeCompactionHookContextDTO.requiredEndSeq must be >= 0 when present.');
        }
        if ($this->tokenEstimateBefore < 0
            || $this->messagesCompacted < 0
            || $this->messagesRetained < 0) {
            throw new \InvalidArgumentException('BeforeCompactionHookContextDTO token/message counts must be >= 0.');
        }
    }

    public function hasCoverageWatermark(): bool
    {
        return null !== $this->requiredStartSeq && null !== $this->requiredEndSeq;
    }
}
