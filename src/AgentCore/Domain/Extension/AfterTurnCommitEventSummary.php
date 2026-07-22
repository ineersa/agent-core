<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Extension;

final readonly class AfterTurnCommitEventSummary
{
    /**
     * Hot-batch summary of one committed canonical event.
     *
     * Optional turnNo/createdAt carry the RunEvent's own provenance so public
     * ExtensionApi consumers do not invent values from the surrounding context.
     * createdAt is an ISO-8601 string (not DateTimeImmutable) so serializer
     * denormalization stays free of immutable DateTime method warnings.
     *
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int $seq,
        public string $type,
        public array $payload = [],
        public ?int $turnNo = null,
        public ?string $createdAt = null,
    ) {
    }
}
