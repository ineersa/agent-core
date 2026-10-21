<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination\DTO;

final readonly class FinalizeCommandMetadataDTO
{
    /** @param array<string, mixed> $metadata */
    public function __construct(public string $runId, public string $idempotencyKey, public array $metadata)
    {
    }
}
