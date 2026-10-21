<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Command\DTO;

final readonly class CommandConsumptionDecisionDTO
{
    /** @param array<string, mixed> $metadata */
    public function __construct(public ?string $retirementReason = null, public array $metadata = [])
    {
    }
}
