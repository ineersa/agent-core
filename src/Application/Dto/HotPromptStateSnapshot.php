<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Dto;

use Ineersa\AgentCore\Domain\Run\PromptState;

final readonly class HotPromptStateSnapshot
{
    /**
     * @param list<int> $missingSequences
     */
    public function __construct(
        public string $source,
        public int $eventCount,
        public int $lastSeq,
        public int $tokenEstimate,
        public bool $isContiguous,
        public array $missingSequences,
        public int $messagesCount,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    public static function fromPromptState(?PromptState $state): ?self
    {
        if (null === $state) {
            return null;
        }

        return new self(
            source: $state->source,
            eventCount: $state->eventCount,
            lastSeq: $state->lastSeq,
            tokenEstimate: $state->tokenEstimate,
            isContiguous: $state->isContiguous,
            missingSequences: $state->missingSequences,
            messagesCount: \count($state->messages),
            updatedAt: $state->updatedAt(),
        );
    }
}
