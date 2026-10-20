<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

final readonly class MarkChildBackgroundedDTO
{
    public function __construct(public string $lifecycleId, public \DateTimeImmutable $acceptedAt)
    {
    }
}
