<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

final readonly class RunAccessScope
{
    /**
     * Initializes run access scope with run ID, optional tenant/user IDs, session metadata, and creation timestamp.
     *
     * @param array<string, mixed> $sessionMetadata
     */
    public function __construct(
        public string $runId,
        public ?string $tenantId,
        public ?string $userId,
        public array $sessionMetadata = [],
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        public \DateTimeImmutable $updatedAt = new \DateTimeImmutable(),
    ) {
    }

    public function withUpdatedAt(?\DateTimeImmutable $updatedAt = null): self
    {
        return new self(
            runId: $this->runId,
            tenantId: $this->tenantId,
            userId: $this->userId,
            sessionMetadata: $this->sessionMetadata,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt ?? new \DateTimeImmutable(),
        );
    }
}
