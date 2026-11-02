<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Scheduler;

final readonly class RecurringJobDTO
{
    /** @param array<string,mixed> $payload */
    public function __construct(public string $id, public string $handlerId, public array $payload, public string $every, public \DateTimeImmutable $from, public ?\DateTimeImmutable $until = null)
    {
    }
}
