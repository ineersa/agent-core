<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session;

/**
 * Immutable public projection of one canonical session event.
 *
 * Source identity is (runId, seq). turnNo is diagnostic only.
 *
 * @phpstan-type EventPayload array<string, mixed>
 */
final readonly class SessionEventDTO
{
    /**
     * @param EventPayload $payload Canonical event payload as stored
     */
    public function __construct(
        public string $runId,
        public int $seq,
        public int $turnNo,
        public string $type,
        public array $payload,
        public \DateTimeImmutable $createdAt,
    ) {
        if ('' === $this->runId) {
            throw new \InvalidArgumentException('runId must not be empty.');
        }
        if ($this->seq < 1) {
            throw new \InvalidArgumentException('seq must be >= 1.');
        }
        if ('' === $this->type) {
            throw new \InvalidArgumentException('type must not be empty.');
        }
    }
}
