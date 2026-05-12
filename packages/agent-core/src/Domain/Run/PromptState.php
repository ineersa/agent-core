<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

final readonly class PromptState
{
    /**
     * @param list<int>                  $missingSequences
     * @param list<array<string, mixed>> $messages
     */
    public function __construct(
        public string $runId,
        public string $source,
        public int $eventCount,
        public int $lastSeq,
        public array $missingSequences,
        public bool $isContiguous,
        public int $tokenEstimate,
        public array $messages,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function withUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        return new self(
            runId: $this->runId,
            source: $this->source,
            eventCount: $this->eventCount,
            lastSeq: $this->lastSeq,
            missingSequences: $this->missingSequences,
            isContiguous: $this->isContiguous,
            tokenEstimate: $this->tokenEstimate,
            messages: $this->messages,
            updatedAt: $updatedAt,
        );
    }

    public function updatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
