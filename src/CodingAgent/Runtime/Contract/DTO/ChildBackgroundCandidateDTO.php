<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\DTO;

final readonly class ChildBackgroundCandidateDTO
{
    public function __construct(public string $lifecycleId, public string $toolCallId, public string $label)
    {
    }
}
