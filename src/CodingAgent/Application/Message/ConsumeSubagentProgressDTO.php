<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message;

final readonly class ConsumeSubagentProgressDTO
{
    public function __construct(public string $lifecycleId, public int $revision, public bool $forced, public bool $discarded, public \DateTimeImmutable $consumedAt)
    {
    }
}
