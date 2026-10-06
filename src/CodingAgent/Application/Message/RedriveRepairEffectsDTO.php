<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message;

/** Original authorized deliveries, captured before the repair intent is published. */
final readonly class RedriveRepairEffectsDTO
{
    /** @param list<object> $effects */
    public function __construct(public string $runId, public array $effects)
    {
    }
}
