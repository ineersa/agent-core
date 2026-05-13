<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event\Lifecycle;

use Ineersa\AgentCore\Domain\Event\RunEvent;

abstract readonly class AbstractLifecycleRunEvent extends RunEvent
{
    public const string TYPE = '';

    /**
     * Initializes lifecycle run event with run ID, sequence, turn number, payload, and creation timestamp.
     *
     * @param array<string, mixed> $payload
     */
    final public function __construct(
        string $runId,
        int $seq,
        int $turnNo,
        array $payload = [],
        ?\DateTimeImmutable $createdAt = null,
    ) {
        parent::__construct(
            runId: $runId,
            seq: $seq,
            turnNo: $turnNo,
            type: static::TYPE,
            payload: $payload,
            createdAt: $createdAt ?? new \DateTimeImmutable(),
        );
    }
}
