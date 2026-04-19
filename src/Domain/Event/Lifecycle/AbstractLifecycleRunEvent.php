<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event\Lifecycle;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Abstract base class for lifecycle run events within the AgentCore domain, providing a structured foundation for event data. It encapsulates core execution metadata such as run identifiers, sequence numbers, and turn counts. This class ensures consistent event structure across concrete lifecycle implementations.
 */
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
